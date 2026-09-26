<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use Hilos\API\Router\HttpRouter;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Daemon\AbandonedCommandSink;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Client\HttpClient;
use Hilos\Socket\Http\DTO\HttpReplyDTO;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use Hilos\Socket\Server\HttpServer;
use Hilos\Socket\SocketException;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * The master's half of an agent-answered HTTP address: parking the connection, and writing the reply.
 *
 * The HTTP server lives in the master, where the database and the files may not be touched, so a
 * request to an address an agent declares is not answered where it arrives: the connection is held
 * under a correlation id, the request leaves as an HTTP_REQUEST signal, and the agent's reply is
 * written when it comes back. Every request goes through a real socket pair and the client's own
 * read() and write(), as in {@see HttpClientRequestBodyTest}.
 */
final class HttpClientParkTest extends TestCase
{
    /** Address the fixture agent answers */
    private const string AGENT_PATH = '/_test/file';

    /** Address an ordinary handler answers */
    private const string HANDLER_PATH = '/_test/echo';

    /** Agent type the fixture address is declared by */
    private const string AGENT_TYPE = 'test_files_agent';

    /** Most bytes read off the peer's end in one call */
    private const int PEER_READ_BYTES = 65536;

    /** @var list<Socket> Sockets closed after each test */
    private array $sockets = [];

    /** @var ?SignalRouter Router in place before the test, restored after it */
    private ?SignalRouter $previousRouter = null;

    /**
     * Restores the env facade and puts a fresh signal router in place to catch what the client queues.
     *
     * @throws EnvException When the test env cannot be loaded
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (Hilos::$env === null) {
            Hilos::initEnv(dirname(__DIR__, 2));
        }
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        $this->sockets = [];
        Hilos::$sr = $this->previousRouter;

        parent::tearDown();
    }

    /**
     * @throws EnvException When the client or the router cannot read their env values
     * @throws SocketException When a socket of the pair refuses a read or a write
     * @throws HilosException When the client refuses to turn the request into a response
     */
    public function testARequestToAnAgentAddressIsParkedAndHandedToTheAgent(): void
    {
        $server = new HttpServer('127.0.0.1', 0);
        [$client, $peer] = $this->connect($server);

        $this->send($peer, $this->get(self::AGENT_PATH . '?id=42', "Hilos-Session-Token: " . str_repeat('a', 32) . "\r\n"));
        $client->read();
        $client->write();

        $this->assertSame('', $this->receive($peer), 'nothing is answered before the agent replies');
        $request = $this->takeRequest();
        $this->assertSame(32, strlen($request->correlationId));
        $this->assertTrue(ctype_xdigit($request->correlationId));
        $this->assertSame(HttpConstants::METHOD_GET, $request->method);
        $this->assertSame(self::AGENT_PATH, $request->path);
        $this->assertSame(['id' => '42'], $request->query);
        $this->assertSame(str_repeat('a', 32), $request->sessionToken);
        $this->assertNull($request->originNodeId, 'off a cluster the reply is written where it parked');
    }

    /**
     * @throws EnvException When the client or the router cannot read their env values
     * @throws SocketException When a socket of the pair refuses a read or a write
     * @throws HilosException When the client refuses to turn the request into a response
     */
    public function testTheAgentReplyIsWrittenWithItsStatusHeadersAndBody(): void
    {
        $server = new HttpServer('127.0.0.1', 0);
        [$client, $peer] = $this->connect($server);
        $this->send($peer, $this->get(self::AGENT_PATH . '?id=42'));
        $client->read();
        $request = $this->takeRequest();

        $server->deliver($request->correlationId, HttpReplyDTO::response($request, HttpConstants::HTTP_OK, [
            HttpConstants::HEADER_CONTENT_TYPE => 'image/png',
        ], "\x89PNG\r\n"));
        $client->write();

        $received = $this->receive($peer);
        $this->assertStringStartsWith("HTTP/1.1 200 OK\r\n", $received);
        $this->assertStringContainsString("Content-Type: image/png\r\n", $received);
        $this->assertStringContainsString("Connection: keep-alive\r\n", $received);
        $this->assertStringContainsString("Content-Length: 6\r\n", $received);
        $this->assertStringEndsWith("\r\n\r\n\x89PNG\r\n", $received);
        $this->assertFalse($client->shouldClose());
    }

