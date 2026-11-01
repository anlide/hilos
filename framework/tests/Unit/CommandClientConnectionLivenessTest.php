<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Daemon\ConnectionLivenessTestDoor;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\Client\CommandClient;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\WebSocket\Liveness\WebSocketLivenessMoment;
use Hilos\Utils\Helpers\JsonHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * Tests the master answering test:connection:liveness itself.
 *
 * The sockets and their clocks live in the master, so the command channel reaches them through a
 * door the master implements. What is pinned here is the channel's half: the request is read and
 * refused in the words the caller is promised, the door is asked with the hash a socket holds and
 * the moment named on the line, the count it answers comes back as it is - zero included - and a
 * failing tick is answered as an error instead of thrown into the master loop.
 */
final class CommandClientConnectionLivenessTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    /** @var list<Socket> Sockets kept alive for the client under test */
    private array $sockets = [];

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        putenv('SOCKET_READ_BUFFER_SIZE=65536');
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        $this->sockets = [];

        Hilos::$env = $this->previousEnv;
        putenv('SOCKET_READ_BUFFER_SIZE');

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: WebSocketLivenessMoment}> Moment named on the line and the case it reads as
     */
    public static function moments(): array
    {
        return [
            'probably offline' => ['probably-offline', WebSocketLivenessMoment::ProbablyOffline],
            'close' => ['close', WebSocketLivenessMoment::Close],
            'check' => ['check', WebSocketLivenessMoment::Check],
        ];
    }

    /**
     * @param string $momentName Moment as the request names it
     * @param WebSocketLivenessMoment $moment Case the door has to be asked with
     */
    #[DataProvider('moments')]
    public function testTheDoorIsAskedWithTheTokenHashAndTheMomentAndItsCountIsTheReply(
        string $momentName,
        WebSocketLivenessMoment $moment,
    ): void {
        $door = new RecordingConnectionLivenessTestDoor(2);

        $reply = $this->ask([
            CommandConstants::FIELD_SESSION_TOKEN => 'browser-token',
            CommandConstants::FIELD_MOMENT => $momentName,
        ], $door);

        $this->assertSame(CommandConstants::STATUS_OK, $reply[CommandConstants::FIELD_STATUS] ?? null);
        $this->assertSame([CommandConstants::FIELD_SOCKETS => 2], $reply[CommandConstants::FIELD_PAYLOAD] ?? null);
        $this->assertSame([[ProtectedModeRuntime::hashSessionToken('browser-token'), $moment]], $door->calls);
    }

    public function testNoLiveSocketOfTheBrowserIsAnAnswerAndNotARefusal(): void
    {
        $reply = $this->ask([
            CommandConstants::FIELD_SESSION_TOKEN => 'browser-token',
            CommandConstants::FIELD_MOMENT => 'probably-offline',
        ], new RecordingConnectionLivenessTestDoor(0));

        $this->assertSame(CommandConstants::STATUS_OK, $reply[CommandConstants::FIELD_STATUS] ?? null);
        $this->assertSame([CommandConstants::FIELD_SOCKETS => 0], $reply[CommandConstants::FIELD_PAYLOAD] ?? null);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}> Requests naming no usable session token
     */
    public static function missingSessionTokens(): array
    {
        return [
            'absent' => [[CommandConstants::FIELD_MOMENT => 'close']],
            'empty' => [[CommandConstants::FIELD_SESSION_TOKEN => '', CommandConstants::FIELD_MOMENT => 'close']],
            'not text' => [[CommandConstants::FIELD_SESSION_TOKEN => 42, CommandConstants::FIELD_MOMENT => 'close']],
        ];
    }

    /**
     * @param array<string, mixed> $payload Request payload naming no usable session token
     */
    #[DataProvider('missingSessionTokens')]
    public function testARequestWithoutASessionTokenIsRefusedAndNoSocketIsTouched(array $payload): void
    {
        $door = new RecordingConnectionLivenessTestDoor(1);

        $reply = $this->ask($payload, $door);

        $this->assertSame(CommandConstants::STATUS_ERROR, $reply[CommandConstants::FIELD_STATUS] ?? null);
        $this->assertSame(
            'Missing sessionToken',
            $reply[CommandConstants::FIELD_PAYLOAD][CommandConstants::FIELD_MESSAGE] ?? null,
        );
        $this->assertSame([], $door->calls);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}> Requests naming no moment the sockets know
     */
    public static function unknownMoments(): array
    {
        return [
            'absent' => [[CommandConstants::FIELD_SESSION_TOKEN => 'browser-token']],
            'unknown name' => [[CommandConstants::FIELD_SESSION_TOKEN => 'browser-token', CommandConstants::FIELD_MOMENT => 'offline']],
            'case name' => [[CommandConstants::FIELD_SESSION_TOKEN => 'browser-token', CommandConstants::FIELD_MOMENT => 'Close']],
            'not text' => [[CommandConstants::FIELD_SESSION_TOKEN => 'browser-token', CommandConstants::FIELD_MOMENT => 1]],
        ];
    }

    /**
     * @param array<string, mixed> $payload Request payload naming no moment the sockets know
     */
    #[DataProvider('unknownMoments')]
    public function testAnUnknownMomentIsRefusedAndNoSocketIsTouched(array $payload): void
    {
        $door = new RecordingConnectionLivenessTestDoor(1);

        $reply = $this->ask($payload, $door);

        $this->assertSame(CommandConstants::STATUS_ERROR, $reply[CommandConstants::FIELD_STATUS] ?? null);
        $this->assertSame(
            'moment must be probably-offline, close or check',
            $reply[CommandConstants::FIELD_PAYLOAD][CommandConstants::FIELD_MESSAGE] ?? null,
        );
        $this->assertSame([], $door->calls);
    }

    public function testADaemonWithoutTheDoorRefusesInsteadOfThrowing(): void
    {
        $reply = $this->ask([
            CommandConstants::FIELD_SESSION_TOKEN => 'browser-token',
            CommandConstants::FIELD_MOMENT => 'check',
        ], null);

        $this->assertSame(CommandConstants::STATUS_ERROR, $reply[CommandConstants::FIELD_STATUS] ?? null);
        $this->assertSame(
            'This daemon has no connection liveness door',
            $reply[CommandConstants::FIELD_PAYLOAD][CommandConstants::FIELD_MESSAGE] ?? null,
        );
    }

    public function testAFailingTickIsAnsweredAsAnErrorInsteadOfThrownIntoTheLoop(): void
    {
        $reply = $this->ask([
            CommandConstants::FIELD_SESSION_TOKEN => 'browser-token',
            CommandConstants::FIELD_MOMENT => 'probably-offline',
        ], new ThrowingConnectionLivenessTestDoor());

        $this->assertSame(CommandConstants::STATUS_ERROR, $reply[CommandConstants::FIELD_STATUS] ?? null);
        $this->assertSame(
            ThrowingConnectionLivenessTestDoor::MESSAGE,
            $reply[CommandConstants::FIELD_PAYLOAD][CommandConstants::FIELD_MESSAGE] ?? null,
        );
    }

    /**
     * Sends one test:connection:liveness request to a fresh command client and reads its reply.
     *
     * @param array<string, mixed> $payload Request payload
     * @param ?ConnectionLivenessTestDoor $door Door the server is wired with, or null for none
     * @return array<string, mixed> Decoded reply
     */
    private function ask(array $payload, ?ConnectionLivenessTestDoor $door): array
    {
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->sockets[] = $pair[0];
        $this->sockets[] = $pair[1];

        $server = new CommandServer('127.0.0.1', 0);
        if ($door !== null) {
            $server->setConnectionLivenessTestDoor($door);
        }

        $client = new ConnectionLivenessTestCommandClient($pair[0], $server);
        $client->feed([
            CommandConstants::FIELD_CORRELATION_ID => 'corr-liveness',
            CommandConstants::FIELD_COMMAND => CliCommands::CONNECTION_TEST_LIVENESS,
            CommandConstants::FIELD_PAYLOAD => $payload,
        ]);

        return $client->lastReply();
    }
}

