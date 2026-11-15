<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\ProtectedModeRuntime as ViewProtectedModeRuntime;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * A process does not apply its own RT echo, however many of its own frames of a row are in
 * flight (HIL-2104).
 *
 * Whose frame it is comes from the emitter stamp the frame carries, so the verdict holds no
 * state: an own echo is not spent by an earlier one of the same row, not pushed out by frames of
 * other rows, and a foreign frame of a row this process is writing is applied rather than taken
 * for an echo. The lift chain that once left the master in `deactivating` for good (HIL-2085) is
 * one of these cases; the rows of an ordinary collection are the rest. The frames are queued on a
 * real router outside a transaction and applied on the master path, with the check on.
 */
final class RtSyncRowSelfEchoEmitterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Hilos::$rt = new FrameworkRuntimeSingletonTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$rt = null;

        parent::tearDown();
    }

    public function testALateEchoDoesNotTakeALiftedRowBack(): void
    {
        $this->arrangeStandingFreeze();
        $this->writeDiagnostics(3);
        $this->lift();
        $diagnosticsEcho = $this->nextQueuedFrame();
        $deactivatingEcho = $this->nextQueuedFrame();
        $inactiveEcho = $this->nextQueuedFrame();

        $logged = $this->logOf(function () use ($diagnosticsEcho, $deactivatingEcho, $inactiveEcho): void {
            RtSyncApplicator::applyUpdated($diagnosticsEcho);
            RtSyncApplicator::applyUpdated($deactivatingEcho);
            $this->writeDiagnostics(0);
            RtSyncApplicator::applyUpdated($inactiveEcho);
            RtSyncApplicator::applyUpdated($this->nextQueuedFrame());
        });

        $this->assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, $this->row()->phase);
        $this->assertNull($this->row()->operation);
        $this->assertSame(0, $this->row()->stoppedAgentCount);
        $this->assertSame('', $logged);
    }

    public function testAnOrdinaryLiftEndsInactiveAndSaysNothing(): void
    {
        $this->arrangeStandingFreeze();
        $this->lift();
        $deactivatingEcho = $this->nextQueuedFrame();
        $inactiveEcho = $this->nextQueuedFrame();

        $logged = $this->logOf(static function () use ($deactivatingEcho, $inactiveEcho): void {
            RtSyncApplicator::applyUpdated($deactivatingEcho);
            RtSyncApplicator::applyUpdated($inactiveEcho);
        });

        $this->assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, $this->row()->phase);
        $this->assertNull($this->row()->operation);
        $this->assertSame('', $logged);
    }

    public function testOwnEchoesOfAnUpdateADeleteAndACreateLeaveTheLastWriteStanding(): void
    {
        $collection = $this->arrangeCollection();
        $this->arrangeRow('r1', 'third');
        Hilos::$sr = new SignalRouter();
        $updateEcho = $this->sendOwn(
            SignalConstants::RT_SYNC_UPDATED,
            new RtSyncUpdatedSignalData(
                RowSelfEchoTestRtContext::ROWS,
                'r1',
                [RowSelfEchoTestState::name => 'second'],
                previous: [RowSelfEchoTestState::name => 'first'],
            ),
        );
        $deleteEcho = $this->sendOwn(
            SignalConstants::RT_SYNC_DELETED,
            new RtSyncDeletedSignalData(
                RowSelfEchoTestRtContext::ROWS,
                'r1',
                [RowSelfEchoTestState::id => 'r1', RowSelfEchoTestState::name => 'second'],
            ),
        );
        $createEcho = $this->sendOwn(
            SignalConstants::RT_SYNC_CREATED,
            new RtSyncCreatedSignalData(
                RowSelfEchoTestRtContext::ROWS,
                'r1',
                [RowSelfEchoTestState::id => 'r1', RowSelfEchoTestState::name => 'third'],
            ),
        );

        RtSyncApplicator::applyUpdated($updateEcho);
        $this->assertRowNamed($collection, 'r1', 'third');
        RtSyncApplicator::applyDeleted($deleteEcho);
        $this->assertRowNamed($collection, 'r1', 'third');
        RtSyncApplicator::applyCreated($createEcho);
        $this->assertRowNamed($collection, 'r1', 'third');
    }

    public function testAForeignFrameIsAppliedWhileOwnFramesOfTheRowAreInFlight(): void
    {
        $collection = $this->arrangeCollection();
        $this->arrangeRow('r1', 'own-second');
        Hilos::$sr = new SignalRouter();
        $firstEcho = $this->sendOwn(
            SignalConstants::RT_SYNC_UPDATED,
            new RtSyncUpdatedSignalData(
                RowSelfEchoTestRtContext::ROWS,
                'r1',
                [RowSelfEchoTestState::name => 'own-first'],
                previous: [RowSelfEchoTestState::name => 'seeded'],
            ),
        );
        $secondEcho = $this->sendOwn(
            SignalConstants::RT_SYNC_UPDATED,
            new RtSyncUpdatedSignalData(
                RowSelfEchoTestRtContext::ROWS,
                'r1',
                [RowSelfEchoTestState::name => 'own-second'],
                previous: [RowSelfEchoTestState::name => 'own-first'],
            ),
        );

        RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(
            RowSelfEchoTestRtContext::ROWS,
            'r1',
            [RowSelfEchoTestState::name => 'foreign'],
            previous: [RowSelfEchoTestState::name => 'own-second'],
            emitter: 'another-process',
        ));
        $this->assertRowNamed($collection, 'r1', 'foreign');
        RtSyncApplicator::applyUpdated($firstEcho);
        RtSyncApplicator::applyUpdated($secondEcho);
        $this->assertRowNamed($collection, 'r1', 'foreign');
    }

    public function testAnUnstampedFrameOfARowThisProcessNeverWroteIsApplied(): void
    {
        $collection = $this->arrangeCollection();
        $this->arrangeRow('r1', 'seeded');
        Hilos::$sr = new SignalRouter();

        RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(
            RowSelfEchoTestRtContext::ROWS,
            'r1',
            [RowSelfEchoTestState::name => 'unstamped'],
            previous: [RowSelfEchoTestState::name => 'seeded'],
        ));

        $this->assertRowNamed($collection, 'r1', 'unstamped');
    }

    public function testAnOwnEchoIsRecognizedBehindTenThousandOwnFramesOfOtherRows(): void
    {
        $collection = $this->arrangeCollection();
        $this->arrangeRow('r0', 'later');
        Hilos::$sr = new SignalRouter();
        $firstEcho = $this->sendOwn(
            SignalConstants::RT_SYNC_UPDATED,
            new RtSyncUpdatedSignalData(
                RowSelfEchoTestRtContext::ROWS,
                'r0',
                [RowSelfEchoTestState::name => 'earlier'],
                previous: [RowSelfEchoTestState::name => 'seeded'],
            ),
        );
        // 10 001 own frames in flight, the first one included.
        for ($index = 1; $index < 10001; $index++) {
            Hilos::$sr->queueRtSyncSignal(
                SignalConstants::RT_SYNC_UPDATED,
                new RtSyncUpdatedSignalData(
                    RowSelfEchoTestRtContext::ROWS,
                    "r{$index}",
                    [RowSelfEchoTestState::name => 'elsewhere'],
                ),
            );
        }

        RtSyncApplicator::applyUpdated($firstEcho);

        $this->assertRowNamed($collection, 'r0', 'later');
    }

    /**
     * Puts the row under a standing freeze before any router is installed, so the arrangement
     * leaves no frame behind for the case to trip over.
     */
    private function arrangeStandingFreeze(): void
    {
        $this->asTheMaster(function (): void {
            $this->row()->actions->enterActivating(new ProtectedModeQuiesceData('restore', 'backup', 0, null, null), null);
            $this->row()->actions->enterActive();
        });
        Hilos::$sr = new SignalRouter();
    }

    /**
     * Writes both phases of a lift in one call, the way the executor does.
     */
    private function lift(): void
    {
        $this->asTheMaster(function (): void {
            $this->row()->actions->enterDeactivating();
            $this->row()->actions->enterInactive();
        });
    }

    /**
     * Queues a diagnostics frame of the row; a phase never travels in one.
     *
     * @param int $stoppedAgentCount Count the frame carries, different from the last one written
     */
    private function writeDiagnostics(int $stoppedAgentCount): void
    {
        $this->asTheMaster(function () use ($stoppedAgentCount): void {
            $this->row()->actions->updateAgentDiagnostics('node-a', $stoppedAgentCount, [], true);
        });
    }

    /**
     * Runs a write with the daemon registered as the row's truth source, and drops it after.
     *
     * @param callable(): void $write Write the row accepts from its truth source only
     */
    private function asTheMaster(callable $write): void
    {
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        try {
            $write();
        } finally {
            RtTruthSourceRegistry::unregisterDaemon(StateProtectedModeRuntime::RT_ITEM);
        }
    }

    /**
     * @return RtSyncUpdatedSignalData The frame next in line on the router
     */
    private function nextQueuedFrame(): RtSyncUpdatedSignalData
    {
        $frame = Hilos::$sr?->getNextQueuedSignal()?->data;
        $this->assertInstanceOf(RtSyncUpdatedSignalData::class, $frame);

        return $frame;
    }

    /**
     * Sends a frame the way a write of this process does, and takes it back off the router as
     * the echo that will return to this process.
     *
     * @template T of RtSyncSignalDataInterface
     * @param string $signalName RT sync signal the write announces
     * @param T $frame Fact the write produced
     * @return T The frame as the router queued it
     */
    private function sendOwn(string $signalName, RtSyncSignalDataInterface $frame): RtSyncSignalDataInterface
    {
        Hilos::$sr?->queueRtSyncSignal($signalName, $frame);
        $queued = Hilos::$sr?->getNextQueuedSignal()?->data;
        $this->assertInstanceOf($frame::class, $queued);

        return $queued;
    }

    /**
     * @return ViewProtectedModeRuntime The mounted protected mode row
     */
    private function row(): ViewProtectedModeRuntime
    {
        $row = Hilos::$rt?->hilosProtectedModeRuntime;
        $this->assertInstanceOf(ViewProtectedModeRuntime::class, $row);

        return $row;
    }

    /**
     * Mounts the one runtime collection the row cases sync into.
     *
     * @return RtStates Empty collection the applicator writes to
     */
    private function arrangeCollection(): RtStates
    {
        Hilos::$rt = new RowSelfEchoTestRtContext();
        Hilos::$rt->configure();

        $collection = Hilos::$rt->getStateCollection(RowSelfEchoTestRtContext::ROWS);
        $this->assertInstanceOf(RtStates::class, $collection);

        return $collection;
    }

    /**
     * Seeds the row with the values of this process's last write, before any router is
     * installed, along the road a row really arrives by: a well-formed create.
     *
     * @param string $id Row key to seed
     * @param string $name Name the last write left on the row
     */
    private function arrangeRow(string $id, string $name): void
    {
        RtSyncApplicator::applyCreated(new RtSyncCreatedSignalData(
            RowSelfEchoTestRtContext::ROWS,
            $id,
            [RowSelfEchoTestState::id => $id, RowSelfEchoTestState::name => $name],
        ));
    }

    /**
     * @param RtStates $collection Collection the row lives in
     * @param string $id Row key
     * @param string $name Name the row must read
     */
    private function assertRowNamed(RtStates $collection, string $id, string $name): void
    {
        $state = $collection->get($id);
        $this->assertInstanceOf(RowSelfEchoTestState::class, $state, "Row {$id} is still in the collection");
        $this->assertSame($name, $state->name);
    }

    /**
     * @param callable(): void $applying Steps whose log lines are wanted
     * @return string Everything the steps logged
     */
    private function logOf(callable $applying): string
    {
        ob_start();
        try {
            $applying();
        } finally {
            $logged = (string)ob_get_clean();
        }

        return $logged;
    }
}

/**
 * Runtime context holding the one collection the row cases sync into.
 */
final class RowSelfEchoTestRtContext extends RtContext
{
    public const string ROWS = 'rowSelfEchoTestRows';

    /**
     * Registers the single test collection.
     */
    public function configure(): void
    {
        $this->_stateCollections[self::ROWS] = RowSelfEchoTestStates::init();
    }
}

final class RowSelfEchoTestStates extends RtStates
{
    public const string STATE_CLASS = RowSelfEchoTestState::class;
}

/**
 * Row whose one field tells which write it holds.
 */
final class RowSelfEchoTestState extends RtState
{
    public const string id = 'id';
    public const string name = 'name';

    private(set) string $id = '';

    public string $name = '';

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Hydrated row
     * @throws InvalidFormatException When the row is missing a field it is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->id = self::requireString($row, self::id);
        $instance->name = self::requireString($row, self::name);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $diff Partial update
     * @throws InvalidFormatException When a field the diff does carry holds the wrong type
     */
    public function applyDiff(array $diff): void
    {
        if (array_key_exists(self::name, $diff)) {
            $this->name = self::requireString($diff, self::name);
        }
    }

    /**
     * @return string Row key
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::name => $this->name,
        ];
    }
}
