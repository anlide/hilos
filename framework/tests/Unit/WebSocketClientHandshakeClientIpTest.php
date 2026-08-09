<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for how the handshake reports a client IP it could not read.
 *
 * socket_getpeername() answers a blank string when there is no peer name, and
 * that blank is an input problem, not an address: it is normalized to null at
 * the WebSocketClient boundary so no handshake consumer has to know that the
 * empty string means "unknown".
 */
final class WebSocketClientHandshakeClientIpTest extends TestCase
{
    private ?SignalRouter $previousSignalRouter = null;

    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        // The handshake welcome frame reads HILOS_BUILD_TIMESTAMP through Hilos::$env.
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$env = $this->previousEnv;
    }

    public function testHandshakeCarriesTheResolvedClientIp(): void
    {
        $probe = WebSocketClientTestProbe::createSocketless();
        $probe->peerIp = '10.1.2.3';

        $probe->feed($this->handshakeRequest());

        $this->assertTrue($probe->handshakeDone());
        $this->assertSame('10.1.2.3', $probe->capturedClientIp);
    }

    public function testHandshakeWithoutAPeerNameCarriesNoClientIp(): void
    {
        $probe = WebSocketClientTestProbe::createSocketless();
        $probe->peerIp = '';

        $probe->feed($this->handshakeRequest());

        $this->assertTrue($probe->handshakeDone());
        $this->assertNull($probe->capturedClientIp);
    }

    /**
     * Builds the minimal upgrade request the probe accepts.
     *
     * @return string Raw handshake request bytes
     */
    private function handshakeRequest(): string
    {
        return "GET /ws HTTP/1.1\r\n"
            . "host: localhost:8092\r\n"
            . "upgrade: websocket\r\n"
            . "connection: Upgrade\r\n"
            . 'sec-websocket-key: ' . base64_encode('0123456789abcdef') . "\r\n"
            . "sec-websocket-version: 13\r\n"
            . "\r\n";
    }
}
