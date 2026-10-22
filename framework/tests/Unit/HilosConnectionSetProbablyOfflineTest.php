<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Hilos;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\HilosConnections as StateHilosConnections;
use Hilos\Runtime\State\Item\HilosConnection as StateHilosConnection;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\HilosConnectionsActions;
use Hilos\Runtime\View\Actions\Item\HilosConnectionActions;
use Hilos\Runtime\View\Collection\HilosConnections;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\HilosConnection;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The doubt flag of a connection is written by its owner alone, and only a change travels (HIL-1566).
 *
 * The cases drive the one write that raises and lowers the flag and read what it leaves: the row's
 * value, and the RT sync frames queued for the other processes. A report repeating what the row
 * already says is not a change and must cost no frame; a writer other than the owner is refused
 * before the value is even compared.
 */
final class HilosConnectionSetProbablyOfflineTest extends TestCase
{
    private const string AGENT_ID = 'set_probably_offline_test_agent';
    private const string STRANGER_ID = 'set_probably_offline_test_stranger';
    private const string ACCEPT_KEY = 'ak-quiet';

    protected function tearDown(): void
    {
        Hilos::$rt = null;
        Hilos::$sr = null;
        ExecutionContext::setCurrentAgentId(null);
        RtTruthSourceRegistry::unregisterAgent(self::AGENT_ID);

        parent::tearDown();
    }

    public function testRaisingTheDoubtWritesTheFlagAndSyncsItOnce(): void
    {
        $connections = $this->arrangeConnection(false);

        $connections[self::ACCEPT_KEY]->actions->setProbablyOffline(true);

        $this->assertTrue($connections[self::ACCEPT_KEY]->probablyOffline);
        $this->assertSame([[StateHilosConnection::probablyOffline => true]], $this->queuedUpdateDiffs());
    }

    public function testLoweringTheDoubtWritesTheFlagAndSyncsItOnce(): void
    {
        $connections = $this->arrangeConnection(true);

        $connections[self::ACCEPT_KEY]->actions->setProbablyOffline(false);

        $this->assertFalse($connections[self::ACCEPT_KEY]->probablyOffline);
        $this->assertSame([[StateHilosConnection::probablyOffline => false]], $this->queuedUpdateDiffs());
    }

    /**
     * @return array<string, array{bool}> Value the row already holds and the write repeats
     */
    public static function unchangedValues(): array
    {
        return [
            'not doubted again' => [false],
            'doubted again' => [true],
        ];
    }

    #[DataProvider('unchangedValues')]
    public function testTheSameValueAgainSendsNothing(bool $value): void
    {
        $connections = $this->arrangeConnection($value);

        $connections[self::ACCEPT_KEY]->actions->setProbablyOffline($value);

        $this->assertSame($value, $connections[self::ACCEPT_KEY]->probablyOffline);
        $this->assertSame([], $this->queuedUpdateDiffs());
    }

    /**
     * @return array<string, array{bool, bool}> Value the row holds and the value the stranger writes
     */
    public static function strangerWrites(): array
    {
        return [
            'a change' => [false, true],
            'the same value' => [true, true],
        ];
    }

    #[DataProvider('strangerWrites')]
    public function testAWriterOtherThanTheOwnerIsRefusedAndChangesNothing(bool $held, bool $written): void
    {
        $connections = $this->arrangeConnection($held);
        ExecutionContext::setCurrentAgentId(self::STRANGER_ID);

        try {
            $connections[self::ACCEPT_KEY]->actions->setProbablyOffline($written);
            $this->fail('a writer that is not the truth source must be refused');
        } catch (RtTruthSourceWriteNotAllowedException) {
            // The refusal is the point; what follows checks it left the row alone.
        }

        $this->assertSame($held, $connections[self::ACCEPT_KEY]->probablyOffline);
        $this->assertSame([], $this->queuedUpdateDiffs());
    }

    public function testTheWriteLeavesTheOtherFieldsOfTheRowAlone(): void
    {
        $connections = $this->arrangeConnection(false);
        $before = $connections[self::ACCEPT_KEY]->toArray();

        $connections[self::ACCEPT_KEY]->actions->setProbablyOffline(true);

        $this->assertSame(
            array_replace($before, [StateHilosConnection::probablyOffline => true]),
            $connections[self::ACCEPT_KEY]->toArray(),
        );
    }

