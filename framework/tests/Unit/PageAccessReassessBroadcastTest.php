<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\WebSocket\Exception\HandshakeFailedException;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessConnectionsMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerSourceInterestDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * The master's half of the access re-decision (HIL-644, HIL-652, HIL-911): it fans the
 * announcement out and does nothing else with it - except for the by-session criterion, which
 * only the master can resolve into connections.
 *
 * The master is the only process that can address "every worker of this node", and it is also
 * the one process that cannot say who is behind a connection - so what is pinned here is a
 * write per worker link, and the ORDER that write happens in. The order is not a nicety: the
 * database sync of the flag that was just written rides the same queue, and a worker that
 * re-decides ahead of it answers against a flag it has not seen change.
 */
final class PageAccessReassessBroadcastTest extends TestCase
{
    private const int USER_ID = 41;

    /** @var string DB collection the flag whose change is announced lives in */
    public const string USERS_COLLECTION = 'users';

    /** @var list<string> Accept keys of the session whose sign-out is announced */
    private const array ACCEPT_KEYS = ['ak-first', 'ak-second'];

    /** Session cookie of the browser whose open pages are re-judged, in the minted token form */
    private const string SESSION_TOKEN = '0123456789abcdef0123456789abcdef';

    /** Session cookie of any other browser, same form and a different value */
    private const string STRANGER_SESSION_TOKEN = 'fedcba9876543210fedcba9876543210';

