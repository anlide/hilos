<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\DaemonNodeReportTestDoor;
use Hilos\Core\Router\SignalRouter;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Server\CommandServer;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\WorkerAgentStoppedDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Utils\LogLevel;
use Hilos\Utils\Logger;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Socket;

/**
 * Only a received stop report confirms a requested stop after roster removal.
 */
final class WorkerClientAgentStoppedReportTest extends TestCase
{
    private const string AGENT_ID = 'unit_stop_report';

    private const int WORKER_INDEX = 23;

    private string $logFile;

    private ?string $previousLogFile;

    private bool $previousShowLogLevel;

    private LogLevel $previousWriteLevel;

    private ?EnvAccessor $previousEnv;

    /** @var list<WorkerClient> Connections to close after each case */
    private array $clients = [];

    /** @var list<Socket> Other ends of the local worker connections */
    private array $peers = [];

    /**
     * @return array<string, array{bool}> Both worker kinds use their connection's positive index
     */
    public static function workerKinds(): array
    {
        return ['regular' => [false], 'monopolistic' => [true]];
    }

    /**
     * @param bool $isMonopolistic Whether the reporting worker is monopolistic
     * @throws EnvException When the connection cannot read its socket buffer size
     */
    #[DataProvider('workerKinds')]
    public function testExpectedReportAfterRosterRemovalLogsOnceAndConsumesTheWait(bool $isMonopolistic): void
    {
        $manager = new AgentStoppedReportTestAgentManagerDaemon();
        $agent = $this->createMock(AgentDaemonInterface::class);
        $agent->expects($this->never())->method('onStop');
        $manager->addAgent(self::AGENT_ID, $agent, self::WORKER_INDEX, $isMonopolistic);
        $client = $this->worker($manager, $isMonopolistic);

        $client->sendAgentStop(self::AGENT_ID);
        $manager->removeAgent(self::AGENT_ID);

        $this->assertTrue($client->hasPendingWrite());
        $this->assertTrue($client->awaitsStopReport(self::AGENT_ID));
        $this->assertFalse($manager->hasAgent(self::AGENT_ID));
        $this->assertSame('', $this->logContent());

        $this->receiveReport($client);

        $this->assertFalse($client->awaitsStopReport(self::AGENT_ID));
        $this->assertFalse($manager->hasAgent(self::AGENT_ID));
        $this->assertOneStopLine();
        $firstReport = $this->logContent();

        $this->receiveReport($client);

        $this->assertSame($firstReport, $this->logContent());
    }

    /**
     * @param bool $isMonopolistic Whether the reporting worker is monopolistic
     * @throws EnvException When the connection cannot read its socket buffer size
     */
    #[DataProvider('workerKinds')]
    public function testUnknownReportWithoutAWaitOrRosterDoesNotConfirmAStop(bool $isMonopolistic): void
    {
        $client = $this->worker(new AgentStoppedReportTestAgentManagerDaemon(), $isMonopolistic);

        $this->receiveReport($client);

        $this->assertFalse($client->awaitsStopReport(self::AGENT_ID));
        $this->assertSame('', $this->logContent());
    }

    /**
     * @param bool $isMonopolistic Whether the reporting worker is monopolistic
     * @throws EnvException When the connection cannot read its socket buffer size
     */
    #[DataProvider('workerKinds')]
    public function testUnrequestedReportWithRosterUsesTheManagersSingleStopLine(bool $isMonopolistic): void
    {
        $this->assertRosterReport($isMonopolistic, false);
    }

    /**
     * @param bool $isMonopolistic Whether the reporting worker is monopolistic
     * @throws EnvException When the connection cannot read its socket buffer size
     */
    #[DataProvider('workerKinds')]
    public function testExpectedReportWithRosterUsesTheManagersSingleStopLine(bool $isMonopolistic): void
    {
        $this->assertRosterReport($isMonopolistic, true);
    }

    /**
     * @param bool $isMonopolistic Whether the disconnected worker is monopolistic
     * @throws EnvException When the connection cannot read its socket buffer size
     * @throws HilosException When the worker connection cannot announce its close
     */
    #[DataProvider('workerKinds')]
    public function testClosingTheLinkWithoutAReportDoesNotConfirmAStop(bool $isMonopolistic): void
    {
        $manager = new AgentStoppedReportTestAgentManagerDaemon();
        $manager->addAgent(
            self::AGENT_ID,
            $this->createMock(AgentDaemonInterface::class),
            self::WORKER_INDEX,
            $isMonopolistic,
        );
        $client = $this->worker($manager, $isMonopolistic);
        $client->sendAgentStop(self::AGENT_ID);
        $manager->removeAgent(self::AGENT_ID);

        $client->close();

        $this->assertNull($client->getSocket());
        $this->assertSame('', $this->logContent());
    }

