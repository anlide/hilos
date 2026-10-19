<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Socket\Exception\Base\ConnectionResetException;
use Hilos\Socket\Worker\DaemonConnectionState;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Socket;

/** A worker drains queued daemon bytes before parsing or reporting connection loss (HIL-1527). */
final class WorkerDaemonClientDrainTest extends TestCase
{
    /** @var array<int, Socket> Daemon ends still owned by the test */
    private array $daemonEnds = [];

    /** @var list<WorkerDaemonClientDrainTestClient> Clients to close after the test */
    private array $clients = [];

    /** Close both ends, including clients that already lost their connection. */
    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        foreach ($this->daemonEnds as $socket) {
            socket_close($socket);
        }

        parent::tearDown();
    }

    /** All three messages, including an upload-sized base64 field, arrive in one read. */
    public function testOneReadQueuesAllAvailableMessages(): void
    {
        [$client, $daemonEnd] = $this->connectedClient();
        $messages = [
            new AgentStartDTO('upload-sized', [base64_encode(str_repeat('x', 65_536))]),
            new AgentStartDTO('second', [str_repeat('y', 16_384)]),
            new AgentStartDTO('third'),
        ];
        $wire = '';
        foreach ($messages as $message) {
            $wire .= $message->toJson() . "\n";
        }
        $this->assertGreaterThan(100_000, strlen($wire));
        $this->assertSame(strlen($wire), socket_write($daemonEnd, $wire));

        $client->read();

        foreach ($messages as $message) {
            $queued = $client->getNextMessage();
            $this->assertInstanceOf(AgentStartDTO::class, $queued);
            $this->assertSame($message->toArray(), $queued->toArray());
        }
        $this->assertNull($client->getNextMessage());
        $this->assertTrue($client->isConnected());
    }

    /**
     * Two reads split the encoded key at every byte, including inside escaping.
     *
     * @param string $acceptKey Exact key to reconstruct
     * @param int $splitOffset Split within the JSON string representing that key
     */
    #[DataProvider('escapedKeySplits')]
    public function testSplitEscapingPreservesMessagesAndQueueOrder(string $acceptKey, int $splitOffset): void
    {
        [$client, $daemonEnd] = $this->connectedClient();
        $first = new AgentStartDTO('before-split');
        $splitMessage = new AgentStartDTO('split', [$acceptKey, 'after-[]{}"/\\']);
        $last = new AgentStartDTO('after-split');
        $incomplete = new AgentStartDTO('waiting-for-newline', ['tail']);
        $wire = $splitMessage->toJson() . "\n";
        $keyPosition = strpos($wire, json_encode($acceptKey, JSON_THROW_ON_ERROR));
        $this->assertNotFalse($keyPosition);
        $cut = $keyPosition + $splitOffset;
        $firstRead = $first->toJson() . "\n" . substr($wire, 0, $cut);
        $secondRead = substr($wire, $cut) . $last->toJson() . "\n" . $incomplete->toJson();
        $this->assertSame(strlen($firstRead), socket_write($daemonEnd, $firstRead));

        $client->read();

        $queued = $client->getNextMessage();
        $this->assertInstanceOf(AgentStartDTO::class, $queued);
        $this->assertSame($first->toArray(), $queued->toArray());
        $this->assertNull($client->getNextMessage(), 'The split message must not arrive early');
        $this->assertTrue($client->isConnected());
        $this->assertSame(strlen($secondRead), socket_write($daemonEnd, $secondRead));

        $client->read();

        foreach ([$splitMessage, $last] as $message) {
            $queued = $client->getNextMessage();
            $this->assertInstanceOf(AgentStartDTO::class, $queued);
            $this->assertSame($message->toArray(), $queued->toArray());
        }
        $this->assertNull($client->getNextMessage(), 'No duplicate or unterminated message may arrive');
        $this->assertTrue($client->isConnected());
    }

    /**
     * @return iterable<string, array{string, int}> Keys and every split within their encoded string
     */
    public static function escapedKeySplits(): iterable
    {
        $keys = [
            'brackets' => '{}[]',
            'quotes-and-slashes' => 'a"b/c\\d',
            'escaped-newline' => "\n",
            'escaped-tab' => "\t",
            'escaped-control' => "\x01",
            'even-backslashes' => '\\\\',
            'odd-backslashes-before-quote' => '\\"',
            'even-backslashes-before-quote' => '\\\\"',
        ];
        foreach ($keys as $name => $key) {
            $encoded = json_encode($key, JSON_THROW_ON_ERROR);
            for ($offset = 1; $offset < strlen($encoded); $offset++) {
                yield $name . '-' . $offset => [$key, $offset];
            }
        }
    }

    /** The same read observes EOF after preserving the complete preceding message. */
    public function testMessageBeforeEofStaysQueuedInLostState(): void
    {
        [$client, $daemonEnd] = $this->connectedClient();
        $message = new AgentStartDTO('before-eof');
        $wire = $message->toJson() . "\n";
        $this->assertSame(strlen($wire), socket_write($daemonEnd, $wire));
        $this->closeDaemonEnd($daemonEnd);

        $client->read();

        $this->assertTrue($client->isConnectionLost());
        $this->assertFalse($client->isConnected());
        $queued = $client->getNextMessage();
        $this->assertInstanceOf(AgentStartDTO::class, $queued);
        $this->assertSame($message->toArray(), $queued->toArray());
        $this->assertNull($client->getNextMessage());
    }

    /** A reset following complete data is raised after that data reaches the queue. */
    public function testMessageBeforeResetStaysQueuedWhenTheOriginalErrorIsRaised(): void
    {
        [$client, $daemonEnd] = $this->connectedClient();
        $message = new AgentStartDTO('before-reset');
        $wire = $message->toJson() . "\n";
        $this->assertSame(strlen($wire), socket_write($daemonEnd, $wire));
        $client->send(new AgentStartDTO('unread'));
        $client->write();
        $this->closeDaemonEnd($daemonEnd);

        try {
            $client->read();
            $this->fail('The reset after the message must raise');
        } catch (ConnectionResetException) {
            $this->assertTrue($client->isConnectionLost());
            $queued = $client->getNextMessage();
            $this->assertInstanceOf(AgentStartDTO::class, $queued);
            $this->assertSame($message->toArray(), $queued->toArray());
            $this->assertNull($client->getNextMessage());
        }
    }

    /**
     * @return array{WorkerDaemonClientDrainTestClient, Socket} Connected client and its daemon peer
     */
    private function connectedClient(): array
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $this->assertTrue(socket_set_nonblock($pair[0]));
        $this->daemonEnds[spl_object_id($pair[1])] = $pair[1];
        $client = new WorkerDaemonClientDrainTestClient();
        $client->adoptConnectedSocket($pair[0]);
        $this->clients[] = $client;

        return [$client, $pair[1]];
    }

    /**
     * @param Socket $socket Daemon end to close before the worker reads
     */
    private function closeDaemonEnd(Socket $socket): void
    {
        socket_close($socket);
        unset($this->daemonEnds[spl_object_id($socket)]);
    }
}

/** Hands the worker one end of an established non-blocking socket pair. */
final class WorkerDaemonClientDrainTestClient extends WorkerDaemonClient
{
    /**
     * @param Socket $socket Connected worker end
     */
    public function adoptConnectedSocket(Socket $socket): void
    {
        $this->socket = $socket;
        $this->state = DaemonConnectionState::CONNECTED;
    }
}
