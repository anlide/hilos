<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\WebSocketConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\Exception\HandshakeFailedException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the WebSocket handshake header-name case handling.
 *
 * RFC 7230 treats header names as case-insensitive: browsers send canonical
 * names (Upgrade), Node clients send lowercase (upgrade) - both must pass.
 */
final class WebSocketClientHandshakeHeaderCaseTest extends TestCase
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

    public function testHandshakeSucceedsWithLowercaseHeaderNames(): void
    {
        $probe = WebSocketClientTestProbe::createSocketless();
        $key = base64_encode('0123456789abcdef');

        $probe->feed(
            "GET /ws HTTP/1.1\r\n"
            . "host: localhost:8092\r\n"
            . "upgrade: websocket\r\n"
            . "connection: Upgrade\r\n"
            . "sec-websocket-key: {$key}\r\n"
            . "sec-websocket-version: 13\r\n"
            . "cookie: session=abc\r\n"
            . "\r\n",
        );

        $this->assertTrue($probe->handshakeDone());
        $expectedAccept = base64_encode(sha1($key . WebSocketConstants::RFC6455_ACCEPT_MAGIC, true));
        $this->assertStringContainsString('101 Switching Protocols', $probe->outboundBytes());
        $this->assertStringContainsString("Sec-WebSocket-Accept: {$expectedAccept}", $probe->outboundBytes());
        $this->assertSame(['session' => 'abc'], $probe->capturedCookies);
        $this->assertSame($key, $probe->capturedHeaders['sec-websocket-key'] ?? null);
    }

    public function testHandshakeSucceedsWithBrowserCanonicalHeaderNames(): void
    {
        $probe = WebSocketClientTestProbe::createSocketless();
        $key = base64_encode('fedcba9876543210');

        $probe->feed(
            "GET /ws HTTP/1.1\r\n"
            . "Host: localhost:8092\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n",
        );

        $this->assertTrue($probe->handshakeDone());
        $expectedAccept = base64_encode(sha1($key . WebSocketConstants::RFC6455_ACCEPT_MAGIC, true));
        $this->assertStringContainsString("Sec-WebSocket-Accept: {$expectedAccept}", $probe->outboundBytes());
    }

    public function testHandshakeRejectsRequestWithoutUpgradeHeader(): void
    {
        $probe = WebSocketClientTestProbe::createSocketless();

        $this->expectException(HandshakeFailedException::class);
        $probe->feed(
            "GET /ws HTTP/1.1\r\n"
            . "host: localhost:8092\r\n"
            . "\r\n",
        );
    }
}
