<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\CLI\Commands\ClusterTestUploadPoolCommand;
use Hilos\Core\CLI\Commands\CommandExecutionSite;
use Hilos\Core\CLI\Commands\DatabaseFreeCommand;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Exception\LogicException;
use Hilos\Environment\EnvAccessor;
use Hilos\Files\Upload\DTO\UploadMemoryTestRequest;
use Hilos\Files\Upload\UploadMemoryMaster;
use Hilos\Hilos;
use Hilos\Socket\Client\CommandClient;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Server\CommandServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Socket;

/** The real command boundary answers from the owning master's production ledger. */
final class CommandClientUploadPoolTest extends TestCase
{
    private ?EnvAccessor $previousEnv;
    private mixed $previousCluster;
    private ?string $previousAppEnv;
    private DaemonManager $manager;
    private CommandServer $server;

    /** @var list<Socket> Test sockets */
    private array $sockets = [];

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousCluster = Hilos::$cluster;
        $appEnv = getenv('APP_ENV');
        $this->previousAppEnv = $appEnv === false ? null : $appEnv;
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = null;
        $this->manager = new ReflectionClass(UploadPoolTestManager::class)->newInstanceWithoutConstructor();
        $this->server = new CommandServer('127.0.0.1', 0);
        $this->server->setUploadMemoryTestDoor($this->manager);
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        putenv($this->previousAppEnv === null ? 'APP_ENV' : 'APP_ENV=' . $this->previousAppEnv);
    }

    public function testInspectAndLimitReadExactlyTheSameMasterPoolWithoutTheProductFeature(): void
    {
        $reply = $this->ask(['operation' => 'inspect']);
        self::assertTrue($reply->isOk());
        self::assertSame(['limitBytes' => 0, 'usedBytes' => 0, 'waitingUploads' => 0, 'pendingPeerReservations' => 0], $reply->payload);
        $reply = $this->ask(['operation' => 'limit', 'memoryPoolBytes' => 7]);
        self::assertTrue($reply->isOk());
        self::assertSame(['limitBytes' => 7, 'usedBytes' => 0, 'waitingUploads' => 0, 'pendingPeerReservations' => 0], $reply->payload);
        $master = new ReflectionProperty(DaemonManager::class, 'uploadMemory')->getValue($this->manager);
        self::assertInstanceOf(UploadMemoryMaster::class, $master);
        self::assertSame($master->inspect(), $reply->payload);
        $master->enqueueTest('standalone', 'test-probe', null, 5, static function (): void {});
        self::assertSame(5, $this->ask(['operation' => 'inspect'])->payload['usedBytes']);
        $master->afterDispatch();
        self::assertSame(0, $this->ask(['operation' => 'inspect'])->payload['usedBytes']);
        $reply = $this->ask(['operation' => 'limit', 'memoryPoolBytes' => 0]);
        self::assertSame(0, $reply->payload['limitBytes']);
    }

    /** @return iterable<string, array{array<string, mixed>}> Malformed command requests */
    public static function invalidRequests(): iterable
    {
        yield 'missing operation' => [[]];
        yield 'bad operation' => [['operation' => 'reset']];
        yield 'mistyped operation' => [['operation' => 1]];
        yield 'limit missing' => [['operation' => 'limit']];
        foreach (['7', 1.5, false, [], -1] as $bad) {
            yield 'limit ' . get_debug_type($bad) => [['operation' => 'limit', 'memoryPoolBytes' => $bad]];
        }
        yield 'enqueue missing' => [['operation' => 'enqueue']];
        foreach (['7', 1.5, false, [], 0, -1, 65537] as $bad) {
            yield 'bytes ' . get_debug_type($bad) . ':' . json_encode($bad) => [[
                'operation' => 'enqueue', 'targetNodeId' => 'B', 'agentType' => 'probe', 'bytes' => $bad,
            ]];
        }
        yield 'unknown agent' => [['operation' => 'enqueue', 'targetNodeId' => 'missing', 'agentType' => 'missing', 'bytes' => 1]];
        yield 'node type' => [['operation' => 'enqueue', 'targetNodeId' => 1, 'agentType' => 'probe', 'bytes' => 1]];
        yield 'index type' => [['operation' => 'enqueue', 'targetNodeId' => 'B', 'agentType' => 'probe', 'agentIndex' => false, 'bytes' => 1]];
    }

    /** @param array<string, mixed> $payload Invalid test request */
    #[DataProvider('invalidRequests')]
    public function testInvalidRequestsProduceExactlyOneRefusalWithoutChargingBytes(array $payload): void
    {
        $reply = $this->ask($payload);
        self::assertFalse($reply->isOk());
        self::assertSame('test-correlation', $reply->correlationId);
        self::assertSame(0, $this->ask(['operation' => 'inspect'])->payload['usedBytes']);
    }

    public function testProductionGateRefusesBeforeTheMasterPoolIsInitialized(): void
    {
        putenv('APP_ENV=prod');
        $reply = $this->ask(['operation' => 'limit', 'memoryPoolBytes' => 7]);
        self::assertFalse($reply->isOk());
        self::assertNull(new ReflectionProperty(DaemonManager::class, 'uploadMemory')->getValue($this->manager));
    }

    public function testCliRegistryCarriesBothLatchesSiteOptionsAndDatabaseFreedom(): void
    {
        $cli = new CliManager([]);
        self::assertSame(ClusterTestUploadPoolCommand::class, $cli->commandClasses()[CliCommands::CLUSTER_TEST_UPLOAD_POOL]);
        self::assertSame(CommandExecutionSite::DAEMON, $cli->executions()[CliCommands::CLUSTER_TEST_UPLOAD_POOL]->site);
        self::assertTrue(is_subclass_of(ClusterTestUploadPoolCommand::class, TestOnlyCommand::class));
        self::assertInstanceOf(DatabaseFreeCommand::class, new ClusterTestUploadPoolCommand());
        self::assertFalse($cli->requiresDatabase(CliCommands::CLUSTER_TEST_UPLOAD_POOL));
        self::assertSame(['memory-pool-bytes', 'target-node-id', 'agent-type', 'agent-index', 'bytes'], ClusterTestUploadPoolCommand::OPTIONS);
        self::assertSame(['operation' => 'enqueue', 'targetNodeId' => 'B', 'agentType' => 'probe', 'bytes' => 65536],
            UploadMemoryTestRequest::fromArray([
                'operation' => 'enqueue', 'targetNodeId' => 'B', 'agentType' => 'probe', 'bytes' => 65536,
            ])->toArray());
    }

    /**
     * @param array<string, mixed> $payload Test command boundary
     * @return CommandReplyDTO Exactly one decoded response
     */
    private function ask(array $payload): CommandReplyDTO
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        array_push($this->sockets, ...$pair);
        $client = new UploadPoolCommandClient($pair[0], $this->server);
        $wire = $client->exchange(new CommandRequestDTO('test-correlation', CommandConstants::COMMAND_TEST_UPLOAD_POOL, $payload));
        self::assertCount(1, array_filter(explode("\n", $wire)));

        return CommandReplyDTO::fromJson(trim($wire));
    }
}

final class UploadPoolCommandClient extends CommandClient
{
    /**
     * @param CommandRequestDTO $request Incoming command line
     * @return string Response frames
     */
    public function exchange(CommandRequestDTO $request): string
    {
        $this->readBuffer = $request->toJson() . "\n";
        $this->processReadBuffer();

        return $this->writeBuffer;
    }
}

final class UploadPoolTestManager extends DaemonManager
{
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        throw new LogicException('No agent roster needed for command boundary checks');
    }
}