    /**
     * @throws EnvException When the client or the router cannot read their env values
     * @throws SocketException When a socket of the pair refuses a read or a write
     * @throws HilosException When the client refuses to turn the request into a response
     */
    public function testARequestBehindAParkedOneWaitsForTheReplyAndIsAnsweredAfterIt(): void
    {
        $server = new HttpServer('127.0.0.1', 0);
        [$client, $peer] = $this->connect($server);

        $this->send($peer, $this->get(self::AGENT_PATH . '?id=1') . $this->get(self::HANDLER_PATH));
        $client->read();
        $client->write();

        $this->assertSame('', $this->receive($peer), 'the pipelined request must not overtake the parked one');
        $request = $this->takeRequest();
        $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'the second request is not a signal');

        $server->deliver($request->correlationId, HttpReplyDTO::refusal($request, HttpConstants::HTTP_NOT_FOUND));
        $client->write();

        $this->assertSame([HttpConstants::HTTP_NOT_FOUND, HttpConstants::HTTP_OK], $this->statuses($this->receive($peer)));
    }

    /**
     * @throws EnvException When the client or the router cannot read their env values
     * @throws SocketException When a socket of the pair refuses a read or a write
     * @throws HilosException When the client refuses to turn the request into a response
     */
    public function testAConnectionThatClosesWhileParkedIsAbandonedAndItsLateReplyFindsNobody(): void
    {
        $server = new HttpServer('127.0.0.1', 0);
        $sink = new HttpClientParkTestAbandonedSink();
        $server->setAbandonedCommandSink($sink);
        [$client, $peer] = $this->connect($server);
        $this->send($peer, $this->get(self::AGENT_PATH . '?id=42'));
        $client->read();
        $request = $this->takeRequest();

        $client->close();
        $this->sockets = [$peer];

        $this->assertSame([$request->correlationId], $sink->abandoned);
        $server->deliver($request->correlationId, HttpReplyDTO::refusal($request, HttpConstants::HTTP_NOT_FOUND));
        $this->assertSame('', $this->receive($peer), 'a reply to a connection that left is written nowhere');
    }

    /**
     * @throws EnvException When the client or the router cannot read their env values
     * @throws SocketException When a socket of the pair refuses a read or a write
     * @throws HilosException When the client refuses to turn the request into a response
     */
    public function testAReplyToACorrelationIdNobodyHoldsIsDropped(): void
    {
        $server = new HttpServer('127.0.0.1', 0);
        [$client, $peer] = $this->connect($server);
        $this->send($peer, $this->get(self::AGENT_PATH . '?id=42'));
        $client->read();
        $request = $this->takeRequest();
        $stranger = new HttpRequestDTO(str_repeat('f', 32), HttpConstants::METHOD_GET, self::AGENT_PATH, [], null, null);

        $server->deliver($stranger->correlationId, HttpReplyDTO::refusal($stranger, HttpConstants::HTTP_NOT_FOUND));
        $client->write();

        $this->assertSame('', $this->receive($peer), 'only the reply to its own request reaches a parked connection');
        $server->deliver($request->correlationId, HttpReplyDTO::refusal($request, HttpConstants::HTTP_NOT_FOUND));
        $client->write();
        $this->assertSame([HttpConstants::HTTP_NOT_FOUND], $this->statuses($this->receive($peer)));
    }

    /**
     * @throws EnvException When the client or the router cannot read their env values
     * @throws SocketException When a socket of the pair refuses a read or a write
     * @throws HilosException When the client refuses to turn the request into a response
     */
    public function testAnOrdinaryRouteIsStillAnsweredWhereItArrives(): void
    {
        [$client, $peer] = $this->connect(new HttpServer('127.0.0.1', 0));

        $this->send($peer, $this->get(self::HANDLER_PATH));
        $client->read();

        $this->assertSame([HttpConstants::HTTP_OK], $this->statuses($this->receive($peer)));
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * @throws EnvException When the client or the router cannot read their env values
     * @throws SocketException When a socket of the pair refuses a read or a write
     * @throws HilosException When the client refuses to turn the request into a response
     */
    public function testAProjectRouteOnTheSameAddressReplacesTheAgent(): void
    {
        $router = $this->router();
        $router->addRoute(HttpConstants::METHOD_GET, self::AGENT_PATH, static fn (): array => ['served' => 'by the project']);
        [$client, $peer] = $this->connect(new HttpServer('127.0.0.1', 0), $router);

        $this->send($peer, $this->get(self::AGENT_PATH . '?id=42'));
        $client->read();

        $received = $this->receive($peer);
        $this->assertSame([HttpConstants::HTTP_OK], $this->statuses($received));
        $this->assertStringContainsString('by the project', $received);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * Opens a socket pair and puts an HTTP client of the given server on one end of it.
     *
     * @param HttpServer $server Server that holds the client's parked requests
     * @param ?HttpRouter $router Router to route with, the fixture one when null
     * @return array{HttpClient, Socket} Client, and the peer's end of the pair
     * @throws EnvException When the client or the router cannot read their env values
     */
    private function connect(HttpServer $server, ?HttpRouter $router = null): array
    {
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->sockets[] = $pair[0];
        $this->sockets[] = $pair[1];

        $client = new HttpClient($pair[0], server: $server);
        $client->setRouter($router ?? $this->router());

        return [$client, $pair[1]];
    }

    /**
     * The router the daemon builds: one address an agent answers, one a handler does.
     *
     * @return HttpRouter Router fixture
     * @throws EnvException When the router cannot read the session cookie name
     */
    private function router(): HttpRouter
    {
        $router = new HttpRouter();
        $router->addAgentRoute(HttpConstants::METHOD_GET, self::AGENT_PATH, self::AGENT_TYPE);
        $router->addRoute(HttpConstants::METHOD_GET, self::HANDLER_PATH, static fn (): array => ['echo' => 'ok']);

        return $router;
    }

    /**
     * Takes the one HTTP_REQUEST the client queued, asserting its envelope.
     *
     * @return HttpRequestDTO Request handed to the agent
     */
    private function takeRequest(): HttpRequestDTO
    {
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertInstanceOf(SignalDTO::class, $signal);
        $this->assertSame(SignalTypeConstants::HTTP_REQUEST, $signal->signalType->getType());
        $this->assertSame(SignalSource::DAEMON, $signal->signalSource->getSource());
        $this->assertInstanceOf(HttpRequestDTO::class, $signal->data);
        $this->assertSame("{$signal->data->method} {$signal->data->path}", $signal->signalName->getName());

        return $signal->data;
    }

    /**
     * Serializes a GET request.
     *
     * @param string $target Path and query string
     * @param string $extraHeaders Header lines to add, each ending in CRLF
     * @return string Raw request
     */
    private function get(string $target, string $extraHeaders = ''): string
    {
        return "GET {$target} HTTP/1.1\r\nHost: daemon\r\n{$extraHeaders}\r\n";
    }

    /**
     * Writes raw bytes from the peer's end.
     *
     * @param Socket $peer Peer's end of the pair
     * @param string $bytes Bytes to write
     */
    private function send(Socket $peer, string $bytes): void
    {
        $this->assertSame(strlen($bytes), socket_write($peer, $bytes));
    }

    /**
     * Reads everything the client has written to the peer so far, without waiting for more.
     *
     * @param Socket $peer Peer's end of the pair
     * @return string Bytes received, empty when the client wrote nothing
     */
    private function receive(Socket $peer): string
    {
        $received = '';
        while (true) {
            $read = [$peer];
            $write = null;
            $except = null;
            if (socket_select($read, $write, $except, 0) !== 1) {
                return $received;
            }
            $chunk = socket_read($peer, self::PEER_READ_BYTES, PHP_BINARY_READ);
            if ($chunk === false || $chunk === '') {
                return $received;
            }
            $received .= $chunk;
        }
    }

    /**
     * Status codes of the responses in the received bytes, in the order they were sent.
     *
     * @param string $received Bytes the peer received
     * @return list<int> Status codes
     */
    private function statuses(string $received): array
    {
        preg_match_all('#HTTP/1\.1 (\d{3}) #', $received, $matches);

        return array_map(intval(...), $matches[1]);
    }
}

/**
 * Master seam double that remembers which parked requests were given up on.
 */
final class HttpClientParkTestAbandonedSink implements AbandonedCommandSink
{
    /** @var list<string> Correlation ids reported abandoned, in order */
    public array $abandoned = [];

    /**
     * @param string $correlationId Correlation id nobody is waiting on any more
     */
    public function onCommandAbandoned(string $correlationId): void
    {
        $this->abandoned[] = $correlationId;
    }
}
