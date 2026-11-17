<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\Command;

use Closure;
use Hilos\Auth\OAuth\OAuthProviderTestCommands;
use Hilos\Constants\CliCommands;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\CommandClient;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Utils\LogLevel;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Socket;

/** Exercises the real IPC decoders and logger, keeping command contents in memory. */
final class CommandPayloadLoggingTest extends TestCase
{
    private const string SECRET = 'synthetic-oauth-secret-for-transport-regression';

    private ?EnvAccessor $previousEnv;
    private ?SignalRouter $previousRouter;
    private ?string $previousLogFile;
    private bool $previousShowLogLevel;
    private LogLevel $previousWriteLevel;
    private WorkerClient $master;
    private CommandClient $caller;
    private Socket $workerPeer;
    private Socket $commandPeer;

    /** Capture DEBUG at its normal stdout destination, without a log artifact. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = Hilos::$env;
        $this->previousRouter = Hilos::$sr;
        $this->previousLogFile = new ReflectionProperty(Logger::class, 'logFile')->getValue();
        $this->previousShowLogLevel = new ReflectionProperty(Logger::class, 'showLogLevel')->getValue();
        $this->previousWriteLevel = Logger::writeLevel();
        Hilos::$env = new EnvAccessor();
        Hilos::$sr = new SignalRouter();
        Logger::resetLogFile();
        Logger::setShowLogLevel(true);
        Logger::setWriteLevel(LogLevel::Debug);

        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $this->workerPeer = $pair[1];
        $manager = $this->getMockBuilder(AgentManagerDaemon::class)->onlyMethods(['createAgentDaemon'])->getMock();
        $this->master = new WorkerClient($pair[0], $manager, $this->createStub(WorkerServer::class));
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $this->commandPeer = $pair[1];
        $this->caller = new CommandClient($pair[0], $this->createStub(CommandServer::class));
    }

    /** Release local sockets and restore the suite's logger and facade. */
    protected function tearDown(): void
    {
        $this->master->close();
        $this->caller->close();
        socket_close($this->workerPeer);
        socket_close($this->commandPeer);
        if ($this->previousLogFile === null) {
            Logger::resetLogFile();
        } else {
            Logger::setLogFile($this->previousLogFile);
        }
        Logger::setShowLogLevel($this->previousShowLogLevel);
        Logger::setWriteLevel($this->previousWriteLevel);
        Hilos::$env = $this->previousEnv;
        Hilos::$sr = $this->previousRouter;
        parent::tearDown();
    }

    /** @return array<string, array{?string, bool}> Exact raw secret and row-presence cases */
    public static function rawStates(): array
    {
        return [
            'configured secret' => [self::SECRET, true],
            'empty secret' => ['', true],
            'null secret' => [null, true],
            'absent row' => [null, false],
        ];
    }

    /**
     * @param ?string $secret Exact stored value
     * @param bool $present Whether the provider row exists
     */
    #[DataProvider('rawStates')]
    public function testSnapshotReplyAndRestoreRequestKeepRawStateOutOfDebug(?string $secret, bool $present): void
    {
        $snapshot = [
            'providerKey' => 'raw-provider-marker',
            'row' => $present ? [
                'id' => 17,
                'clientId' => 'raw-client-marker',
                'clientSecret' => $secret,
                'scope' => 'raw-scope-marker',
                'createdAt' => 1700000000,
                'updatedAt' => 1700000001,
            ] : null,
        ];
        $output = $this->capture(function () use ($snapshot, $present): void {
            $this->receiveRequest(new CommandRequestDTO('snapshot-correlation', CliCommands::OAUTH_PROVIDER_TEST_SNAPSHOT, [
                'providerKey' => $snapshot['providerKey'],
            ]));
            $this->receiveReply(CommandReplyDTO::ok('snapshot-correlation', ['snapshot' => $snapshot]));
            $this->receiveRequest(new CommandRequestDTO('restore-correlation', CliCommands::OAUTH_PROVIDER_TEST_RESTORE, [
                'snapshot' => $snapshot,
                'currentId' => $present ? 17 : null,
            ]));
            $this->receiveReply(CommandReplyDTO::ok('restore-correlation', ['restored' => true]));
        });

        $this->assertSafeDiagnostics($output);
        self::assertStringContainsString('[DEBUG] Received message from worker', $output);
        self::assertStringContainsString(WorkerAgentMessageDTO::MESSAGE_TYPE, $output);
    }

    /** A malformed command still carries arbitrary raw fields until the owner refuses it. */
    public function testOwnerRefusalDoesNotExposeMalformedRestorePayload(): void
    {
        $previousAppEnv = getenv('APP_ENV');
        putenv('APP_ENV=test');
        try {
            $output = $this->capture(function (): void {
                $request = $this->receiveRequest(new CommandRequestDTO('refusal-correlation', CliCommands::OAUTH_PROVIDER_TEST_RESTORE, [
                    'snapshot' => ['unexpected' => self::SECRET],
                    'currentId' => 'raw-current-id-marker',
                ]));
                $reply = OAuthProviderTestCommands::handle($request, static function (): void {
                    self::fail('A refused restoration must not announce a change.');
                });
                self::assertFalse($reply->isOk());
                self::assertSame('refusal-correlation', $reply->correlationId);
                $this->receiveReply($reply);
            });
        } finally {
            putenv($previousAppEnv === false ? 'APP_ENV' : 'APP_ENV=' . $previousAppEnv);
        }

        $this->assertSafeDiagnostics($output);
        self::assertFalse(str_contains($output, 'raw-current-id-marker'), 'A malformed field reached the journal.');
    }

