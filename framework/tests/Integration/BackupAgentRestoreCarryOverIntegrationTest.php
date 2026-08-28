<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Auth\Session\DeferredSessionCarryoverQueue;
use Hilos\Auth\Session\SessionCarrier;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\BackupRunKind;
use Hilos\Backup\BackupNotificationType;
use Hilos\Backup\BackupScope;
use Hilos\Backup\RestoreEnvDecision;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Database;
use Hilos\Database\DTO\DbReHydrateOutcome;
use Hilos\Environment\EnvAccessor;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Notification\DeferredNotificationQueue;
use Hilos\Notification\NotificationDraft;
use Hilos\Runtime\State\Collection\BackupHistories;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\BackupRuntime as StateBackupRuntime;
use Hilos\Runtime\State\Item\BackupHistory as StateBackupHistory;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\RestoreRuntime as StateRestoreRuntime;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Actions\Collection\BackupHistoriesActions;
use Hilos\Runtime\View\Actions\Collection\HilosSessionConnectionsActions;
use Hilos\Runtime\View\Actions\Item\HilosConnectionActions;
use Hilos\Runtime\View\Collection\BackupHistories as ViewBackupHistories;
use Hilos\Runtime\View\Collection\HilosSessionConnections as ViewHilosSessionConnections;
use Hilos\Runtime\View\Item\HilosSessionConnection as ViewHilosSessionConnection;
use Hilos\Runtime\View\Item\RestoreRuntime;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use RuntimeException;

/**
 * Where the supervisor photographs live sessions, and where it puts them back (HIL-479, HIL-436).
 *
 * {@see SessionCarrierIntegrationTest} pins the carry-over itself - which session lands on which
 * account across a swapped database. What is left uncovered is the supervisor's ORDER, and order
 * is the whole mechanism: a snapshot taken one step later would photograph a database that was
 * already being overwritten, and rows written one step earlier would land in a node that has not
 * finished re-reading the database they are written into.
 *
 * The three properties here have no other door: they need a live database on one side and a
 * restore's lifecycle on the other. The child is the one thing not real - the test replaces the
 * database itself, exactly as {@see SessionCarrierIntegrationTest} does, because what the child
 * does to the data is not what is under test here; when it did it is.
 */
final class BackupAgentRestoreCarryOverIntegrationTest extends HilosSessionIntegrationTestCase
{
    private const string BACKUP_ID = '2026-08-15_10-30-00';

    private const string TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    /** A second live session, whose socket is the one that dies while its agent is stopped. */
    private const string OTHER_TOKEN = '0f9e8d7c6b5a493827160f5e4d3c2b1a';

    private const string CREATED_AT = '2026-08-01 09:15:00';

    /** Well past any run of this suite: these cases are about a live session, not about expiry. */
    private const string EXPIRES_AT = '2036-09-01 09:15:00';

    /** The initiator before the swap; the announcement must not be sent to this id afterwards. */
    public const int OLD_USER_ID = 41;

    private const int NEW_USER_ID = 77;

    private const string EMAIL_TYPE = 'password';

    private const string EMAIL = 'ann@example.test';

    /** @var ?RtContext Runtime context to restore after the test */
    private ?RtContext $previousRt = null;

    /** @var string Directory the two restore queues of this case live in */
    private string $backupDir = '';