    /** Temporary main log file the assertions read the written lines back from */
    private string $logFile = '';

    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-page-access-broadcast');
        Logger::setLogFile($this->logFile);
        // A connection gets its session hash from the cookie its handshake presents, and which
        // cookie that is comes from the environment.
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        Hilos::$env = new EnvAccessor();
        putenv('HILOS_SESSION_COOKIE_NAME=' . PageAccessReassessBroadcastTestWebSocketServer::SESSION_COOKIE_NAME);
    }

    protected function tearDown(): void
    {
        putenv('HILOS_SESSION_COOKIE_NAME');
        Hilos::$env = $this->previousEnv;
        Logger::resetLogFile();

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testTheAnnouncementIsWrittenToEveryWorkerLink(): void
    {
        $manager = new PageAccessReassessBroadcastTestManager();
        $workerServer = $manager->addWorkerServer();
        $workerServer->addWorker();
        $workerServer->addWorker();

        $manager->receiveAnnouncement(self::USER_ID);
        $manager->dispatch();

        $perWorker = $workerServer->framesPerWorker();
        $this->assertCount(2, $perWorker);
        foreach ($perWorker as $frames) {
            $this->assertCount(1, $frames);
            $restored = WorkerDTO::factoryWorkerDTO($frames[0]);
            $this->assertInstanceOf(WorkerPageAccessReassessMessageDTO::class, $restored);
            $this->assertSame(self::USER_ID, $restored->userId);
        }
        $this->assertSame('', $this->written());
    }

    /**
     * The load-bearing one, and the reason the master queues what it received instead of acting
     * on it. Both frames arrive from the writing worker in the order that worker queued them -
     * the flag's database sync first, the announcement second - and both are still sitting in
     * the master's own queue when the second arrives. Only the dispatch pass writes anything,
     * so each worker link is written the sync before the announcement and re-decides against
     * the flag it has just been told about. An announcement written at receipt would overtake
     * the sync and arrive first.
     */
    public function testTheFlagSyncIsWrittenToEachWorkerBeforeTheAnnouncement(): void
    {
        $manager = new PageAccessReassessBroadcastTestManager();
        $workerServer = $manager->addWorkerServer();
        $workerServer->addWorker();
        $manager->everyWorkerReads(self::USERS_COLLECTION);

        $manager->receiveFlagSync(self::USER_ID);
        $manager->receiveAnnouncement(self::USER_ID);

        $this->assertSame([[]], $workerServer->framesPerWorker(), 'Receipt writes nothing by itself');

        $manager->dispatch();

        $frames = $workerServer->framesPerWorker()[0];
        $this->assertCount(2, $frames);
        $this->assertInstanceOf(WorkerDbSyncUpdatedMessageDTO::class, WorkerDTO::factoryWorkerDTO($frames[0]));
        $this->assertInstanceOf(
            WorkerPageAccessReassessMessageDTO::class,
            WorkerDTO::factoryWorkerDTO($frames[1]),
        );
    }

    /**
     * The by-connection announcement takes the same road on the same terms (HIL-652). What the
     * master does with it is identical because it understands neither: it holds no browser
     * context to resolve a person and no subscription mirror to resolve a socket, so both
     * criteria are, to it, one list to hand every worker of the node.
     */
    public function testTheByConnectionAnnouncementIsWrittenToEveryWorkerLink(): void
    {
        $manager = new PageAccessReassessBroadcastTestManager();
        $workerServer = $manager->addWorkerServer();
        $workerServer->addWorker();
        $workerServer->addWorker();

        $manager->receiveConnectionsAnnouncement(self::ACCEPT_KEYS);
        $manager->dispatch();

        $perWorker = $workerServer->framesPerWorker();
        $this->assertCount(2, $perWorker);
        foreach ($perWorker as $frames) {
            $this->assertCount(1, $frames);
            $restored = WorkerDTO::factoryWorkerDTO($frames[0]);
            $this->assertInstanceOf(WorkerPageAccessReassessConnectionsMessageDTO::class, $restored);
            $this->assertSame(self::ACCEPT_KEYS, $restored->acceptKeys);
        }
        $this->assertSame('', $this->written());
    }

    /**
     * The order the sign-out depends on, and the reason the master queues this frame instead of
     * acting on it too. In a live sign-out the frame queued ahead is the runtime write that
     * un-points the connections; the sync below stands in for it, because what is pinned here is
     * the master's own behavior and not which write came first. An announcement acted on at
     * receipt would overtake whatever preceded it, and the sweep would then re-judge a connection
     * that still answers to the person who just signed out - and answer "allow".
     */
    public function testAnyWriteQueuedAheadReachesEachWorkerBeforeTheByConnectionAnnouncement(): void
    {
        $manager = new PageAccessReassessBroadcastTestManager();
        $workerServer = $manager->addWorkerServer();
        $workerServer->addWorker();
        $manager->everyWorkerReads(self::USERS_COLLECTION);

        $manager->receiveFlagSync(self::USER_ID);
        $manager->receiveConnectionsAnnouncement(self::ACCEPT_KEYS);

        $this->assertSame([[]], $workerServer->framesPerWorker(), 'Receipt writes nothing by itself');

        $manager->dispatch();

        $frames = $workerServer->framesPerWorker()[0];
        $this->assertCount(2, $frames);
        $this->assertInstanceOf(WorkerDbSyncUpdatedMessageDTO::class, WorkerDTO::factoryWorkerDTO($frames[0]));
        $this->assertInstanceOf(
            WorkerPageAccessReassessConnectionsMessageDTO::class,
            WorkerDTO::factoryWorkerDTO($frames[1]),
        );
    }

    /**
     * The by-session announcement is the one the master does not merely pass on (HIL-911). A worker
     * cannot tell which of its subscriptions belong to a browser, while the master holds exactly
     * that - the sockets, each with the session it was accepted under. So the master answers the
     * question and hands every worker the by-connection frame for the keys it found: both tabs of
     * the browser, and not the stranger connected beside them.
     */
    public function testTheBySessionAnnouncementReachesEveryWorkerAsTheConnectionsOfThatSession(): void
    {
        $manager = new PageAccessReassessBroadcastTestManager();
        $workerServer = $manager->addWorkerServer();
        $workerServer->addWorker();
        $workerServer->addWorker();
        $webSocketServer = $manager->addWebSocketServer();
        $firstTab = $webSocketServer->connect(self::SESSION_TOKEN);
        $secondTab = $webSocketServer->connect(self::SESSION_TOKEN);
        $webSocketServer->connect(self::STRANGER_SESSION_TOKEN);

        $manager->reassessPagesOfSession((string)$firstTab->sessionTokenHash);
        $manager->dispatch();

        $perWorker = $workerServer->framesPerWorker();
        $this->assertCount(2, $perWorker);
        foreach ($perWorker as $frames) {
            $this->assertCount(1, $frames);
            $restored = WorkerDTO::factoryWorkerDTO($frames[0]);
            $this->assertInstanceOf(WorkerPageAccessReassessConnectionsMessageDTO::class, $restored);
            $this->assertSame([$firstTab->acceptKey, $secondTab->acceptKey], $restored->acceptKeys);
        }
        $this->assertSame('', $this->written());
    }

    /**
     * A session with no connection on this node has no open page here, and an empty list would
     * cost every worker a frame that re-judges nothing - the rule the by-connection announcement
     * already keeps at its own door.
     */
    public function testASessionWithNoConnectionHereAnnouncesNothing(): void
    {
        $manager = new PageAccessReassessBroadcastTestManager();
        $workerServer = $manager->addWorkerServer();
        $workerServer->addWorker();
        $webSocketServer = $manager->addWebSocketServer();
        $webSocketServer->connect(self::STRANGER_SESSION_TOKEN);

        $manager->reassessPagesOfSession(ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN));
        $manager->dispatch();

        $this->assertSame([[]], $workerServer->framesPerWorker());
        $this->assertSame('', $this->written());
    }

    /**
     * A frame the master cannot build is a frame it must not invent a user id for: nothing goes
     * out, and the line names the class that arrived instead.
     */
    public function testAnAnnouncementCarryingTheWrongPayloadIsWrittenAndNotSent(): void
    {
        $manager = new PageAccessReassessBroadcastTestManager();
        $workerServer = $manager->addWorkerServer();
        $workerServer->addWorker();

        $this->queueMalformedAnnouncement(new SignalData(['userId' => self::USER_ID]));
        $manager->dispatch();

        $this->assertSame([[]], $workerServer->framesPerWorker());
        $this->assertStringContainsString('access re-decision carries invalid data', $this->written());
        $this->assertStringContainsString(SignalData::class, $this->written());
    }

    /**
     * Puts one announcement in the master's own queue carrying a payload no handler would build,
     * which is the only way the malformed case can be reached at all.
     *
     * @param SignalData $data Payload the announcement carries instead of the expected one
     */
    private function queueMalformedAnnouncement(SignalData $data): void
    {
        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::PAGE_ACCESS_REASSESS_USER),
            signalName: new SignalName(SignalConstants::PAGE_ACCESS_REASSESS_USER),
            signalData: $data,
        );
    }

    /**
     * Reads back whatever the dispatch pass wrote to the temporary log.
     *
     * @return string Log contents, empty when the pass stayed silent
     */
    private function written(): string
    {
        return (string)file_get_contents($this->logFile);
    }
}

