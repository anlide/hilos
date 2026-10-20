<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Cluster\ClusterContext;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\TestOrmForgetRequest;
use Hilos\Core\Daemon\TestOrmForgetRequests;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\DbItem;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Client\CommandClient;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DaemonConnectionState;
use Hilos\Socket\Worker\DTO\WorkerRegisterDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerTestOrmForgetDTO;
use Hilos\Socket\Worker\DTO\WorkerTestOrmForgottenDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Socket;

/**
 * Loaded ORM rows never leave their process (testing.md observability exception 1).
 * Separate contexts expose that memory while real socket pairs exercise both readers,
 * worker registration, the actual worker handler and the master's held console reply.
 */
final class OrmForgetTransportIntegrationTest extends FrameworkIntegrationTestCase
{
    private ?DbContext $previousDb;
    private ?EnvAccessor $previousEnv;
    private ?SignalRouter $previousRouter;
    private ?ClusterContext $previousCluster;
    private string|false $previousAppEnv;
    /** @var list<Socket> Open test sockets */
    private array $sockets = [];
    private OrmTransportMaster $master;
    private CommandServer $console;
    private CommandClient $caller;
    private Socket $terminal;
    private OrmTransportContext $masterDb;
    private OrmTransportContext $workerDb;
    private OrmTransportContext $neighborDb;
    private WorkerClient $receiver;
    private OrmTransportSocket $sender;
    private OrmTransportWorker $worker;
    private AgentManagerDaemon $agents;