    /**
     * @throws HilosException When the queue directory or the context build fails
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousRt = Hilos::$rt;
        $rt = new CarryOverTestRtContext();
        $rt->configure();
        // What the facade does for a live process right after configure(): the framework reads
        // the project's connections wherever they are mounted, and that interest is what keeps
        // the rows here when the agent that owns them leaves (HIL-664, HIL-717).
        $rt->declareProcessWideReads();
        // The index is mounted empty on purpose: the archive these cases restore was never
        // scanned into it, so the supervisor finds no row to write this restore's duration onto
        // and says so - which is the quiet half of the same path a real installation takes.
        $rt->mountFeatureCollection(StateBackupHistory::RT_COLLECTION, BackupHistories::init());
        $rt->setRepresent(
            StateBackupHistory::RT_COLLECTION,
            ViewBackupHistories::class,
            BackupHistoriesActions::class,
        );
        $rt->mountFeatureItem(StateRestoreRuntime::RT_ITEM, StateRestoreRuntime::create());
        $rt->mountFeatureItem(StateBackupRuntime::RT_ITEM, StateBackupRuntime::create());
        Hilos::$rt = $rt;
        RtTruthSourceRegistry::registerDaemon(StateRestoreRuntime::RT_ITEM);
        RtTruthSourceRegistry::registerDaemon(StateBackupRuntime::RT_ITEM);

        Hilos::$sr = new SignalRouter();
        $this->backupDir = sys_get_temp_dir() . '/hilos-carry-over-it-' . getmypid();
        FsPath::ensureDirectory($this->backupDir);
        Hilos::$env = $this->env();

        // Both queues are emptied before the case rather than only after it: one left behind by a
        // run that died mid-case would hand its logins and its letters to this one.
        DeferredSessionCarryoverQueue::drain();
        DeferredNotificationQueue::drain();
    }

    /**
     * @throws HilosException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        DeferredSessionCarryoverQueue::drain();
        DeferredNotificationQueue::drain();
        rmdir($this->backupDir);
        RtTruthSourceRegistry::unregisterDaemon(StateBackupRuntime::RT_ITEM);
        RtTruthSourceRegistry::unregisterDaemon(StateRestoreRuntime::RT_ITEM);
        RtTruthSourceRegistry::unregisterAgent(CarryOverTestOwnerAgent::AGENT_TYPE);
        ExecutionContext::setCurrentAgentId(null);
        Hilos::$env = null;
        Hilos::$sr = null;
        Hilos::$rt = $this->previousRt;

        parent::tearDown();
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testTheSessionsArePhotographedBeforeTheChildIsLetNearTheDatabase(): void
    {
        $this->seedLiveSession();
        $agent = $this->admittedRestore();

        $agent->onProtectedModeReady();

        $this->assertCount(
            1,
            $this->snapshotOf($agent),
            'A snapshot taken any later would photograph a database already being overwritten',
        );
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testTheSessionsAreHandedOverOnlyAfterTheBarrierHasClosed(): void
    {
        $this->seedLiveSession();
        $agent = $this->admittedRestore();
        $agent->onProtectedModeReady();
        $this->swapDatabase();

        $this->endRestoreChild($agent, ExitCode::SUCCESS);
        $this->assertSame(
            [],
            DeferredSessionCarryoverQueue::drain(),
            'Handed over here, the logins would be applied in a node still holding caches of the database that is gone',
        );

        $agent->onDbReHydrateComplete(new DbReHydrateOutcome(true, []));
        $this->assertNull(
            self::sessionRow(self::TOKEN),
            'The supervisor stopped writing this table: the rows belong to the sessions library now (HIL-771)',
        );

        // What the library does on its way back up, and the whole of it.
        $queued = DeferredSessionCarryoverQueue::drain();
        $this->assertCount(1, $queued, 'The photographed login waits in the queue for its owner');
        SessionCarrier::carryOver($queued);

        $row = self::sessionRow(self::TOKEN);
        $this->assertNotNull($row, 'The token resolves again once the library has applied the queue');
        $this->assertSame((string)self::NEW_USER_ID, (string)$row['user_id']);
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testAFailedImportCarriesNothingOverAtAll(): void
    {
        $this->seedLiveSession();
        $agent = $this->admittedRestore();
        $agent->onProtectedModeReady();
        $this->swapDatabase();

        $this->endRestoreChild($agent, ExitCode::ERROR);
        $agent->onDbReHydrateComplete(new DbReHydrateOutcome(true, []));

        $this->assertSame(
            [],
            DeferredSessionCarryoverQueue::drain(),
            'Handing sessions to the library after a half-imported database would build on top of the damage',
        );
        $this->assertNull(self::sessionRow(self::TOKEN), 'And nothing writes them behind its back either');
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testTheOutcomeIsAnnouncedToTheInitiatorInTheDatabaseThatReplacedTheirs(): void
    {
        $this->seedLiveSession();
        $agent = $this->admittedRestore();
        $agent->onProtectedModeReady();
        $this->swapDatabase();

        $this->endRestoreChild($agent, ExitCode::SUCCESS);
        $agent->onDbReHydrateComplete(new DbReHydrateOutcome(true, []));

        $letters = DeferredNotificationQueue::drain();
        $draft = self::letterTo($letters, self::NEW_USER_ID);
        $this->assertNotNull($draft, 'The person who asked is found again by identity, not by the id they had');
        $this->assertSame(BackupNotificationType::RESTORE_SUCCEEDED, $draft->type);
        $this->assertNull(
            self::letterTo($letters, self::OLD_USER_ID),
            'That id belongs to somebody else in the restored database, and mailing them would be the'
            . ' whole reason the initiator travels as identities',
        );
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testARestoreThatDidNotComeBackIsAnnouncedAsAFailure(): void
    {
        $this->seedLiveSession();
        $agent = $this->admittedRestore();
        $agent->onProtectedModeReady();
        $this->swapDatabase();

        $this->endRestoreChild($agent, ExitCode::ERROR);
        $agent->onDbReHydrateComplete(new DbReHydrateOutcome(true, []));

        $draft = self::letterTo(DeferredNotificationQueue::drain(), self::NEW_USER_ID);
        $this->assertNotNull($draft, 'A restore that failed is exactly the one nobody may find out about by chance');
        $this->assertSame(BackupNotificationType::RESTORE_FAILED, $draft->type);
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testTheFrozenNodeStillHoldsTheHallItsStoppedAgentLeftBehind(): void
    {
        // The order of 22.08.2026, end to end: the tabs are connected, the freeze stops the agent
        // that owns their rows, and only then is the photograph taken. The agent leaving used to
        // empty the collection, so this is where "carried over 0" was born - with every socket of
        // the node still open and a person watching their own restore log them out.
        self::seedSession(self::TOKEN, self::OLD_USER_ID, self::CREATED_AT, self::EXPIRES_AT);
        self::seedIdentity(self::OLD_USER_ID, self::EMAIL_TYPE, self::EMAIL);

        $worker = new CarryOverTestWorkerManager();
        $worker->handleDaemonMessage(new AgentStartDTO(CarryOverTestOwnerAgent::AGENT_TYPE));
        $this->openConnection('accept-live', self::TOKEN);
        $this->openConnection('accept-gone', self::OTHER_TOKEN);

        $worker->handleDaemonMessage(new AgentStopDTO(CarryOverTestOwnerAgent::AGENT_TYPE));

        $agent = $this->admittedRestore();
        $agent->onProtectedModeReady();

        $this->assertCount(
            1,
            $this->snapshotOf($agent),
            'The freeze stops the agents, not the sockets: the hall it photographs is still full',
        );
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testTheAgentComingBackStrikesTheTabThatClosedWhileItWasDown(): void
    {
        // The other end of the same window. Nobody could write the collection while the agent was
        // stopped, so a tab that closed in the meantime left its row behind; the master's roster
        // is what tells the agent which rows those are.
        self::seedSession(self::TOKEN, self::OLD_USER_ID, self::CREATED_AT, self::EXPIRES_AT);

        $worker = new CarryOverTestWorkerManager();
        $worker->handleDaemonMessage(new AgentStartDTO(CarryOverTestOwnerAgent::AGENT_TYPE));
        $this->openConnection('accept-live', self::TOKEN);
        $this->openConnection('accept-gone', self::OTHER_TOKEN);

        $worker->handleDaemonMessage(new AgentStopDTO(CarryOverTestOwnerAgent::AGENT_TYPE));
        $worker->handleDaemonMessage(new AgentStartDTO(CarryOverTestOwnerAgent::AGENT_TYPE, ['accept-live']));

        $connections = $this->connectionRegistry();
        $this->assertTrue(isset($connections['accept-live']), 'A socket the node still holds keeps its row');
        $this->assertFalse(isset($connections['accept-gone']), 'A socket that died unwitnessed loses it');
    }

    /**
     * Registers one connection row the way a handshake does, under the agent that owns them.
     *
     * @param string $acceptKey Accept key of the socket
     * @param string $sessionToken Session token the socket belongs to
     * @throws HilosException When the runtime write is refused
     */
    private function openConnection(string $acceptKey, string $sessionToken): void
    {
        ExecutionContext::setCurrentAgentId(CarryOverTestOwnerAgent::AGENT_TYPE);
        $this->connectionRegistry()->actions->register($acceptKey, self::OLD_USER_ID, $sessionToken);
    }