/**
 * Door that records what it was asked and answers a fixed count, as the master would for that many sockets.
 */
final class RecordingConnectionLivenessTestDoor implements ConnectionLivenessTestDoor
{
    /** @var list<array{0: string, 1: WebSocketLivenessMoment}> Session token hash and moment of each call */
    public array $calls = [];

    /** @param int $sockets Count to answer every call with */
    public function __construct(private readonly int $sockets)
    {
    }

    public function bringLivenessMomentToNow(string $sessionTokenHash, WebSocketLivenessMoment $moment): int
    {
        $this->calls[] = [$sessionTokenHash, $moment];

        return $this->sockets;
    }
}

/**
 * Door whose socket fails its tick, the way a socket with no accept key to name would.
 */
final class ThrowingConnectionLivenessTestDoor implements ConnectionLivenessTestDoor
{
    /** @var string Message of the failure the door raises */
    public const string MESSAGE = 'The socket has no accept key to name';

    public function bringLivenessMomentToNow(string $sessionTokenHash, WebSocketLivenessMoment $moment): int
    {
        throw new InvalidFormatException(self::MESSAGE);
    }
}

/**
 * Command client that takes a request straight from the test instead of the socket.
 */
final class ConnectionLivenessTestCommandClient extends CommandClient
{
    /**
     * Hands one request to the read-buffer parser as the CLI would send it.
     *
     * @param array<string, mixed> $request Request payload
     */
    public function feed(array $request): void
    {
        $this->readBuffer .= json_encode($request) . "\n";
        $this->processReadBuffer();
    }

    /**
     * Reads back the reply the parser queued for the CLI.
     *
     * @return array<string, mixed> Decoded reply, or an empty array when none was queued
     */
    public function lastReply(): array
    {
        $lines = array_filter(explode("\n", $this->writeBuffer), static fn(string $line): bool => $line !== '');
        $reply = JsonHelper::tryDecode((string) end($lines));

        return is_array($reply) ? $reply : [];
    }
}