    /** @throws HilosException When fixture SQL or the test transport cannot initialize */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousEnv = Hilos::$env;
        $this->previousRouter = Hilos::$sr;
        $this->previousCluster = Hilos::$cluster;
        $this->previousAppEnv = getenv('APP_ENV');
        $this->environment('test');
        Hilos::$cluster = null;
        Database::sqlRun('DROP TABLE IF EXISTS `orm_forget_transport_test`');
        Database::sqlRun('CREATE TABLE `orm_forget_transport_test` (`id` INT PRIMARY KEY, `label` VARCHAR(40) NOT NULL) ENGINE=InnoDB');
        Database::sqlRun("INSERT INTO `orm_forget_transport_test` VALUES (1, 'before')");
        foreach (['masterDb', 'workerDb', 'neighborDb'] as $name) {
            $this->$name = new OrmTransportContext();
            $this->$name->configure();
            self::assertSame('before', $this->$name->rows[1]->label());
        }
        Database::sqlRun("UPDATE `orm_forget_transport_test` SET `label` = 'after' WHERE `id` = 1");
        Hilos::$db = $this->masterDb;
        $this->agents = $this->createMock(AgentManagerDaemon::class);
        $this->master = new OrmTransportMaster($this->agents);
        $this->console = new CommandServer('127.0.0.1', 0);
        $this->master->registerServer($this->console);
        [$socket, $this->terminal] = $this->pair();
        $this->caller = new CommandClient($socket, $this->console);
    }

    /** @throws DatabaseException When private fixture cleanup fails */
    protected function tearDown(): void
    {
        Database::handlerEnd();
        Database::sqlRun('DROP TABLE IF EXISTS `orm_forget_transport_test`');
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        $this->sockets = [];
        Hilos::$db = $this->previousDb;
        Hilos::$env = $this->previousEnv;
        Hilos::$sr = $this->previousRouter;
        Hilos::$cluster = $this->previousCluster;
        putenv($this->previousAppEnv === false ? 'APP_ENV' : 'APP_ENV=' . $this->previousAppEnv);
        parent::tearDown();
    }

    /** @return list<array{int}> Both worker-id signs */
    public static function workerIds(): array
    {
        return [[3], [-3]];
    }

    /** @param int $workerId Signed target id */
    #[DataProvider('workerIds')]
    public function testOnlyTheSelectedWorkerForgetsAndSuccessWaitsForItsAck(int $workerId): void
    {
        $this->connectWorker($workerId);
        $neighbor = $this->connection($workerId > 0 ? -3 : 3);
        $this->request(['process' => 'worker', 'workerId' => $workerId]);
        self::assertFalse($neighbor[0]->hasPendingWrite());
        self::assertFalse($this->caller->hasPendingWrite());
        self::assertSame(1, $this->pendingCount());
        self::assertSame('before', $this->workerDb->rows[1]->label());
        $this->runWorker();
        self::assertSame('after', $this->workerDb->rows[1]->label());
        self::assertSame('before', $this->masterDb->rows[1]->label());
        self::assertSame('before', $this->neighborDb->rows[1]->label());
        self::assertFalse($this->caller->hasPendingWrite(), 'Local completion alone has not delivered its ACK');
        $this->deliverAck();
        $reply = $this->readReply();
        self::assertTrue($reply->isOk());
        self::assertSame(['process' => 'worker', 'workerId' => $workerId], $reply->payload);
        self::assertSame('forget-1', $reply->correlationId);
        self::assertSame(0, $this->pendingCount());
        $this->sender->send(new WorkerTestOrmForgottenDTO('forget-1', true, null));
        $this->deliverAck();
        self::assertFalse($this->caller->hasPendingWrite(), 'A duplicate cannot answer twice');
        self::assertNull(Hilos::$sr->getNextQueuedSignal(), 'This door never routes an agent command');
    }

    /** The synchronous master path leaves every other cached context intact. */
    public function testDaemonForgetsOnlyItsOwnContext(): void
    {
        $this->request(['process' => 'daemon']);
        self::assertSame('after', $this->masterDb->rows[1]->label());
        self::assertSame('before', $this->workerDb->rows[1]->label());
        self::assertSame('before', $this->neighborDb->rows[1]->label());
        self::assertSame(['process' => 'daemon', 'workerId' => null], $this->readReply()->payload);
        self::assertSame(0, $this->pendingCount());
    }

    /** @param array<string, mixed> $payload Invalid target on the command wire */
    #[DataProvider('invalidTargets')]
    public function testMasterRepeatsStrictTargetValidation(array $payload): void
    {
        $this->request($payload);
        $this->assertRefusal('Invalid ORM forget target');
        self::assertSame('before', $this->masterDb->rows[1]->label());
        self::assertSame(0, $this->pendingCount());
    }

    /** @return list<array{array<string, mixed>}> Invalid process and worker-id shapes */
    public static function invalidTargets(): array
    {
        return array_map(static fn (array $payload): array => [$payload], [
            [], ['process' => 'other'], ['process' => null], ['process' => 1],
            ['process' => 'daemon', 'workerId' => null], ['process' => 'daemon', 'workerId' => 3],
            ['process' => 'daemon', 'extra' => true], ['process' => 'worker'],
            ['process' => 'worker', 'workerId' => null], ['process' => 'worker', 'workerId' => 0],
            ['process' => 'worker', 'workerId' => '3'], ['process' => 'worker', 'workerId' => 3.0],
            ['process' => 'worker', 'workerId' => true], ['process' => 'worker', 'workerId' => []],
            ['process' => 'worker', 'workerId' => 3, 'extra' => true],
        ]);
    }

    /** @param int $workerId Missing signed worker id */
    #[DataProvider('workerIds')]
    public function testMissingWorkerIsRefusedWithoutCreatingOne(int $workerId): void
    {
        $this->request(['process' => 'worker', 'workerId' => $workerId]);
        $this->assertRefusal("Worker {$workerId} is not connected");
        self::assertSame([], $this->master->workers->getClients());
        self::assertSame(0, $this->pendingCount());
    }

    /** A known index is insufficient without a registered live connection. */
    public function testUnregisteredAndClosingWorkersCannotBeSelected(): void
    {
        [$socket, $unused] = $this->pair();
        $client = new WorkerClient($socket, $this->agents, $this->master->workers);
        $client->setWorkerIndex(3);
        $this->master->workers->attach($client);
        $this->request(['process' => 'worker', 'workerId' => 3]);
        $this->assertRefusal('Worker 3 is not connected');
        self::assertFalse($client->hasPendingWrite());
        $this->master->workers->detach($client);
        $this->connectWorker(3);
        $this->receiver->markShouldClose();
        $this->request(['process' => 'worker', 'workerId' => 3], 'forget-2');
        $this->assertRefusal('Worker 3 is not connected');
    }

    /** @return list<array{string}> Local and remote processing paths */
    public static function processes(): array
    {
        return [['daemon'], ['worker']];
    }

    /** @param string $process Context to remove before handling */
    #[DataProvider('processes')]
    public function testMissingContextIsARefusal(string $process): void
    {
        $this->connectWorker(3);
        if ($process === 'daemon') {
            Hilos::$db = null;
            $this->request(['process' => 'daemon']);
        } else {
            $this->request(['process' => 'worker', 'workerId' => 3]);
            $this->runWorker(null);
            $this->deliverAck();
        }
        $this->assertRefusal('This process has no database context');
        self::assertSame(0, $this->pendingCount());
        self::assertSame('before', $this->masterDb->rows[1]->label());
        self::assertSame('before', $this->workerDb->rows[1]->label());
    }

    /** @param string $process Process whose transaction guard refuses */
    #[DataProvider('processes')]
    public function testOpenTransactionRefusesWithoutDroppingAnyLoadedRows(string $process): void
    {
        $this->connectWorker(3);
        Database::transactionStart();
        try {
            $this->request($process === 'daemon' ? ['process' => 'daemon'] : ['process' => 'worker', 'workerId' => 3]);
            if ($process === 'worker') {
                $this->runWorker();
                $this->deliverAck();
            }
            $this->assertRefusal('ORM forget requires all transactions to be closed');
            self::assertTrue(Database::hasOpenTransactions());
            self::assertSame('before', $this->masterDb->rows[1]->label());
            self::assertSame('before', $this->workerDb->rows[1]->label());
        } finally {
            Database::transactionRollback();
        }
    }

    /** The recipient checks its own environment and accepts a later valid request. */
    public function testWorkerRepeatsEnvironmentGateAndRemainsAliveAfterRefusal(): void
    {
        $this->connectWorker(3);
        $this->request(['process' => 'worker', 'workerId' => 3]);
        $this->environment('prod');
        $this->runWorker();
        $this->environment('test');
        $this->deliverAck();
        $this->assertRefusal(TestOnlyCommandOnProductionException::message(CliCommands::ORM_TEST_FORGET));
        self::assertSame('before', $this->workerDb->rows[1]->label());
        self::assertFalse($this->receiver->shouldClose());
        $this->request(['process' => 'worker', 'workerId' => 3], 'forget-2');
        $this->runWorker();
        $this->deliverAck();
        self::assertTrue($this->readReply()->isOk());
    }

    /** An unexpected local failure remains an outcome instead of ending the worker. */
    public function testWorkerUnexpectedFailureBecomesOneNegativeAck(): void
    {
        $this->connectWorker(3);
        $this->request(['process' => 'worker', 'workerId' => 3]);
        $broken = new class extends HilosDbContext {
            /** The injected failure context mounts no collections. */
            public function configure(): void
            {
            }

            /** @throws RuntimeException Injected local API failure */
            public function forgetLoadedDataForTest(): void
            {
                throw new RuntimeException('Injected ORM failure');
            }
        };
        $this->runWorker($broken);
        $this->deliverAck();
        $this->assertRefusal('Injected ORM failure');
        self::assertFalse($this->receiver->shouldClose());
        self::assertFalse($this->caller->hasPendingWrite());
    }

    /** Only the original connection can answer, even after its id is reused. */
    public function testForeignAndReplacementConnectionsCannotCompleteTheOriginalWait(): void
    {
        $this->connectWorker(3);
        [$foreign, $foreignWriter] = $this->connection(4);
        $this->request(['process' => 'worker', 'workerId' => 3]);
        $foreignWriter->send(['type' => 'test_orm_forgotten', 'correlationId' => 'forget-1', 'ok' => true, 'error' => null, 'workerId' => 3]);
        $foreignWriter->write();
        $foreign->read();
        self::assertFalse($this->caller->hasPendingWrite());
        $this->master->workers->detach($this->receiver);
        [$replacement, $replacementWriter] = $this->connection(3);
        $replacementWriter->send(new WorkerTestOrmForgottenDTO('forget-1', true, null));
        $replacementWriter->write();
        $replacement->read();
        self::assertSame(1, $this->pendingCount());
        self::assertFalse($this->caller->hasPendingWrite());
        self::assertFalse($replacement->hasPendingWrite(), 'The request was not redirected to the replacement');
        $this->runWorker();
        $this->deliverAck();
        self::assertTrue($this->readReply()->isOk());
    }

    /** Negative, unknown and duplicate outcomes preserve the pending correlation contract. */
    public function testFalseAndUnknownCorrelationAcksCannotProduceSuccess(): void
    {
        $this->connectWorker(3);
        $this->request(['process' => 'worker', 'workerId' => 3]);
        $this->sender->send(new WorkerTestOrmForgottenDTO('other-correlation', true, null));
        $this->deliverAck();
        self::assertSame(1, $this->pendingCount());
        self::assertFalse($this->caller->hasPendingWrite());
        $this->sender->send(new WorkerTestOrmForgottenDTO('forget-1', false, 'Worker refused'));
        $this->deliverAck();
        $this->assertRefusal('Worker refused');
        self::assertSame(0, $this->pendingCount());
        $this->sender->send(new WorkerTestOrmForgottenDTO('forget-1', true, null));
        $this->deliverAck();
        self::assertFalse($this->caller->hasPendingWrite());
    }

    /** A rejected frame leaves the valid outstanding request answerable. */
    public function testMalformedAckDoesNotConsumeTheWait(): void
    {
        $this->connectWorker(3);
        $this->request(['process' => 'worker', 'workerId' => 3]);
        $this->sender->send(['type' => 'test_orm_forgotten', 'correlationId' => 'forget-1', 'ok' => true]);
        try {
            $this->deliverAck();
            self::fail('Incomplete ACK must fail parsing');
        } catch (InvalidFormatException) {
            self::assertSame(1, $this->pendingCount());
            self::assertFalse($this->caller->hasPendingWrite());
        }
        $this->runWorker();
        $this->deliverAck();
        self::assertTrue($this->readReply()->isOk());
    }

    /** Connection loss consumes the original wait before a replacement can answer. */
    public function testDisconnectConsumesTheWaitAndReplacementAckIsIgnored(): void
    {
        $this->connectWorker(-3);
        $this->request(['process' => 'worker', 'workerId' => -3]);
        $this->closeWorker();
        $this->assertRefusal('Worker -3 disconnected before ORM forget completed');
        self::assertSame(0, $this->pendingCount());
        $this->connectWorker(-3);
        $this->sender->send(new WorkerTestOrmForgottenDTO('forget-1', true, null));
        $this->deliverAck();
        self::assertFalse($this->caller->hasPendingWrite());
    }

    /** The inner wait refuses before the CLI window without retrying sent work. */
    public function testTimeoutPrecedesCallerWindowAndLateAckIsIgnored(): void
    {
        $this->connectWorker(3);
        $before = microtime(true);
        $this->request(['process' => 'worker', 'workerId' => 3]);
        $pending = $this->pending();
        self::assertGreaterThanOrEqual($before + CommandChannelWindows::AGENT_WAIT_SECONDS, $pending['forget-1']->deadline);
        self::assertLessThan($before + CommandChannelWindows::CALLER_WAIT_SECONDS, $pending['forget-1']->deadline);
        $this->master->waits()->tick($pending['forget-1']->deadline);
        $this->assertRefusal('Worker 3 did not confirm ORM forget in time');
        self::assertSame(0, $this->pendingCount());
        $this->runWorker();
        $this->deliverAck();
        self::assertFalse($this->caller->hasPendingWrite());
    }

    /** An expired wait cannot succeed between the deadline and the next timer pass. */
    public function testAckAfterDeadlineIsRefusedEvenBeforeTheNextTimerTick(): void
    {
        $this->connectWorker(3);
        $this->request(['process' => 'worker', 'workerId' => 3]);
        new ReflectionProperty(TestOrmForgetRequests::class, 'pending')->setValue($this->master->waits(), [
            'forget-1' => new TestOrmForgetRequest('forget-1', $this->receiver, 3, microtime(true) - 1),
        ]);
        $this->runWorker();
        $this->deliverAck();
        $this->assertRefusal('Worker 3 did not confirm ORM forget in time');
        self::assertSame(0, $this->pendingCount());
    }

    /** Caller departure drops only waiting; the already queued worker work may still finish. */
    public function testCallerCloseAbandonsWaitingWithoutPromisingToCancelSentWork(): void
    {
        $this->connectWorker(3);
        $this->request(['process' => 'worker', 'workerId' => 3]);
        $socket = $this->caller->getSocket();
        $this->caller->close();
        $this->forgetSocket($socket);
        self::assertSame(0, $this->pendingCount());
        $this->runWorker();
        self::assertSame('after', $this->workerDb->rows[1]->label());
        $this->deliverAck();
        self::assertFalse($this->caller->hasPendingWrite());
        $this->master->waits()->tick(INF);
        self::assertSame(0, $this->pendingCount());
    }

    /** @param string $env Environment of the process being exercised */
    private function environment(string $env): void
    {
        putenv('APP_ENV=' . $env);
        Hilos::$env = new EnvAccessor();
    }

    /** @return array{Socket, Socket} Nonblocking endpoints retained for cleanup */
    private function pair(): array
    {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        foreach ($pair as $socket) {
            self::assertTrue(socket_set_nonblock($socket));
            $this->sockets[] = $socket;
        }
        return $pair;
    }

    /** @param int $workerId Signed id of the real registration frame */
    private function connectWorker(int $workerId): void
    {
        [$this->receiver, $this->sender] = $this->connection($workerId);
        $this->worker = new OrmTransportWorker($this->sender);
    }

    /**
     * @param int $workerId Signed worker registration id
     * @return array{WorkerClient, OrmTransportSocket} Registered transport endpoints
     */
    private function connection(int $workerId): array
    {
        [$masterSocket, $workerSocket] = $this->pair();
        $receiver = new WorkerClient($masterSocket, $this->agents, $this->master->workers);
        $sender = new OrmTransportSocket($workerSocket);
        $this->master->workers->attach($receiver);
        $sender->send(new WorkerRegisterDTO(abs($workerId), $workerId < 0));
        $sender->write();
        $receiver->read();
        self::assertTrue($receiver->isRegistered());
        $receiver->write();
        $sender->read();
        self::assertInstanceOf(WorkerRegisteredDTO::class, $sender->getNextMessage());
        return [$receiver, $sender];
    }

    /**
     * @param array<string, mixed> $payload Target on the real console wire
     * @param string $correlation Console correlation to retain through the round trip
     */
    private function request(array $payload, string $correlation = 'forget-1'): void
    {
        $frame = json_encode(
            new CommandRequestDTO($correlation, CliCommands::ORM_TEST_FORGET, $payload)->toArray(),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ) . "\n";
        self::assertSame(strlen($frame), socket_write($this->terminal, $frame));
        $this->caller->read();
    }

    /** @param ?DbContext $context Optional replacement or absent worker database */
    private function runWorker(?DbContext $context = null): void
    {
        Hilos::$db = func_num_args() === 0 ? $this->workerDb : $context;
        $this->receiver->write();
        $this->sender->read();
        $frame = $this->sender->getNextMessage();
        self::assertInstanceOf(WorkerTestOrmForgetDTO::class, $frame);
        $this->worker->handleDaemonMessage($frame);
        self::assertNull($this->sender->getNextMessage());
        Hilos::$db = $this->masterDb;
    }

    private function deliverAck(): void
    {
        $this->sender->write();
        $this->receiver->read();
    }

    /** @return CommandReplyDTO Outcome delivered over the held console socket */
    private function readReply(): CommandReplyDTO
    {
        self::assertTrue($this->caller->hasPendingWrite());
        $this->caller->write();
        $wire = socket_read($this->terminal, 65536);
        self::assertIsString($wire);
        self::assertSame(1, substr_count($wire, "\n"));
        return CommandReplyDTO::fromJson(trim($wire));
    }

    /** @param string $reason Exact refusal expected on the console */
    private function assertRefusal(string $reason): void
    {
        $reply = $this->readReply();
        self::assertFalse($reply->isOk());
        self::assertSame(['message' => $reason], $reply->payload);
    }

    /** @return array<string, TestOrmForgetRequest> Actual master wait records */
    private function pending(): array
    {
        return new ReflectionProperty(TestOrmForgetRequests::class, 'pending')->getValue($this->master->waits());
    }

    /** @return int Number of retained wait records */
    private function pendingCount(): int
    {
        return count($this->pending());
    }

    private function closeWorker(): void
    {
        $socket = $this->receiver->getSocket();
        $this->receiver->close();
        $this->master->workers->detach($this->receiver);
        $this->forgetSocket($socket);
    }

    /** @param Socket $closed Socket already closed through the production client */
    private function forgetSocket(Socket $closed): void
    {
        $this->sockets = array_values(array_filter($this->sockets, static fn (Socket $socket): bool => $socket !== $closed));
    }
}