    /**
     * @return CarryOverTestViewConnections The represented connections collection of this context
     */
    private function connectionRegistry(): CarryOverTestViewConnections
    {
        $registry = Hilos::$rt?->connectionsRegistry();

        return $registry instanceof CarryOverTestViewConnections
            ? $registry
            : throw new RuntimeException('The connections collection is not represented.');
    }

    /**
     * Picks one recipient's letter out of what the restore left for the notifications library.
     *
     * Off the queue rather than out of `hilos_notification`, because a restore announces itself
     * with the node still frozen and every other agent stopped, so what it can do is leave the
     * letter behind (HIL-771). Who it is addressed to is still decided here, in the database that
     * replaced the initiator's, and that is what these cases are about.
     *
     * @param list<NotificationDraft> $letters Everything the restore left, drained once by the caller
     * @param int $userId Recipient user id
     * @return ?NotificationDraft The newest letter for them, or null when they got none
     */
    private static function letterTo(array $letters, int $userId): ?NotificationDraft
    {
        $mine = array_values(array_filter(
            $letters,
            static fn(NotificationDraft $draft): bool => $draft->userId === $userId,
        ));

        return $mine === [] ? null : $mine[count($mine) - 1];
    }

    /**
     * Seeds one live authenticated session with a single identity, and a connection holding it.
     *
     * @throws HilosException When a step against the database fails
     */
    private function seedLiveSession(): void
    {
        self::seedSession(self::TOKEN, self::OLD_USER_ID, self::CREATED_AT, self::EXPIRES_AT);
        self::seedIdentity(self::OLD_USER_ID, self::EMAIL_TYPE, self::EMAIL);

        /** @var CarryOverTestRtContext $rt */
        $rt = Hilos::$rt;
        $rt->connections()->add(CarryOverTestConnection::create('accept-1', self::OLD_USER_ID, self::TOKEN));
    }

