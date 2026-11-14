<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Generator;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Hilos;
use Hilos\Runtime\ConnectionRosterReconciler;
use Hilos\Runtime\Ordinary\RtOrdinaryAdmission;
use Hilos\Runtime\Ordinary\RtOrdinaryQueue;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Collection\HilosConnections as StateHilosConnections;
use Hilos\Runtime\State\Item\HilosConnection as StateHilosConnection;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\HilosConnectionsActions;
use Hilos\Runtime\View\Actions\Item\HilosConnectionActions;
use Hilos\Runtime\View\Collection\HilosConnections;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\HilosConnection;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * What a starting agent does to the connection rows left behind while it was down (HIL-664).
 *
 * The rows outlive the agent on purpose now - the list of live connections is the truth of the
 * node holding the sockets, not of the agent - so the moment the agent comes back it has to say
 * which of them still have a socket. The roster the master hands it is that sentence, and these
 * cases pin what it costs: a row named on it stays, a row missing from it goes, and the two
 * silences (nothing mounted, somebody else's collection) answer zero rather than refusing, and
 * so does the third one a second owner brings (HIL-771): an agent allowed to edit a row but not
 * to take it away is not the agent this reconcile is for.
 */
final class ConnectionRosterReconcilerTest extends TestCase
{
    private const string AGENT_ID = 'roster_test_agent';
    private const string OTHER_AGENT_ID = 'roster_test_other_agent';

    protected function tearDown(): void
    {
        Hilos::$rt = null;
        ExecutionContext::setCurrentAgentId(null);
        RtTruthSourceRegistry::unregisterAgent(self::AGENT_ID);
        RtTruthSourceRegistry::unregisterAgent(self::OTHER_AGENT_ID);
        RtOrdinaryAdmission::reset();

        parent::tearDown();
    }

    public function testARowWithNoLiveSocketIsStruckAndALiveOneStays(): void
    {
        $connections = $this->arrangeOwnedCollection();

        $struck = ConnectionRosterReconciler::reconcile(['ak-live']);

        $this->assertSame(1, $struck);
        $this->assertTrue(isset($connections['ak-live']));
        $this->assertFalse(isset($connections['ak-gone']));
    }

    public function testAnEmptyRosterStrikesEveryRow(): void
    {
        $connections = $this->arrangeOwnedCollection();

        $struck = ConnectionRosterReconciler::reconcile([]);

        $this->assertSame(2, $struck);
        $this->assertCount(0, $connections);
    }

    public function testARosterNamingKeysTheCollectionNeverHadStrikesNothingExtra(): void
    {
        $connections = $this->arrangeOwnedCollection();

        $struck = ConnectionRosterReconciler::reconcile(['ak-live', 'ak-gone', 'ak-never-seen']);

        $this->assertSame(0, $struck);
        $this->assertCount(2, $connections);
    }

    public function testAProjectWithNoConnectionsMountedAnswersZero(): void
    {
        Hilos::$rt = new RosterEmptyRtContext();
        Hilos::$rt->configure();
        ExecutionContext::setCurrentAgentId(self::AGENT_ID);

        $this->assertSame(0, ConnectionRosterReconciler::reconcile([]));
    }

    public function testAnAgentThatDoesNotOwnTheCollectionAnswersZero(): void
    {
        $connections = $this->arrangeOwnedCollection();
        ExecutionContext::setCurrentAgentId(self::OTHER_AGENT_ID);

        $struck = ConnectionRosterReconciler::reconcile([]);

        $this->assertSame(0, $struck);
        $this->assertCount(2, $connections);
    }

    public function testACoOwnerThatMayOnlyEditARowAnswersZero(): void
    {
        $connections = $this->arrangeOwnedCollection();
        RtTruthSourceRegistry::register(
            RosterRtContext::connections,
            TruthSourceKeys::all(),
            self::OTHER_AGENT_ID,
            TruthSourceOperations::of(TruthSourceOperation::Update),
        );
        ExecutionContext::setCurrentAgentId(self::OTHER_AGENT_ID);

        $struck = ConnectionRosterReconciler::reconcile([]);

        $this->assertSame(0, $struck);
        $this->assertCount(2, $connections);
    }

    public function testAStrikePastTheServiceReserveIsOwedAndMadeWhenTheFifoFrees(): void
    {
        $connections = $this->arrangeOwnedCollection();
        RtOrdinaryAdmission::bindSourceQueue(new RtOrdinaryQueue(RtRowArena::process(), maxCleanupOperations: 1));
        // An unsettled head holds every committed cell, so the one service cell stays taken.
        $head = RtOrdinaryAdmission::reserve(1);
        $head->seal([[]]);

        $struck = ConnectionRosterReconciler::reconcile([]);

        $this->assertSame(2, $struck);
        $this->assertCount(1, $connections, 'The strike past the service reserve is owed, not refused');
        $this->assertSame(1, RtOrdinaryAdmission::owedRemovals());
        unset($head);
        $connections->actions->register('ak-new', 3);
        $this->assertFalse(isset($connections['ak-gone']), 'The owed strike is made once the FIFO frees');
        $this->assertTrue(isset($connections['ak-new']));
        $this->assertSame(0, RtOrdinaryAdmission::owedRemovals());
    }

    /**
     * Mounts two connection rows under an agent that owns the collection, one of them live.
     *
     * @return RosterConnections Represented collection the cases read the outcome from
     */
    private function arrangeOwnedCollection(): RosterConnections
    {
        Hilos::$rt = new RosterRtContext();
        Hilos::$rt->configure();
        ExecutionContext::setCurrentAgentId(self::AGENT_ID);
        RtTruthSourceRegistry::register(RosterRtContext::connections, TruthSourceKeys::all(), self::AGENT_ID);

        $registry = Hilos::$rt->connectionsRegistry();
        $this->assertInstanceOf(RosterConnections::class, $registry);
        $registry->actions->register('ak-live', 1);
        $registry->actions->register('ak-gone', 2);

        return $registry;
    }
}

/**
 * Presence-stage row with nothing of its own, as the two simple demos have.
 */
final class RosterConnection extends StateHilosConnection
{
    /**
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function initOwn(): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowReader $row Serialized runtime row (nothing of its own to read)
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function hydrateOwn(RtRowReader $row): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowWriter $out Segmented destination after both base stages
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function exportOwn(RtRowWriter $out): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowReader $diff Partial update (nothing of its own to apply)
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function applyOwnDiff(RtRowReader $diff): Generator
    {
        yield new RtWork();
    }
}

/**
 * @extends StateHilosConnections<RosterConnection>
 */
final class RosterStates extends StateHilosConnections
{
    public const string STATE_CLASS = RosterConnection::class;
}

/**
 * @extends HilosConnection<RosterConnection>
 */
final class RosterItem extends HilosConnection
{
}

/**
 * @extends HilosConnectionActions<RosterItem>
 */
final class RosterItemActions extends HilosConnectionActions
{
}

/**
 * @extends HilosConnectionsActions<RosterItem, RosterConnections>
 */
final class RosterCollectionActions extends HilosConnectionsActions
{
}

/**
 * @extends HilosConnections<RosterItem, RosterCollectionActions>
 */
final class RosterConnections extends HilosConnections
{
    /**
     * @param RtState $state Backing state row
     * @return RosterItem View item over the row
     */
    protected function createRtItem(RtState $state): RosterItem
    {
        /** @var RosterConnection $state */
        return new RosterItem($state);
    }
}

/**
 * Runtime context of a project that mounts and represents its connections.
 */
final class RosterRtContext extends RtContext
{
    public const string connections = 'rosterTestConnections';

    /**
     * Mounts the connections collection and gives it the write API the reconcile goes through.
     */
    public function configure(): void
    {
        $this->_stateCollections[self::connections] = RosterStates::init();
        $this->setRepresent(
            self::connections,
            RosterConnections::class,
            RosterCollectionActions::class,
            RosterItemActions::class,
        );
    }
}

/**
 * Runtime context of a project that keeps no connections at all.
 */
final class RosterEmptyRtContext extends RtContext
{
    /**
     * Mounts nothing.
     */
    public function configure(): void
    {
    }
}