/** The real master wiring with an empty agent roster and no process startup. */
final class OrmTransportMaster extends DaemonManager
{
    public OrmTransportServer $workers;

    /** @param AgentManagerDaemon $agents Empty roster for transport tests */
    public function __construct(private readonly AgentManagerDaemon $agents)
    {
        parent::__construct();
        $this->workers = new OrmTransportServer();
        $this->registerServer($this->workers);
    }

    /** @return TestOrmForgetRequests Production waits for deterministic timer checks */
    public function waits(): TestOrmForgetRequests
    {
        return new ReflectionProperty(DaemonManager::class, 'testOrmForgetRequests')->getValue($this);
    }

    /** @return SignalRouter Real router, unused by this master control door */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Test roster */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return $this->agents;
    }
}

/** Supplies already connected clients instead of starting worker processes. */
final class OrmTransportServer extends WorkerServer
{
    /** Bypasses process startup; the test supplies already connected workers. */
    public function __construct()
    {
    }

    /** @param WorkerClient $worker Test socket accepted by this server */
    public function attach(WorkerClient $worker): void
    {
        $this->clients[] = $worker;
    }

    /** @param WorkerClient $worker Original channel replaced in the registry */
    public function detach(WorkerClient $worker): void
    {
        $this->clients = array_values(array_filter($this->clients, static fn (WorkerClient $client): bool => $client !== $worker));
    }

