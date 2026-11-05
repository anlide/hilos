<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Event;
use EventBase;
use Hilos\Socket\Worker\DaemonConnectionState;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Socket;

/** Socket readiness and zero-deadline timers exercise the wait without clock-based assertions. */
final class WorkerDaemonClientWaitTest extends TestCase
{
    /** @var array<int, Socket> Daemon peers still open */
    private array $daemonEnds = [];

    /** @var list<WorkerDaemonClientWaitTestClient> Worker connections to release */
    private array $clients = [];

    /** Release watches through the client before closing its peers. */
    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        foreach ($this->daemonEnds as $daemonEnd) {
            socket_close($daemonEnd);
        }
        parent::tearDown();
    }

    /** Repeated waits keep the same registered watch and leave the bytes for read(). */
    public function testRepeatedReadinessKeepsTheReadWatchPending(): void
    {
        [$client, $daemonEnd] = $this->connectedClient();
        $message = new AgentStartDTO('ready');
        $wire = $message->toJson() . "\n";
        $this->assertSame(strlen($wire), socket_write($daemonEnd, $wire));

        $client->awaitReadable(60.0);
        $readEvent = $this->event($client, 'waitEvent');
        $this->assertInstanceOf(Event::class, $readEvent);
        $this->assertTrue($readEvent->pending(Event::READ));

        $client->awaitReadable(60.0);

        $this->assertSame($readEvent, $this->event($client, 'waitEvent'));
        $this->assertTrue($readEvent->pending(Event::READ));
        $this->assertNull($client->getNextMessage(), 'Waiting must not parse the ready bytes');
        $client->read();
        $queued = $client->getNextMessage();
        $this->assertInstanceOf(AgentStartDTO::class, $queued);
        $this->assertSame($message->toArray(), $queued->toArray());
        $this->assertNull($client->getNextMessage());
    }

    /** An empty socket wakes on the current zero-deadline timer, reusing it on the next call. */
    public function testZeroTimeoutRunsTheReusableTimer(): void
    {
        [$client] = $this->connectedClient();
        $client->awaitReadable(0.0);
        $timer = $this->event($client, 'waitTimer');
        $this->assertInstanceOf(Event::class, $timer);
        $this->assertFalse($timer->pending(Event::TIMEOUT));
        $calls = 0;
        $this->assertTrue($timer->setTimer($this->base($client), static function () use (&$calls): void {
            $calls++;
        }));

        $client->awaitReadable(0.0);

        $this->assertSame(1, $calls);
        $this->assertSame($timer, $this->event($client, 'waitTimer'));
        $this->assertFalse($timer->pending(Event::TIMEOUT));
        $this->assertTrue($client->isConnected());
    }

    /** Readiness must cancel its future timer so the next wait has no inherited deadline. */
    public function testReadinessRemovesTheTimerBeforeTheNextWait(): void
    {
        [$client, $daemonEnd] = $this->connectedClient();
        $this->assertSame(1, socket_write($daemonEnd, 'x'));

        $client->awaitReadable(60.0);

        $timer = $this->event($client, 'waitTimer');
        $this->assertInstanceOf(Event::class, $timer);
        $this->assertFalse($timer->pending(Event::TIMEOUT));
        $this->assertSame('x', socket_read($client->getSocket(), 1));
        $client->awaitReadable(0.0);
        $this->assertSame($timer, $this->event($client, 'waitTimer'));
        $this->assertFalse($timer->pending(Event::TIMEOUT));
    }

    /** A callback failure still takes the pending timer off the base. */
    public function testWaitFailureAlsoRemovesItsTimer(): void
    {
        [$client, $daemonEnd] = $this->connectedClient();
        $client->awaitReadable(0.0);
        $readEvent = $this->event($client, 'waitEvent');
        $this->assertInstanceOf(Event::class, $readEvent);
        $this->assertTrue($readEvent->del());
        $failure = new RuntimeException('wait callback failed');
        $this->assertTrue($readEvent->set(
            $this->base($client),
            $client->getSocket(),
            Event::READ | Event::PERSIST,
            static function () use ($failure): void {
                throw $failure;
            },
        ));
        $this->assertTrue($readEvent->add());
        $this->assertSame(1, socket_write($daemonEnd, 'x'));

        try {
            $client->awaitReadable(60.0);
            $this->fail('The fixture callback must fail the wait');
        } catch (RuntimeException $caught) {
            $this->assertSame($failure, $caught);
        }

        $timer = $this->event($client, 'waitTimer');
        $this->assertInstanceOf(Event::class, $timer);
        $this->assertFalse($timer->pending(Event::TIMEOUT));
    }

    /**
     * Both explicit close and EOF discard the old fd's events before a new connection waits.
     *
     * @param bool $loseConnection Close the peer to take the EOF path
     */
    #[DataProvider('closurePaths')]
    public function testClosureReleasesBothEventsAndTheNextSocketGetsNewOnes(bool $loseConnection): void
    {
        [$client, $daemonEnd] = $this->connectedClient();
        $client->awaitReadable(0.0);
        $readEvent = $this->event($client, 'waitEvent');
        $timer = $this->event($client, 'waitTimer');
        $this->assertInstanceOf(Event::class, $readEvent);
        $this->assertInstanceOf(Event::class, $timer);
        $base = $this->base($client);

        if ($loseConnection) {
            socket_close($daemonEnd);
            unset($this->daemonEnds[spl_object_id($daemonEnd)]);
            $client->read();
            $this->assertTrue($client->isConnectionLost());
        } else {
            $client->close();
        }

        $this->assertNull($this->event($client, 'waitEvent'));
        $this->assertNull($this->event($client, 'waitTimer'));
        $this->assertNull($client->getSocket());
        $client->close();
        [$client, $newDaemonEnd] = $this->connectedClient($client);
        $this->assertSame(1, socket_write($newDaemonEnd, 'x'));
        $client->awaitReadable(60.0);
        $this->assertSame($base, $this->base($client));
        $this->assertNotSame($readEvent, $this->event($client, 'waitEvent'));
        $this->assertNotSame($timer, $this->event($client, 'waitTimer'));
        $this->assertSame('x', socket_read($client->getSocket(), 1));
    }

    /** @return iterable<string, array{bool}> Explicit and lost-connection teardown paths */
    public static function closurePaths(): iterable
    {
        yield 'close' => [false];
        yield 'eof' => [true];
    }

    /**
     * @param ?WorkerDaemonClientWaitTestClient $client Existing client for a fresh socket, or null
     * @return array{WorkerDaemonClientWaitTestClient, Socket} Worker and daemon ends
     */
    private function connectedClient(?WorkerDaemonClientWaitTestClient $client = null): array
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $this->assertTrue(socket_set_nonblock($pair[0]));
        $this->daemonEnds[spl_object_id($pair[1])] = $pair[1];
        if ($client === null) {
            $client = new WorkerDaemonClientWaitTestClient();
            $this->clients[] = $client;
        }
        $client->adoptConnectedSocket($pair[0]);

        return [$client, $pair[1]];
    }

    /**
     * Inspect private wait handles without adding a production accessor just for the tests.
     *
     * @param WorkerDaemonClient $client Connection being observed
     * @param string $property Event handle property
     * @return ?Event Current handle, or null after teardown
     */
    private function event(WorkerDaemonClient $client, string $property): ?Event
    {
        if (!property_exists(WorkerDaemonClient::class, $property)) {
            return null;
        }

        return new ReflectionProperty(WorkerDaemonClient::class, $property)->getValue($client);
    }

    /**
     * @param WorkerDaemonClient $client Connection whose wait has been initialized
     * @return EventBase Base used to observe timer callbacks
     */
    private function base(WorkerDaemonClient $client): EventBase
    {
        return new ReflectionProperty(WorkerDaemonClient::class, 'waitBase')->getValue($client);
    }
}

/** Adopts an already connected socket without dialing a daemon. */
final class WorkerDaemonClientWaitTestClient extends WorkerDaemonClient
{
    /** @param Socket $socket Worker end of a non-blocking socket pair */
    public function adoptConnectedSocket(Socket $socket): void
    {
        $this->socket = $socket;
        $this->state = DaemonConnectionState::CONNECTED;
    }
}