    /** @return array<string, array{string}> Frames refused before command dispatch in either direction */
    public static function malformedFrames(): array
    {
        return [
            'invalid JSON' => ['{"type":"agent_message","raw":"' . self::SECRET . '",}'],
            'missing signal' => ['{"type":"agent_message","raw":"' . self::SECRET . '"}'],
            'daemon frame missing signal' => ['{"type":"daemon_agent_message","agentId":"test","raw":"' . self::SECRET . '"}'],
        ];
    }

    /** @param string $json Malformed input with an arbitrary secret-bearing field */
    #[DataProvider('malformedFrames')]
    public function testMalformedTransportFramesNeverEnterDebug(string $json): void
    {
        $output = $this->capture(function () use ($json): void {
            try {
                new ReflectionMethod(WorkerClient::class, 'processMessage')->invoke($this->master, $json);
                self::fail('Malformed worker input must be refused.');
            } catch (InvalidFormatException) {
            }
            $worker = new WorkerDaemonClient();
            new ReflectionProperty(WorkerDaemonClient::class, 'readBuffer')->setValue($worker, $json . "\n");
            try {
                new ReflectionMethod(WorkerDaemonClient::class, 'processReadBuffer')->invoke($worker);
                self::fail('Malformed daemon input must be refused.');
            } catch (InvalidFormatException) {
            }
            self::assertNull($worker->getNextMessage());
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
        });

        $this->assertSafeDiagnostics($output);
    }

    /**
     * @param CommandRequestDTO $request Original channel request
     * @return CommandRequestDTO Raw request received by the worker
     */
    private function receiveRequest(CommandRequestDTO $request): CommandRequestDTO
    {
        $request = CommandRequestDTO::fromJson($request->toJson());
        $frame = new DaemonAgentMessageDTO('test-owner', new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::COMMAND_REQUEST),
            new SignalName($request->command),
            $request,
        ));
        $worker = new WorkerDaemonClient();
        new ReflectionProperty(WorkerDaemonClient::class, 'readBuffer')->setValue($worker, $frame->toJson() . "\n");
        new ReflectionMethod(WorkerDaemonClient::class, 'processReadBuffer')->invoke($worker);
        $received = $worker->getNextMessage();
        self::assertInstanceOf(DaemonAgentMessageDTO::class, $received);
        self::assertInstanceOf(CommandRequestDTO::class, $received->signal->data);
        self::assertTrue($request->toArray() === $received->signal->data->toArray(), 'The closed request changed in transport.');
        self::assertNull($worker->getNextMessage());

        return $received->signal->data;
    }

    /** @param CommandReplyDTO $reply Raw reply to deliver all the way to the command socket */
    private function receiveReply(CommandReplyDTO $reply): void
    {
        $sender = new WorkerDaemonClient();
        $sender->send(new WorkerAgentMessageDTO('test-owner', new SignalDTO(
            new SignalSource(SignalSource::AGENT, 'test-owner'),
            new SignalType(SignalTypeConstants::COMMAND_REPLY),
            new SignalName($reply->correlationId),
            $reply,
        )));
        $json = new ReflectionProperty(WorkerDaemonClient::class, 'writeBuffer')->getValue($sender);
        new ReflectionMethod(WorkerClient::class, 'processMessage')->invoke($this->master, $json);
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame($reply->correlationId, $signal->signalName->getName());
        self::assertInstanceOf(CommandReplyDTO::class, $signal->data);
        $this->caller->writeReply($signal->data);
        $this->caller->write();
        $bytes = socket_read($this->commandPeer, 65536, PHP_NORMAL_READ);
        self::assertIsString($bytes);
        $received = CommandReplyDTO::fromJson($bytes);
        self::assertTrue($reply->toArray() === $received->toArray(), 'The closed reply changed in transport.');
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * @param Closure():void $exchange Synchronous transport work
     * @return string Actual Logger stdout at DEBUG
     */
    private function capture(Closure $exchange): string
    {
        ob_start();
        try {
            $exchange();
            $output = ob_get_contents();
            self::assertIsString($output);

            return $output;
        } finally {
            ob_end_clean();
        }
    }

    /** @param string $output Captured logger output, never included in an assertion failure */
    private function assertSafeDiagnostics(string $output): void
    {
        self::assertFalse(str_contains($output, self::SECRET), 'A raw secret reached the journal.');
        foreach (['raw-provider-marker', 'raw-client-marker', 'raw-scope-marker', 'clientSecret', '"payload"', '"snapshot"'] as $raw) {
            self::assertFalse(str_contains($output, $raw), 'Raw command data reached the journal.');
        }
        self::assertMatchesRegularExpression('/\[DEBUG\] Parsing worker DTO \[bytes=\d+\]/', $output);
    }
}