    /**
     * Replaces the database contents the way the restore child does, and re-reads the collections.
     *
     * @throws HilosException When the swap or the re-read fails
     */
    private function swapDatabase(): void
    {
        Database::sqlRun('DELETE FROM `hilos_session`');
        Database::sqlRun('DELETE FROM `hilos_identity`');
        self::seedIdentity(self::NEW_USER_ID, self::EMAIL_TYPE, self::EMAIL);

        Hilos::$db->reHydrateDbBackedCollections();
    }

    /**
     * Builds an agent standing where an admitted restore leaves it, with the freeze still ahead.
     *
     * Admission is driven from the command channel against project configuration a framework test
     * has no catalog for, so the state that path produces is set rather than re-enacted - the same
     * technique, and the same reason, as the restore barrier unit cases.
     *
     * @return BackupAgent Agent holding an admitted restore
     */
    private function admittedRestore(): BackupAgent
    {
        $this->restoreRow()->actions->markRunning(self::BACKUP_ID, BackupScope::FULL);

        $agent = new BackupAgent();
        $enter = Closure::bind(
            static function (BackupAgent $agent, string $backupId): void {
                $agent->pendingRestoreId = $backupId;
                $agent->pendingRestoreScope = BackupScope::FULL;
                $agent->pendingRestoreDecision = RestoreEnvDecision::ALLOW;
                $agent->pendingRestoreSince = microtime(true);
                // Admission's own step, taken here for the same reason as the three above: it reads
                // the initiator's identities while the database that knows them is still the live one.
                $agent->pendingRestoreInitiatorIdentities = $agent->captureInitiatorIdentities(
                    BackupAgentRestoreCarryOverIntegrationTest::OLD_USER_ID,
                );
            },
            null,
            BackupAgent::class,
        );

        $enter($agent, self::BACKUP_ID);

        return $agent;
    }

