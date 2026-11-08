<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Daemon\AbandonedCommandSink;
use Hilos\Core\Daemon\AgentRestartDoor;
use Hilos\Core\Daemon\CronFireTestDoor;
use Hilos\Core\Daemon\DaemonNodeReportTestDoor;
use Hilos\Core\Daemon\TestOrmForgetDoor;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\CommandClient;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Server\CommandServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Socket;

/** Native due-now deadlines exercise the guarded client portion without waiting for expiry. */
final class CommandClientDeadlineTest extends TestCase
{
    private EventLoop $loop;
    private ?EnvAccessor $previousEnv;
    private ?SignalRouter $previousRouter;
    private ?ClusterContext $previousCluster;
    private string|false $previousAppEnv;
    private string|false $previousReadSize;
    /** @var list<CommandClient> */
    private array $clients = [];
    /** @var list<Socket> */
    private array $sockets = [];

    /** @return iterable<string, array{string, ?string, ?string, ?string}> All held entrances */
    public static function doors(): iterable
    {
        yield 'agent command' => ['fixture:hold', null, null, null];
        yield 'ORM forget' => [CliCommands::ORM_TEST_FORGET, TestOrmForgetDoor::class, 'setTestOrmForgetDoor', 'requestOrmForget'];
        yield 'node report' => [CliCommands::DAEMON_TEST_NODE_REPORT, DaemonNodeReportTestDoor::class,
            'setDaemonNodeReportTestDoor', 'requestNodeReport'];
        yield 'cron fire' => [CliCommands::CRON_TEST_FIRE, CronFireTestDoor::class, 'setCronFireTestDoor', 'requestCronFire'];
        yield 'cron pause' => [CliCommands::CRON_TEST_PAUSE, CronFireTestDoor::class, 'setCronFireTestDoor', 'requestCronSchedule'];
        yield 'cron cancel pause' => [CliCommands::CRON_TEST_CANCEL_PAUSE, CronFireTestDoor::class,
            'setCronFireTestDoor', 'requestCronSchedule'];
        yield 'agent restart' => [CliCommands::AGENT_RESTART, AgentRestartDoor::class, 'setAgentRestartDoor', 'requestAgentRestart'];
    }

    /**
     * @param string $command Held command
     * @param ?class-string $doorClass Special master door, absent for ordinary agent routing
     * @param ?string $setter Server door binding
     * @param ?string $method Door entrance
     */
    #[DataProvider('doors')]
    public function testEveryHoldArmsTheOriginalChannelWindow(string $command, ?string $doorClass, ?string $setter, ?string $method): void
    {
        [$client, $server, $peer] = $this->client();
        $sink = $this->createMock(AbandonedCommandSink::class);
        $sink->expects(self::once())->method('onCommandAbandoned')->with('held');
        $server->setAbandonedCommandSink($sink);
        if ($doorClass !== null) {
            $door = $this->createMock($doorClass);
            $door->expects(self::once())->method($method)->willReturnCallback(function () use ($client): void {
                self::assertInstanceOf(EventLoopTimer::class, $this->timer($client));
                self::assertSame($this->deadline($client), $this->timer($client)->getDeadline());
            });
            $server->$setter($door);
        }
        $before = hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
        $this->send($peer, new CommandRequestDTO('held', $command, [
            CommandConstants::FIELD_AGENT_ID => null,
            CommandConstants::FIELD_RULE_NAME => 'fixture-rule',
        ]));
        $this->poll();
        $deadline = $this->deadline($client);
        self::assertGreaterThanOrEqual($before + CommandChannelWindows::CHANNEL_HELD_SECONDS, $deadline);
        self::assertSame($deadline, $this->timer($client)->getDeadline());
        self::assertFalse($this->loop->hasReadyWork(), 'A future hold is not runnable');
        $this->sendBytes($peer, '{');
        $this->poll();
        self::assertSame($deadline, $this->deadline($client), 'Unrelated input does not renew a hold');
        self::assertSame($deadline, $this->timer($client)->getDeadline());
        $this->makeDue($client);
        $this->poll();
        $reply = $this->replies($peer);
        self::assertCount(1, $reply);
        self::assertSame(['message' => 'Command timed out'], $reply[0]->payload);
        self::assertNull($this->timer($client)->getDeadline());
        self::assertNull($this->deadline($client));
        self::assertFalse($this->loop->hasReadyWork());
    }

