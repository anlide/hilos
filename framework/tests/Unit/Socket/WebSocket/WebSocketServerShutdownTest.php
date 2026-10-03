<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\WebSocket;

use Hilos\Core\Http\RequestQueryParams;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\SocketException;
use PHPUnit\Framework\TestCase;
use Socket;

/** Browsers remain writable until the master's final release (HIL-1207). */
final class WebSocketServerShutdownTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        Hilos::$env = new EnvAccessor();
        putenv('SOCKET_READ_BUFFER_SIZE=65536');
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        putenv('SOCKET_READ_BUFFER_SIZE');

        parent::tearDown();
    }

    public function testAStopHookFrameIsWrittenBeforeTheBrowserLeaves(): void
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $this->assertTrue(socket_set_nonblock($pair[0]));
        $server = new WebSocketServerShutdownTestServer();
        $client = new WebSocketServerShutdownTestClient($pair[0]);
        $server->seedClient($client);

        try {
            $server->prepareShutdown();
            $this->assertFalse($server->isReadyToShutdown());
            $this->assertFalse($client->shouldClose());

            $client->sendFrame('agent stopped');
            $server->closeClientsOnceWritten();
            $this->assertFalse($client->shouldClose());
            $this->assertFalse($server->isReadyToShutdown());

            $server->onTick();

            $this->assertStringContainsString('agent stopped', socket_read($pair[1], 1024));
            $this->assertTrue($server->isReadyToShutdown());
            $this->assertSame(1, $client->closeCount);
        } finally {
            if (!$server->isReadyToShutdown()) {
                $client->close();
            }
            socket_close($pair[1]);
        }
    }
}

/** Server whose browser was admitted before departure. */
final class WebSocketServerShutdownTestServer extends WebSocketServer
{
    public function __construct()
    {
        parent::__construct('127.0.0.1', 0);
    }

    /** @param WebSocketServerShutdownTestClient $client Browser held by this server */
    public function seedClient(WebSocketServerShutdownTestClient $client): void
    {
        $this->clients[] = $client;
    }

    protected function onStart(): void
    {
    }

    /**
     * @param resource $socket Unused accepted socket
     * @return WebSocketClientInterface Never returned
     * @throws SocketException Always, since this test does not accept clients
     */
    protected function onCreateClient($socket): WebSocketClientInterface
    {
        throw new SocketException('the shutdown test accepts no clients');
    }
}

/** Browser with a completed handshake and no application close side effects. */
final class WebSocketServerShutdownTestClient extends WebSocketClient
{
    public int $closeCount = 0;

    /** @param Socket $socket Connected browser socket */
    public function __construct(Socket $socket)
    {
        parent::__construct($socket);
        $this->handshakeCompleted = true;
    }

    /**
     * @param array<string, string> $headers Unused handshake headers
     * @param string $acceptKey Unused connection key
     * @param array<string, string> $cookies Unused cookies
     * @param ?string $clientIp Unused client address
     * @param RequestQueryParams $queryParams Unused query
     */
    protected function onHandshake(
        array $headers,
        string $acceptKey,
        array $cookies,
        ?string $clientIp,
        RequestQueryParams $queryParams,
    ): void {
    }

    protected function onClose(): void
    {
        $this->closeCount++;
    }
}