    /**
     * Ends the restore child the way the poller does when it finds the process gone.
     *
     * @param BackupAgent $agent Agent whose restore child has just exited
     * @param int $exitCode Exit code the child reported
     */
    private function endRestoreChild(BackupAgent $agent, int $exitCode): void
    {
        $end = Closure::bind(
            static function (BackupAgent $agent, int $exitCode): void {
                $agent->runKind = BackupRunKind::RESTORE;
                $agent->finishChild(
                    $exitCode === ExitCode::SUCCESS,
                    $exitCode === ExitCode::SUCCESS ? null : 'child exited with code ' . $exitCode,
                    $exitCode,
                );
            },
            null,
            BackupAgent::class,
        );

        $end($agent, $exitCode);
    }

    /**
     * Reads the snapshot the agent is holding for the run in flight.
     *
     * @param BackupAgent $agent Agent in the middle of a restore
     * @return array<int, object> Photographed sessions, empty when none were taken
     */
    private function snapshotOf(BackupAgent $agent): array
    {
        $read = Closure::bind(
            static fn(BackupAgent $agent): array => $agent->pendingCarryover ?? [],
            null,
            BackupAgent::class,
        );

        return $read($agent);
    }

    /**
     * @return RestoreRuntime The mounted restore runtime row
     */
    private function restoreRow(): RestoreRuntime
    {
        $view = Hilos::$rt?->hilosRestoreRuntime;

        return $view instanceof RestoreRuntime
            ? $view
            : throw new RuntimeException('The restore runtime singleton is not mounted.');
    }

    /**
     * Builds an environment the restore path can read every value it needs from.
     *
     * The CLI entry names an empty file on purpose: the spawn is real, so the case exercises the
     * supervisor's own path, but the child it starts has nothing to do and no database to reach.
     *
     * The backup directory is a real one this case owns, and has to be: both things a restore
     * leaves behind - the logins and the announcement - are files under it now (HIL-771), and a
     * directory that is not there turns a queued line into a logged write failure, which reads
     * from the outside exactly like a restore that carried nothing.
     *
     * @return EnvAccessor Accessor answering the backup keys with fixtures
     */
    private function env(): EnvAccessor
    {
        return new class ($this->backupDir) extends EnvAccessor {
            /**
             * @param string $backupDir Directory this case's queues live in
             */
            public function __construct(private readonly string $backupDir)
            {
                parent::__construct();
            }

            public function string(EnvConstants|string $name): string
            {
                return $name === EnvConstants::BACKUP_DIR ? $this->backupDir : '/dev/null';
            }

            public function int(EnvConstants|string $name): int
            {
                return 600;
            }
        };
    }
}

/**
 * The smallest concrete connection row: the framework session triple and nothing else.
 */