    public function testExpiryReleasesTheBufferedPipelineAndAbandonsExactlyOnce(): void
    {
        [$client, $server, $peer] = $this->client();
        $sink = $this->createMock(AbandonedCommandSink::class);
        $sink->expects(self::once())->method('onCommandAbandoned')->with('first');
        $server->setAbandonedCommandSink($sink);
        $this->send($peer, new CommandRequestDTO('first', 'fixture:hold'));
        $this->send($peer, new CommandRequestDTO('ping', CommandConstants::COMMAND_PING));
        $this->send($peer, new CommandRequestDTO('second', 'fixture:hold'));
        $this->poll();
        self::assertSame([], $this->replies($peer));
        $this->makeDue($client);
        $this->poll();
        $replies = $this->replies($peer);
        self::assertSame(['first', 'ping'], array_column($replies, 'correlationId'));
        self::assertFalse($replies[0]->isOk());
        self::assertTrue($replies[1]->isOk());
        self::assertNotNull($this->deadline($client));
        self::assertFalse($this->loop->hasReadyWork());
        $server->deliver('second', CommandReplyDTO::ok('second'));
        $this->poll();
        self::assertSame(['second'], array_column($this->replies($peer), 'correlationId'));
        self::assertNull($this->timer($client)->getDeadline());
    }

    public function testRebindKeepsTheOriginalDeadlineAndClosesTheOldTimer(): void
    {
        [$client, $server, $peer] = $this->client();
        $this->send($peer, new CommandRequestDTO('held', 'fixture:hold'));
        $this->poll();
        $deadline = $this->deadline($client);
        $oldTimer = $this->timer($client);
        $other = new EventLoop();
        try {
            $this->bind($client, $other);
            self::assertSame($deadline, $this->timer($client)->getDeadline());
            self::assertNull($oldTimer->getDeadline());
            self::assertSame(0, $this->loop->registeredCount());
            $this->loop->tick();
            self::assertSame([], $this->replies($peer));
            $server->deliver('held', CommandReplyDTO::ok('held'));
            for ($pass = 0; $pass < 8; ++$pass) {
                $other->tick();
            }
            self::assertSame(['held'], array_column($this->replies($peer), 'correlationId'));
        } finally {
            $client->detachEventLoop();
            $other->close();
        }
    }

    public function testCancelingAnActivatedTimerCannotExpireTheNextHold(): void
    {
        [$client, $server, $peer] = $this->client();
        $this->send($peer, new CommandRequestDTO('first', 'fixture:hold'));
        $this->send($peer, new CommandRequestDTO('second', 'fixture:hold'));
        $this->poll();
        $this->makeDue($client);
        $this->loop->createReadyWork(static function () use ($server): void {
            $server->deliver('first', CommandReplyDTO::ok('first'));
        })->markReady();
        $this->poll();
        $replies = $this->replies($peer);
        self::assertSame(['first'], array_column($replies, 'correlationId'));
        self::assertTrue($replies[0]->isOk());
        self::assertNotNull($this->deadline($client));
        self::assertFalse($this->loop->hasReadyWork());
        $server->deliver('second', CommandReplyDTO::ok('second'));
        $this->poll();
        self::assertTrue($this->replies($peer)[0]->isOk());
    }

    public function testSynchronousDoorReplyReleasesTheHoldBeforeTheBufferedNextRequest(): void
    {
        [$client, $server, $peer] = $this->client();
        $door = $this->createMock(DaemonNodeReportTestDoor::class);
        $door->expects(self::once())->method('requestNodeReport')->willReturnCallback(
            static fn (CommandRequestDTO $request) => $server->deliver($request->correlationId, CommandReplyDTO::ok($request->correlationId)),
        );
        $server->setDaemonNodeReportTestDoor($door);
        $this->send($peer, new CommandRequestDTO('sync', CliCommands::DAEMON_TEST_NODE_REPORT));
        $this->send($peer, new CommandRequestDTO('ping', CommandConstants::COMMAND_PING));
        $this->poll();
        self::assertSame(['sync', 'ping'], array_column($this->replies($peer), 'correlationId'));
        self::assertNull($this->deadline($client));
        self::assertNull($this->timer($client)->getDeadline());
        self::assertFalse($this->loop->hasReadyWork());
    }