    /**
     * Mounts one connection of a signed-in person, owned by this test's agent, holding the given doubt.
     *
     * @param bool $probablyOffline Doubt the row holds before the case runs
     * @return ProbablyOfflineConnections Collection the connection lives in
     */
    private function arrangeConnection(bool $probablyOffline): ProbablyOfflineConnections
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new ProbablyOfflineRtContext();
        Hilos::$rt->configure();
        ExecutionContext::setCurrentAgentId(self::AGENT_ID);
        RtTruthSourceRegistry::register(ProbablyOfflineRtContext::connections, TruthSourceKeys::all(), self::AGENT_ID);

        $connections = Hilos::$rt->connectionsRegistry();
        $this->assertInstanceOf(ProbablyOfflineConnections::class, $connections);
        $connections->actions->register(self::ACCEPT_KEY, 7);
        $connections[self::ACCEPT_KEY]->actions->setProbablyOffline($probablyOffline);
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
            // The row's own creation and the arranged doubt are not what these cases are about.
        }

        return $connections;
    }

    /**
     * Drains the queue and keeps the diffs of the RT updates it carried.
     *
     * @return list<array<string, mixed>> Diff of each queued RT update, in order
     */
    private function queuedUpdateDiffs(): array
    {
        $diffs = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if (
                $signal->signalSource->getSource() !== SignalSource::RT
                || $signal->signalType->getType() !== SignalConstants::RT_SYNC_UPDATED
            ) {
                continue;
            }
            $this->assertInstanceOf(RtSyncUpdatedSignalData::class, $signal->data);
            $diffs[] = $signal->data->row;
        }

        return $diffs;
    }
}

/**
 * Presence-stage row with nothing of its own, syncing under the collection the context mounts it as.
 */
final class ProbablyOfflineConnection extends StateHilosConnection
{
    public static function getRtCollectionKey(): string
    {
        return ProbablyOfflineRtContext::connections;
    }

    protected function initOwn(): void
    {
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row (nothing of its own to read)
     */
    protected function hydrateOwn(array $row): void
    {
    }

    /**
     * @return array<string, mixed> Always empty: the row is the framework base
     */
    protected function ownToArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $diff Partial update (nothing of its own to apply)
     */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/**
 * @extends StateHilosConnections<ProbablyOfflineConnection>
 */
final class ProbablyOfflineStates extends StateHilosConnections
{
    public const string STATE_CLASS = ProbablyOfflineConnection::class;
}

/**
 * @extends HilosConnection<ProbablyOfflineConnection>
 */
final class ProbablyOfflineItem extends HilosConnection
{
}

/**
 * @extends HilosConnectionActions<ProbablyOfflineItem>
 */
final class ProbablyOfflineItemActions extends HilosConnectionActions
{
}

/**
 * @extends HilosConnectionsActions<ProbablyOfflineItem, ProbablyOfflineConnections>
 */
final class ProbablyOfflineCollectionActions extends HilosConnectionsActions
{
}

/**
 * @extends HilosConnections<ProbablyOfflineItem, ProbablyOfflineCollectionActions>
 */
final class ProbablyOfflineConnections extends HilosConnections
{
    /**
     * @param RtState $state Backing state row
     * @return ProbablyOfflineItem View item over the row
     */
    protected function createRtItem(RtState $state): ProbablyOfflineItem
    {
        /** @var ProbablyOfflineConnection $state */
        return new ProbablyOfflineItem($state);
    }
}

/**
 * Runtime context of a project that mounts and represents its connections.
 */
final class ProbablyOfflineRtContext extends RtContext
{
    public const string connections = 'probablyOfflineTestConnections';

    /**
     * Mounts the connections collection and gives it the write API the doubt goes through.
     */
    public function configure(): void
    {
        $this->_stateCollections[self::connections] = ProbablyOfflineStates::init();
        $this->setRepresent(
            self::connections,
            ProbablyOfflineConnections::class,
            ProbablyOfflineCollectionActions::class,
            ProbablyOfflineItemActions::class,
        );
    }
}