final class CarryOverTestConnection extends HilosSessionConnection
{
    /**
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return CarryOverTestRtContext::connections;
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
 * A project connections collection, as every project that has sessions declares one.
 *
 * @extends HilosSessionConnections<CarryOverTestConnection>
 */
final class CarryOverTestConnections extends HilosSessionConnections
{
    public const string STATE_CLASS = CarryOverTestConnection::class;
}

/**
 * The project's view of one connection row: the framework session match and nothing else.
 *
 * @extends ViewHilosSessionConnection<CarryOverTestConnection>
 */
final class CarryOverTestViewConnection extends ViewHilosSessionConnection
{
}

/**
 * @extends HilosConnectionActions<CarryOverTestViewConnection>
 */
final class CarryOverTestConnectionActions extends HilosConnectionActions
{
}

/**
 * @extends HilosSessionConnectionsActions<CarryOverTestViewConnection, CarryOverTestViewConnections>
 */
final class CarryOverTestConnectionsActions extends HilosSessionConnectionsActions
{
}

/**
 * @extends ViewHilosSessionConnections<CarryOverTestViewConnection, CarryOverTestConnectionsActions>
 */
final class CarryOverTestViewConnections extends ViewHilosSessionConnections
{
    /**
     * @param RtState $state Backing state row
     * @return CarryOverTestViewConnection View item over the row
     */
    protected function createRtItem(RtState $state): CarryOverTestViewConnection
    {
        /** @var CarryOverTestConnection $state */
        return new CarryOverTestViewConnection($state);
    }
}

/**
 * The agent that owns the connections, as every project has exactly one.
 *
 * Its stop hook is empty and that is the fix under test (HIL-664): the rows belong to the node
 * holding the sockets, so an agent on its way out has nothing to say about them.
 */
final class CarryOverTestOwnerAgent extends AbstractAgent
{
    /** @var string Agent type identifier */
    public const string AGENT_TYPE = 'carryover_connections_owner';

    /**
     * Claims the connections, which is what makes this agent the one allowed to strike a row.
     */
    public function onStart(): void
    {
        $this->registerRtTruthSource(CarryOverTestRtContext::connections);
    }

    public function onStop(): void
    {
    }
}

final class CarryOverTestAgentManager extends AgentManager
{
    /**
     * @param string $agentType Agent type to build
     * @param ?string $agentIndex Agent index, or null for a singleton
     * @return AgentInterface The one agent these cases host
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new CarryOverTestOwnerAgent();
    }
}

/**
 * Worker manager with the daemon link replaced by a sink, so start and stop frames can be
 * delivered to a real worker without a socket behind it.
 */
final class CarryOverTestWorkerManager extends WorkerManager
{
    public function __construct()
    {
        parent::__construct(1);

        $this->daemonClient = new CarryOverTestDaemonClient();
    }

    /**
     * @return SignalRouter Router this worker registers globally
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /**
     * @return AgentManager Manager building the one agent these cases host
     */
    protected function createAgentManager(): AgentManager
    {
        return new CarryOverTestAgentManager();
    }
}

/**
 * Daemon link that drops what the worker sends instead of writing it to a socket.
 */
final class CarryOverTestDaemonClient extends WorkerDaemonClient
{
    public function __construct()
    {
    }

    /**
     * @return bool Always true: these cases never test the link itself
     */
    public function isConnected(): bool
    {
        return true;
    }

    /**
     * @param WorkerDTO|array<string, mixed> $data Message the worker wanted delivered
     */
    public function send(WorkerDTO|array $data): void
    {
    }
}

/**
 * A runtime context carrying the project's live connections; the backup rows are mounted onto it.
 */
final class CarryOverTestRtContext extends RtContext
{
    public const string connections = 'connections';

    /**
     * Mounts the one collection these cases need: the project's live connections, represented -
     * the write side is what the roster reconcile of a starting agent goes through (HIL-664).
     */
    public function configure(): void
    {
        $this->_stateCollections[self::connections] = CarryOverTestConnections::init();
        $this->setRepresent(
            self::connections,
            CarryOverTestViewConnections::class,
            CarryOverTestConnectionsActions::class,
            CarryOverTestConnectionActions::class,
        );
    }

    /**
     * @return CarryOverTestConnections Live connections of this context
     */
    public function connections(): CarryOverTestConnections
    {
        /** @var CarryOverTestConnections $connections */
        $connections = $this->_stateCollections[self::connections];

        return $connections;
    }
}