    public function testDisconnectCancelsAnActivatedDeadlineAndCannotReattach(): void
    {
        [$client, $server, $peer] = $this->client();
        $sink = $this->createMock(AbandonedCommandSink::class);
        $sink->expects(self::once())->method('onCommandAbandoned')->with('held');
        $server->setAbandonedCommandSink($sink);
        $this->send($peer, new CommandRequestDTO('held', 'fixture:hold'));
        $this->poll();
        $this->makeDue($client);
        $timer = $this->timer($client);
        $this->loop->createReadyWork($client->close(...))->markReady();
        $this->poll();
        $this->bind($client, $this->loop);
        self::assertNull($timer->getDeadline());
        self::assertNull($this->timer($client));
        self::assertSame(0, $this->loop->registeredCount());
        self::assertFalse($this->loop->hasReadyWork());
        $this->clients = [];
        $this->sockets = array_values(array_filter($this->sockets, static fn (Socket $socket): bool => $socket === $peer));
    }

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousRouter = Hilos::$sr;
        $this->previousCluster = Hilos::$cluster;
        $this->previousAppEnv = getenv('APP_ENV');
        $this->previousReadSize = getenv('SOCKET_READ_BUFFER_SIZE');
        putenv('APP_ENV=test');
        putenv('SOCKET_READ_BUFFER_SIZE=65536');
        Hilos::$env = new EnvAccessor();
        Hilos::$sr = new SignalRouter();
        Hilos::$cluster = null;
        $this->loop = new EventLoop();
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->detachEventLoop();
        }
        $this->loop->close();
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        Hilos::$env = $this->previousEnv;
        Hilos::$sr = $this->previousRouter;
        Hilos::$cluster = $this->previousCluster;
        putenv($this->previousAppEnv === false ? 'APP_ENV' : 'APP_ENV=' . $this->previousAppEnv);
        putenv($this->previousReadSize === false ? 'SOCKET_READ_BUFFER_SIZE' : 'SOCKET_READ_BUFFER_SIZE=' . $this->previousReadSize);
    }

    /** @return array{CommandClient, CommandServer, Socket} Actual client and held registry over a socket pair */
    private function client(): array
    {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        foreach ($pair as $socket) {
            self::assertTrue(socket_set_nonblock($socket));
            $this->sockets[] = $socket;
        }
        $server = new CommandServer('127.0.0.1', 0);
        $client = new CommandClient($pair[0], $server);
        $this->clients[] = $client;
        $this->bind($client, $this->loop);
        return [$client, $server, $pair[1]];
    }

    /** @param CommandClient $client Client portion to drive
     * @param EventLoop $loop Owning native base
     */
    private function bind(CommandClient $client, EventLoop $loop): void
    {
        $client->attachEventLoop($loop, static function () use ($client): void {
            $client->read();
            $client->onTick();
            $client->write();
        });
    }

    /** @param CommandClient $client Held client whose production deadline is brought to now */
    private function makeDue(CommandClient $client): void
    {
        $deadline = hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND - 1;
        new ReflectionProperty(CommandClient::class, 'heldSince')->setValue($client, $deadline - CommandChannelWindows::CHANNEL_HELD_SECONDS);
        new ReflectionProperty(CommandClient::class, 'heldDeadline')->setValue($client, $deadline);
        $this->timer($client)->armAt($deadline);
    }

    /** @param CommandClient $client Actual held client
     * @return ?float Original monotonic deadline
     */
    private function deadline(CommandClient $client): ?float
    {
        return new ReflectionProperty(CommandClient::class, 'heldDeadline')->getValue($client);
    }

    /** @param CommandClient $client Actual held client
     * @return ?EventLoopTimer Owner's native deadline handle
     */
    private function timer(CommandClient $client): ?EventLoopTimer
    {
        return new ReflectionProperty(CommandClient::class, 'heldTimer')->getValue($client);
    }

    /** Nonblocking portions only; none waits for a future deadline. */
    private function poll(): void
    {
        for ($pass = 0; $pass < 12; ++$pass) {
            $this->loop->tick();
        }
    }

    /** @param Socket $peer Sending end
     * @param CommandRequestDTO $request Input command
     */
    private function send(Socket $peer, CommandRequestDTO $request): void
    {
        $this->sendBytes($peer, $request->toJson() . "\n");
    }

    /** @param Socket $peer Sending end
     * @param string $bytes Input bytes
     */
    private function sendBytes(Socket $peer, string $bytes): void
    {
        self::assertSame(strlen($bytes), socket_write($peer, $bytes));
    }

    /** @param Socket $peer Reading end
     * @return list<CommandReplyDTO> Every available command reply
     */
    private function replies(Socket $peer): array
    {
        $bytes = '';
        while (($chunk = @socket_read($peer, 65_536)) !== false && $chunk !== '') {
            $bytes .= $chunk;
        }
        socket_clear_error($peer);
        return array_map(
            CommandReplyDTO::fromJson(...),
            array_values(array_filter(explode("\n", $bytes))),
        );
    }
}