    /** No listening socket or child process is needed by the test. */
    protected function onStart(): void
    {
    }
}

/** Binds the real worker handler to a connected IPC socket. */
final class OrmTransportWorker extends WorkerManager
{
    /** @param WorkerDaemonClient $sender Actual ACK transport */
    public function __construct(WorkerDaemonClient $sender)
    {
        $this->daemonClient = $sender;
    }

    /** @return SignalRouter Unused by this control frame */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /**
     * @return AgentManager No agent may be started by this request
     * @throws RuntimeException On unexpected initialization
     */
    protected function createAgentManager(): AgentManager
    {
        throw new RuntimeException('ORM forget must not start agents');
    }
}

/** Real worker socket reader and writer over an already connected pair. */
final class OrmTransportSocket extends WorkerDaemonClient
{
    /** @param Socket $socket Worker side of the pair */
    public function __construct(Socket $socket)
    {
        $this->socket = $socket;
        $this->state = DaemonConnectionState::CONNECTED;
    }
}

final class OrmTransportEntity extends Entity
{
    public const string _table = 'orm_forget_transport_test';
    public const string _primary = 'id';
    public const array _columns = ['id', 'label'];
    public const array _types = ['id' => 'integer', 'label' => 'string'];
    public ?int $id = null;
    public string $label = '';
}