    /**
     * @param bool $isMonopolistic Whether the original channel belongs to a monopolistic worker
     */
    #[DataProvider('workerKinds')]
    public function testRealCloseNotifiesTheWiredDoorBeforeRosterRemovalAndRefusesTheCommand(bool $isMonopolistic): void
    {
        $previousAppEnv = getenv('APP_ENV');
        $previousRt = Hilos::$rt;
        $previousRouter = Hilos::$sr;
        $previousCluster = Hilos::$cluster;
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
        Hilos::$rt = null;
        Hilos::$cluster = null;
        try {
            $roster = new AgentStoppedReportTestAgentManagerDaemon();
            $client = $this->worker($roster, $isMonopolistic);
            $agent = $this->createStub(AgentDaemonInterface::class);
            $agent->method('hasWorkerClient')->willReturn(true);
            $agent->method('getWorkerClient')->willReturn($client);
            $roster->addAgent(HilosAgentType::HILOS_DAEMON_NODE, $agent, self::WORKER_INDEX, $isMonopolistic);
            new ReflectionProperty(AgentManagerDaemon::class, 'startedAgentIds')->setValue($roster, [HilosAgentType::HILOS_DAEMON_NODE => true]);
            $manager = new StopReportDaemonManager($roster);
            $server = new ReflectionProperty(WorkerClient::class, 'workerServer')->getValue($client);
            $manager->registerServer($server);
            self::assertSame($manager, $server->daemonNodeReportTestDoor(), 'Registration wires the actual master');
            $commands = new StopReportCommandCapture();
            $manager->registerServer($commands);
            $owner = new ReflectionProperty(DaemonManager::class, 'daemonNodeReportTestRequests')->getValue($manager);
            $owner->request(new CommandRequestDTO('close-report', CliCommands::DAEMON_TEST_NODE_REPORT, []), 100.0, 100.0);
            $owner->dispatchDue(100.0, 100.1);
            $owner->request(new CommandRequestDTO('close-roster', CliCommands::DAEMON_TEST_NODE_REPORT, []), 101.0, 101.0);
            $door = $this->createMock(DaemonNodeReportTestDoor::class);
            $door->expects(self::once())->method('nodeReportWorkerGone')->with($this->identicalTo($client))
                ->willReturnCallback(static function (WorkerClient $worker) use ($roster, $manager): void {
                    self::assertTrue($roster->hasAgent(HilosAgentType::HILOS_DAEMON_NODE), 'Close notifies before roster cleanup');
                    $manager->nodeReportWorkerGone($worker);
                });
            $server->setDaemonNodeReportTestDoor($door);
            $client->close();
            $client->close();
            self::assertFalse($roster->hasAgent(HilosAgentType::HILOS_DAEMON_NODE));
            self::assertNull($client->getSocket());
            self::assertSame(['close-report', 'close-roster'], array_column($commands->replies, 'correlationId'));
            foreach ($commands->replies as $reply) {
                self::assertSame('The worker running the Daemon node agent went down before it reported.',
                    $reply->payload[CommandConstants::FIELD_MESSAGE]);
            }
            $owner->agentStopped();
            $owner->tick(200.0);
            self::assertCount(2, $commands->replies);
        } finally {
            Hilos::$rt = $previousRt;
            Hilos::$sr = $previousRouter;
            Hilos::$cluster = $previousCluster;
            putenv($previousAppEnv === false ? 'APP_ENV' : 'APP_ENV=' . $previousAppEnv);
        }
    }

