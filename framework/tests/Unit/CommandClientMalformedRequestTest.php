<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\CommandConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\CommandClient;
use Hilos\Socket\Server\CommandServer;
use Hilos\Utils\Helpers\JsonHelper;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * Tests the command socket refusing a request that names nothing (HIL-547).
 *
 * This boundary is the one place that can turn a malformed line into an answer:
 * left through, an empty command reaches new SignalName() and throws where nothing
 * on the read path catches it - AbstractServer::onTick() catches only
 * SocketException, and the daemon main loop catches nothing.
 *
 * Since HIL-562 the refusal arrives in two shapes and the answer has to be the
 * same for both: CommandRequestDTO::fromArray() refuses a line that omits a field,
 * while a line carrying the field empty hydrates and is refused by the check that
 * follows.
 */
final class CommandClientMalformedRequestTest extends TestCase
{
    /** @var ?EnvAccessor Previous env accessor to restore after the test */
    private ?EnvAccessor $previousEnv = null;

    /** @var list<Socket> Sockets kept alive for the client under test */
    private array $sockets = [];

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        Hilos::$env = new EnvAccessor();
        putenv('SOCKET_READ_BUFFER_SIZE=65536');
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            @socket_close($socket);
        }
        $this->sockets = [];

        Hilos::$env = $this->previousEnv;
        putenv('SOCKET_READ_BUFFER_SIZE');

        parent::tearDown();
    }

    public function testRequestWithoutACommandIsAnsweredWithAnError(): void
    {
        $client = $this->client();

        $client->feed([CommandConstants::FIELD_CORRELATION_ID => 'corr-1']);

        $this->assertSame(CommandConstants::STATUS_ERROR, $client->lastReply()[CommandConstants::FIELD_STATUS] ?? null);
        $this->assertSame('corr-1', $client->lastReply()[CommandConstants::FIELD_CORRELATION_ID] ?? null);
    }

    public function testRequestWithoutACorrelationIdIsAnsweredWithAnError(): void
    {
        $client = $this->client();

        $client->feed([CommandConstants::FIELD_COMMAND => CommandConstants::COMMAND_PING]);

        $this->assertSame(CommandConstants::STATUS_ERROR, $client->lastReply()[CommandConstants::FIELD_STATUS] ?? null);
    }

    public function testRequestNamingItsFieldsEmptyIsAnsweredWithAnError(): void
    {
        $client = $this->client();

        $client->feed([
            CommandConstants::FIELD_CORRELATION_ID => 'corr-3',
            CommandConstants::FIELD_COMMAND => '',
            CommandConstants::FIELD_PAYLOAD => [],
        ]);

        $this->assertSame(CommandConstants::STATUS_ERROR, $client->lastReply()[CommandConstants::FIELD_STATUS] ?? null);
        $this->assertSame('corr-3', $client->lastReply()[CommandConstants::FIELD_CORRELATION_ID] ?? null);
    }

    public function testRequestWithoutItsArgumentMapIsAnsweredRatherThanThrown(): void
    {
        $client = $this->client();

        $client->feed([
            CommandConstants::FIELD_CORRELATION_ID => 'corr-4',
            CommandConstants::FIELD_COMMAND => CommandConstants::COMMAND_PING,
        ]);

        $this->assertSame(CommandConstants::STATUS_ERROR, $client->lastReply()[CommandConstants::FIELD_STATUS] ?? null);
        $this->assertSame('corr-4', $client->lastReply()[CommandConstants::FIELD_CORRELATION_ID] ?? null);
    }

    public function testAWellFormedPingStillAnswersOk(): void
    {
        $client = $this->client();

        $client->feed([
            CommandConstants::FIELD_CORRELATION_ID => 'corr-2',
            CommandConstants::FIELD_COMMAND => CommandConstants::COMMAND_PING,
            CommandConstants::FIELD_PAYLOAD => [],
        ]);

        $this->assertSame(CommandConstants::STATUS_OK, $client->lastReply()[CommandConstants::FIELD_STATUS] ?? null);
        $this->assertSame('corr-2', $client->lastReply()[CommandConstants::FIELD_CORRELATION_ID] ?? null);
    }

    /**
     * Builds a command client over a socket pair the test owns.
     *
     * @return CommandClientMalformedRequestTestClient Client under test
     */
    private function client(): CommandClientMalformedRequestTestClient
    {
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->sockets[] = $pair[0];
        $this->sockets[] = $pair[1];

        return new CommandClientMalformedRequestTestClient($pair[0], new CommandServer('127.0.0.1', 0));
    }
}

/**
 * Command client that takes a request straight from the test instead of the socket.
 */
final class CommandClientMalformedRequestTestClient extends CommandClient
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