/**
 * Daemon manager standing on a worker server and nothing else, able to run one dispatch pass.
 */
final class PageAccessReassessBroadcastTestManager extends DaemonManager
{
    /** Stand-in worker server, absent until a case registers one */
    public ?PageAccessReassessBroadcastTestWorkerServer $workerServer = null;

    /**
     * Registers the stand-in worker server the fan-out writes to.
     *
     * @return PageAccessReassessBroadcastTestWorkerServer The registered stand-in, for arranging the case
     */
    public function addWorkerServer(): PageAccessReassessBroadcastTestWorkerServer
    {
        $this->workerServer = new PageAccessReassessBroadcastTestWorkerServer();
        $this->registerServer($this->workerServer);

        return $this->workerServer;
    }

    /**
     * Registers the stand-in WebSocket server whose connections a session is resolved against.
     *
     * @return PageAccessReassessBroadcastTestWebSocketServer The registered stand-in, for connecting browsers to it
     */
    public function addWebSocketServer(): PageAccessReassessBroadcastTestWebSocketServer
    {
        $server = new PageAccessReassessBroadcastTestWebSocketServer();
        $this->registerServer($server);

        return $server;
    }

    /**
     * Reports every worker link of this pool as a reader of one database collection.
     *
     * Needed by the cases that expect a row frame to arrive (HIL-750): a row goes to the workers
     * that read its collection, so a link that never said what it reads is written nothing, and
     * a case about the ORDER of two frames would be asserting over one.
     *
     * @param string $collectionKey DB collection every link of the pool reads
     */
    public function everyWorkerReads(string $collectionKey): void
    {
        foreach ($this->workerServer?->workerIndexes() ?? [] as $workerIndex) {
            $this->agentManagerDaemon->handleSourceInterest(
                new WorkerSourceInterestDTO([], [$collectionKey]),
                $workerIndex,
            );
        }
    }

    /**
     * Hands the master the announcement frame the way a worker link does, through the handler
     * that receives it - so what this file asserts includes the decision to queue rather than
     * act.
     *
     * @param int $userId User the arriving announcement names
     * @throws InvalidArgumentException When the queued announcement carries an empty name
     */
    public function receiveAnnouncement(int $userId): void
    {
        $this->agentManagerDaemon->handleWorkerPageAccessReassess(
            new WorkerPageAccessReassessMessageDTO($userId),
        );
    }

    /**
     * Hands the master the by-connection announcement the way a worker link does, through the
     * handler that receives it (HIL-652).
     *
     * @param list<string> $acceptKeys Connections the arriving announcement names
     * @throws InvalidArgumentException When the queued announcement carries an empty name
     */
    public function receiveConnectionsAnnouncement(array $acceptKeys): void
    {
        $this->agentManagerDaemon->handleWorkerPageAccessReassessConnections(
            new WorkerPageAccessReassessConnectionsMessageDTO($acceptKeys),
        );
    }

    /**
     * Hands the master the database sync of the freshly written admin flag, through the same
     * receipt path, so both frames enter the queue exactly as they do in a live grant.
     *
     * @param int $userId User whose row was updated
     * @throws InvalidArgumentException When the queued sync carries an empty name
     */
    public function receiveFlagSync(int $userId): void
    {
        $this->agentManagerDaemon->handleWorkerDbSyncUpdated(new WorkerDbSyncUpdatedMessageDTO(
            new DbSyncUpdatedSignalData(
                PageAccessReassessBroadcastTest::USERS_COLLECTION,
                (string)$userId,
                ['admin' => 1],
            ),
        ));
    }