    /**
     * Supply the socket buffer catalog and capture the real logger with explicit INFO prefixes.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor();
        $this->previousLogFile = new ReflectionProperty(Logger::class, 'logFile')->getValue();
        $this->previousShowLogLevel = new ReflectionProperty(Logger::class, 'showLogLevel')->getValue();
        $this->previousWriteLevel = Logger::writeLevel();
        $logFile = tempnam(sys_get_temp_dir(), 'hilos-stop-report-');
        $this->assertNotFalse($logFile);
        $this->logFile = $logFile;
        Logger::setLogFile($this->logFile);
        Logger::setShowLogLevel(true);
        Logger::setWriteLevel(LogLevel::Info);
    }

    /**
     * Close local sockets and restore the logger used by the rest of the suite.
     *
     * @throws HilosException When a worker connection cannot announce its close
     */
    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        foreach ($this->peers as $peer) {
            socket_close($peer);
        }
        if ($this->previousLogFile === null) {
            Logger::resetLogFile();
        } else {
            Logger::setLogFile($this->previousLogFile);
        }
        Logger::setShowLogLevel($this->previousShowLogLevel);
        Logger::setWriteLevel($this->previousWriteLevel);
        unlink($this->logFile);
        Hilos::$env = $this->previousEnv;
        parent::tearDown();
    }

    /**
     * @param bool $isMonopolistic Worker kind recorded in the roster
     * @param bool $requestStop Whether the connection already awaits the report
     * @throws EnvException When the connection cannot read its socket buffer size
     */
    private function assertRosterReport(bool $isMonopolistic, bool $requestStop): void
    {
        $manager = new AgentStoppedReportTestAgentManagerDaemon();
        $agent = $this->createMock(AgentDaemonInterface::class);
        $agent->expects($this->once())->method('onStop');
        $manager->addAgent(self::AGENT_ID, $agent, self::WORKER_INDEX, $isMonopolistic);
        $client = $this->worker($manager, $isMonopolistic);
        if ($requestStop) {
            $client->sendAgentStop(self::AGENT_ID);
        }

        $this->receiveReport($client);

        $this->assertFalse($manager->hasAgent(self::AGENT_ID));
        $this->assertFalse($client->awaitsStopReport(self::AGENT_ID));
        $this->assertOneStopLine();
    }

    /**
     * @param AgentManagerDaemon $manager Real roster receiving the worker's report
     * @param bool $isMonopolistic Kind of the connected worker
     * @return WorkerClient Connection over a local socket, without starting a worker process
     * @throws EnvException When the connection cannot read its socket buffer size
     */
    private function worker(AgentManagerDaemon $manager, bool $isMonopolistic): WorkerClient
    {
        $sockets = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets));
        $this->peers[] = $sockets[1];
        $server = new ReflectionClass(AgentStoppedReportTestWorkerServer::class)->newInstanceWithoutConstructor();
        $client = new WorkerClient($sockets[0], $manager, $server);
        $client->setWorkerIndex(self::WORKER_INDEX);
        $client->setIsMonopolistic($isMonopolistic);
        $this->clients[] = $client;

        return $client;
    }

    /**
     * @param WorkerClient $client Connection that receives and decodes the worker frame
     */
    private function receiveReport(WorkerClient $client): void
    {
        new ReflectionMethod(WorkerClient::class, 'processMessage')->invoke(
            $client,
            new WorkerAgentStoppedDTO(self::AGENT_ID)->toJson(),
        );
    }

    /**
     * Require exactly one INFO record, allowing only its timestamp to vary.
     */
    private function assertOneStopLine(): void
    {
        $this->assertMatchesRegularExpression(
            "/\A\[[^\r\n]+\] \[INFO\] Agent 'unit_stop_report' stopped on worker #23\n\z/",
            $this->logContent(),
        );
    }

    /**
     * @return string Bytes written by Logger to this case's file
     */
    private function logContent(): string
    {
        $content = file_get_contents($this->logFile);
        $this->assertNotFalse($content);

        return $content;
    }
}

/**
 * Concrete manager retaining the production roster and stop-report behavior.
 */
final class AgentStoppedReportTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that these receive-only tests never start
     * @param ?string $agentIndex Instance index that these tests never use
     * @return AgentDaemonInterface No daemon is created on the report path
     * @throws LogicException When the receive-only fixture unexpectedly tries to start an agent
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new LogicException('Stop report tests must not create agents');
    }
}

/**
 * Owning server without a listener or worker process.
 */
final class AgentStoppedReportTestWorkerServer extends WorkerServer
{
    /**
     * This receive-only fixture never starts a listener.
     */
    protected function onStart(): void
    {
    }

    /**
     * @param string $agentType Already linked local test receiver
     * @param ?string $agentIndex Unused singleton index
     * @param DaemonAgentMessageDTO $messageDto Frame queued without starting a worker process
     */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, DaemonAgentMessageDTO $messageDto): void
    {
    }
}

/** Actual master wiring around the socket fixture's production agent roster. */
final class StopReportDaemonManager extends DaemonManager
{
    /** @param AgentManagerDaemon $roster Worker fixture's actual roster */
    public function __construct(private readonly AgentManagerDaemon $roster)
    {
        parent::__construct();
    }

    /** @return AgentManagerDaemon Roster whose channel will close */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return $this->roster;
    }

    /** @return SignalRouter Local queue without project routes */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }
}

/** Captures the command outcome without opening another socket. */
final class StopReportCommandCapture extends CommandServer
{
    /** @var list<CommandReplyDTO> */
    public array $replies = [];

    /** This fixture opens no listener. */
    public function __construct()
    {
    }

    /**
     * @param string $correlationId Held console correlation
     * @param CommandReplyDTO $reply Outcome to the original console
     */
    public function deliver(string $correlationId, CommandReplyDTO $reply): void
    {
        $this->replies[] = $reply;
    }
}
