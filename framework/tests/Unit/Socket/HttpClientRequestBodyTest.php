<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use Hilos\API\Router\HttpRouter;
use Hilos\Constants\HttpConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Client\HttpClient;
use Hilos\Socket\SocketException;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * The HTTP server reading the body of a request by its declared length (HIL-921).
 *
 * Until this leaf the server cut a request at the blank line after its headers and handed the
 * route an empty body, so the bytes of the body stayed in the buffer and were read as the start
 * of the next request. No route noticed, because every route the daemon served was a GET; the
 * stand gateway serves five POST routes, and each test here reads one step of the parse it needs.
 *
 * Every request goes through a real socket pair and the client's own read(), so a request that
 * arrives in two pieces arrives the way the kernel hands it over, not the way a test would feed it.
 */
final class HttpClientRequestBodyTest extends TestCase
{
    /** Path of the one route the tests call */
    private const string ECHO_PATH = '/echo';

    /** Declared length above the server's body ceiling */
    private const int OVERSIZED_BODY_BYTES = 2 * 1024 * 1024;

    /** Most bytes read off the peer's end in one call */
    private const int PEER_READ_BYTES = 65536;

    /** @var list<Socket> Sockets closed after each test */
    private array $sockets = [];

    /** @var list<string> Bodies the route received, one per call, in call order */
    private array $receivedBodies = [];

    /**
     * Restores the env facade, which the client and the router read at construction.
     *
     * @throws EnvException When the test env cannot be loaded
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (Hilos::$env === null) {
            Hilos::initEnv(dirname(__DIR__, 2));
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        $this->sockets = [];
        $this->receivedBodies = [];

        parent::tearDown();
    }

    /**
     * @throws EnvException When the client or the router cannot read their env values
     * @throws SocketException When a socket of the pair refuses a read or a write
     * @throws HilosException When the client refuses to turn the request into a response
     */
    public function testAFormBodyReachesTheRouteWhole(): void
    {
        [$client, $peer] = $this->connect();
        $body = 'chat_id=42&text=Your+code+is+123456';

        $this->send($peer, $this->post($body));
        $client->read();

        $this->assertSame([$body], $this->receivedBodies);
        $this->assertSame([HttpConstants::HTTP_OK], $this->statuses($this->receive($peer)));
        $this->assertFalse($client->shouldClose());
    }

    /**
     * @throws EnvException When the client or the router cannot read their env values
     * @throws SocketException When a socket of the pair refuses a read or a write
     * @throws HilosException When the client refuses to turn the request into a response
     */
    public function testABodyThatArrivesInTwoPiecesIsRoutedOnceAfterTheSecond(): void
    {
        [$client, $peer] = $this->connect();
        $request = $this->post('chat_id=42&text=Your+code+is+123456');
        $cut = strlen($request) - 10;

        $this->send($peer, substr($request, 0, $cut));
        $client->read();

        $this->assertSame([], $this->receivedBodies, 'the route must wait for the rest of the declared body');
        $this->assertSame('', $this->receive($peer));

        $this->send($peer, substr($request, $cut));
        $client->read();

        $this->assertSame(['chat_id=42&text=Your+code+is+123456'], $this->receivedBodies);
        $this->assertSame([HttpConstants::HTTP_OK], $this->statuses($this->receive($peer)));
    }

    /**
     * @throws EnvException When the client or the router cannot read their env values
     * @throws SocketException When a socket of the pair refuses a read or a write
     * @throws HilosException When the client refuses to turn the request into a response
     */
    public function testTwoPostsInOneBufferAreTwoRequests(): void
    {
        [$client, $peer] = $this->connect();

        $this->send($peer, $this->post('first=1') . $this->post('second=2'));
        $client->read();

        $this->assertSame(['first=1', 'second=2'], $this->receivedBodies);
        $this->assertSame([HttpConstants::HTTP_OK, HttpConstants::HTTP_OK], $this->statuses($this->receive($peer)));
    }

    /**
     * @throws EnvException When the client or the router cannot read their env values
     * @throws SocketException When a socket of the pair refuses a read or a write
     * @throws HilosException When the client refuses to turn the request into a response
     */
    public function testADeclaredLengthAboveTheCeilingIsRefusedWithoutBufferingTheBody(): void
    {
        [$client, $peer] = $this->connect();
        $headers = 'POST ' . self::ECHO_PATH . " HTTP/1.1\r\nHost: stand-gateway\r\n"
            . 'Content-Length: ' . self::OVERSIZED_BODY_BYTES . "\r\n\r\n";

        $this->send($peer, $headers . str_repeat('a', 4096));
        $client->read();

        $this->assertSame([], $this->receivedBodies);
        $this->assertSame([HttpConstants::HTTP_PAYLOAD_TOO_LARGE], $this->statuses($this->receive($peer)));
        $this->assertTrue($client->shouldClose());
        $this->assertSame(0, $client->bufferedBytes());
    }