    /**
     * Drains the queue through the real dispatch pass, which is private to the manager.
     */
    public function dispatch(): void
    {
        $dispatch = Closure::bind(
            static function (DaemonManager $manager): void {
                $manager->dispatchSignals();
            },
            null,
            DaemonManager::class,
        );

        $dispatch($this);
    }

    /**
     * Swallows the master's own application of a sync frame: this file is about what leaves the
     * master, and applying a row would ask for a database nobody mounted here.
     *
     * @param SignalDTO $signal Signal being dispatched
     * @param ?string $originNodeId Node the write happened on, or null when it was this one
     */
    protected function handleDaemonSignal(SignalDTO $signal, ?string $originNodeId = null): void
    {
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new PageAccessReassessBroadcastTestAgentManagerDaemon();
    }
}

final class PageAccessReassessBroadcastTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}

/**
 * A worker server that keeps the frames written to each of its links instead of owning processes.
 */
final class PageAccessReassessBroadcastTestWorkerServer extends WorkerServer
{
    public function __construct()
    {
    }

    /**
     * Adds one more worker link for the fan-out to write to.
     *
     * Each link is given its own index, because that is the key the master's reader map holds
     * its interest under: links sharing one index would share one entry, and a case about two
     * workers would be arranging one.
     */
    public function addWorker(): void
    {
        $client = new PageAccessReassessBroadcastTestWorkerClient();
        $client->setWorkerIndex(count($this->clients));
        $this->clients[] = $client;
    }

    /**
     * @return list<int> Index of each worker link of this pool, in order
     */
    public function workerIndexes(): array
    {
        $indexes = [];
        foreach ($this->clients as $client) {
            if ($client instanceof PageAccessReassessBroadcastTestWorkerClient) {
                $indexes[] = $client->getWorkerIndex();
            }
        }

        return $indexes;
    }

    /**
     * @return list<list<string>> Raw frames each worker link was written, one entry per link
     */
    public function framesPerWorker(): array
    {
        $frames = [];
        foreach ($this->clients as $client) {
            if ($client instanceof PageAccessReassessBroadcastTestWorkerClient) {
                $frames[] = $client->frames;
            }
        }

        return $frames;
    }

    protected function onStart(): void
    {
    }
}

/**
 * A WebSocket server whose connections are handshaken probes rather than accepted sockets.
 */
final class PageAccessReassessBroadcastTestWebSocketServer extends WebSocketServer
{
    /** Name of the session cookie the probes present */
    public const string SESSION_COOKIE_NAME = 'hilos_session_token';

    public function __construct()
    {
        parent::__construct('127.0.0.1', 0);
    }

    /**
     * Puts a handshaken connection on the server, carrying the cookie of one browser.
     *
     * Driven through the real handshake rather than assembled, because the session hash is derived
     * there and nowhere else.
     *
     * @param string $sessionToken Session cookie the browser presents
     * @return WebSocketClientTestProbe Connection with a completed handshake
     * @throws HandshakeFailedException If the handshake is refused
     */
    public function connect(string $sessionToken): WebSocketClientTestProbe
    {
        $probe = WebSocketClientTestProbe::createSocketless();
        $probe->feed(
            "GET /ws HTTP/1.1\r\n"
            . "Host: localhost:8092\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Cookie: ' . self::SESSION_COOKIE_NAME . '=' . $sessionToken . "\r\n"
            . 'Sec-WebSocket-Key: ' . base64_encode('0123456789abcdef') . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n",
        );
        $this->clients[] = $probe;

        return $probe;
    }

    /**
     * @return string Server name the failure card names
     */
    public function getServerName(): string
    {
        return 'page-access-reassess-broadcast-test';
    }

    protected function onStart(): void
    {
    }

    /**
     * @param resource $socket Client socket
     * @return WebSocketClientInterface Never returned; this server accepts nothing
     * @throws AgentDaemonCreationFailedException Always
     */
    protected function onCreateClient($socket): WebSocketClientInterface
    {
        throw new AgentDaemonCreationFailedException('the re-decision broadcast test accepts no connection');
    }
}

/**
 * A worker link that keeps what was written to it instead of owning a socket.
 */
final class PageAccessReassessBroadcastTestWorkerClient extends WorkerClient
{
    /** @var list<string> Raw frames the master wrote to this link, in order */
    public array $frames = [];

    public function __construct()
    {
    }

    /**
     * @param string $message Frame the master wants written to this worker
     */
    public function send(string $message): void
    {
        $this->frames[] = $message;
    }
}