final class OrmTransportEntities extends EntityCollection
{
    public const string ENTITY_CLASS = OrmTransportEntity::class;
}

/** @property OrmTransportEntity $entity Private fixture row */
final class OrmTransportObject extends Object_
{
    public const string ENTITY_CLASS = OrmTransportEntity::class;

    /** @return string Label held by this process's object */
    public function label(): string
    {
        return $this->entity->label;
    }
}

final class OrmTransportObjects extends Objects
{
    public const string COLLECTION_KEY = 'rows';
    public const string OBJECT_CLASS = OrmTransportObject::class;
    public const string ENTITY_COLLECTION_CLASS = OrmTransportEntities::class;
}

/** @extends DbItem<OrmTransportObject> */
final class OrmTransportItem extends DbItem
{
    /** @return string Label held by this wrapper */
    public function label(): string
    {
        return $this->_object->label();
    }
}

/** @extends DbCollection<OrmTransportItem, OrmTransportObjects> */
final class OrmTransportView extends DbCollection
{
    public const string DB_ITEM_CLASS = OrmTransportItem::class;
    public const string OBJECT_COLLECTION_CLASS = OrmTransportObjects::class;
}

/** @property-read OrmTransportView $rows Process-local rows over the same test table */
final class OrmTransportContext extends HilosDbContext
{
    /** @throws HilosException When the fixture collection cannot be mounted */
    public function configure(): void
    {
        $this->mountObjectCollection(OrmTransportObjects::class, Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(OrmTransportObjects::COLLECTION_KEY, OrmTransportView::class);
    }
}