    /**
     * @throws EnvException When the client or the router cannot read their env values
     * @throws SocketException When a socket of the pair refuses a read or a write
     * @throws HilosException When the client refuses to turn the request into a response
     */
    public function testAChunkedBodyIsRefusedWithLengthRequired(): void
    {
        [$client, $peer] = $this->connect();

        $this->send(
            $peer,
            'POST ' . self::ECHO_PATH . " HTTP/1.1\r\nHost: stand-gateway\r\nTransfer-Encoding: chunked\r\n\r\n"
                . "7\r\nfirst=1\r\n0\r\n\r\n",
        );
        $client->read();

        $this->assertSame([], $this->receivedBodies);
        $this->assertSame([HttpConstants::HTTP_LENGTH_REQUIRED], $this->statuses($this->receive($peer)));
        $this->assertTrue($client->shouldClose());
    }

    /**
     * @throws EnvException When the client or the router cannot read their env values
     * @throws SocketException When a socket of the pair refuses a read or a write
     * @throws HilosException When the client refuses to turn the request into a response
     */
    public function testAGetWithoutABodyIsRoutedAsBefore(): void
    {
        [$client, $peer] = $this->connect();

        $this->send($peer, 'GET ' . self::ECHO_PATH . "?a=1 HTTP/1.1\r\nHost: stand-gateway\r\n\r\n");
        $client->read();

        $this->assertSame([''], $this->receivedBodies);
        $this->assertSame([HttpConstants::HTTP_OK], $this->statuses($this->receive($peer)));
        $this->assertFalse($client->shouldClose());
    }

    /**
     * @throws EnvException When the client or the router cannot read their env values
     * @throws SocketException When a socket of the pair refuses a read or a write
     * @throws HilosException When the client refuses to turn the request into a response
     */
    public function testANegativeDeclaredLengthIsRefusedAsABadRequest(): void
    {
        [$client, $peer] = $this->connect();

        $this->send($peer, 'POST ' . self::ECHO_PATH . " HTTP/1.1\r\nHost: stand-gateway\r\nContent-Length: -7\r\n\r\nfirst=1");
        $client->read();

        $this->assertSame([], $this->receivedBodies);
        $this->assertSame([HttpConstants::HTTP_BAD_REQUEST], $this->statuses($this->receive($peer)));
        $this->assertTrue($client->shouldClose());
    }

    /**
     * Builds an HTTP client over one end of a socket pair, with one route that records the bodies it gets.
     *
     * @return array{0: HttpClientRequestBodyTestClient, 1: Socket} The client and the peer's end of the pair
     * @throws EnvException When the client or the router cannot read their env values
     */
    private function connect(): array
    {
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->sockets[] = $pair[0];
        $this->sockets[] = $pair[1];

        $router = new HttpRouter();
        $router->addRoute(HttpConstants::METHOD_GET, self::ECHO_PATH, $this->recordBody(...));
        $router->addRoute(HttpConstants::METHOD_POST, self::ECHO_PATH, $this->recordBody(...));

        $client = new HttpClientRequestBodyTestClient($pair[0]);
        $client->setRouter($router);

        return [$client, $pair[1]];
    }

    /**
     * Route handler: remembers the body it was given and answers with it.
     *
     * @param array{request: array<string, mixed>, params: array<string, string>} $args Handler arguments
     * @return array{received: string} Response payload
     */
    private function recordBody(array $args): array
    {
        $body = $args['request'][HttpConstants::REQUEST_KEY_BODY];
        $this->receivedBodies[] = $body;

        return ['received' => $body];
    }

    /**
     * Serializes a form POST to the echo route with its length declared.
     *
     * @param string $body Form body
     * @return string Raw request
     */
    private function post(string $body): string
    {
        return 'POST ' . self::ECHO_PATH . " HTTP/1.1\r\nHost: stand-gateway\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n"
            . $body;
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
        // A response ends with its body and no line break, so the next status line starts mid-line.
        preg_match_all('#HTTP/1\.1 (\d{3}) #', $received, $matches);

        return array_map(intval(...), $matches[1]);
    }
}

/**
 * HTTP client that lets a test see how many request bytes it still holds.
 */
final class HttpClientRequestBodyTestClient extends HttpClient
{
    /**
     * Bytes left in the read buffer.
     *
     * @return int Buffered request bytes
     */
    public function bufferedBytes(): int
    {
        return strlen($this->readBuffer);
    }
}
