<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\WebSocketConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the framework handshake welcome frame and the minted accept key.
 *
 * The welcome `{type: 'handshake', data: {build}}` must be the first frame
 * behind the 101 response; the accept key must be a random server-minted
 * identifier, not the client-derivable RFC 6455 Sec-WebSocket-Accept value.
 */
final class WebSocketClientHandshakeWelcomeTest extends TestCase
{
    private ?SignalRouter $previousSignalRouter = null;

    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$env = $this->previousEnv;
        putenv('HILOS_BUILD_TIMESTAMP');
    }

    public function testWelcomeIsTheFirstFrameAfterThe101Response(): void
    {
        $probe = $this->handshakenProbe();

        $welcome = $this->decodeFirstFrameAfter101($probe->outboundBytes());
        $this->assertSame(
            ['type' => 'handshake', 'data' => ['build' => 'dev']],
            $welcome,
        );
    }

    public function testWelcomeCarriesTheConfiguredBuildTimestamp(): void
    {
        putenv('HILOS_BUILD_TIMESTAMP=20260610153000');

        $probe = $this->handshakenProbe();

        $welcome = $this->decodeFirstFrameAfter101($probe->outboundBytes());
        $this->assertSame('20260610153000', $welcome['data']['build'] ?? null);
    }

    public function testAcceptKeyIsMintedNotTheRfcAcceptValue(): void
    {
        $key = base64_encode('0123456789abcdef');
        $probe = $this->handshakenProbe($key);

        $rfcAccept = base64_encode(sha1($key . WebSocketConstants::RFC6455_ACCEPT_MAGIC, true));
        $this->assertStringContainsString("Sec-WebSocket-Accept: {$rfcAccept}", $probe->outboundBytes());
        $this->assertNotSame($rfcAccept, $probe->acceptKey);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', $probe->acceptKey);
        $this->assertSame($probe->acceptKey, $probe->capturedAcceptKey);
    }

    public function testEachHandshakeMintsAUniqueAcceptKey(): void
    {
        $first = $this->handshakenProbe();
        $second = $this->handshakenProbe();

        $this->assertNotSame($first->acceptKey, $second->acceptKey);
    }

    /**
     * Drive a probe through a complete handshake.
     *
     * @param ?string $key Sec-WebSocket-Key value; defaults to a fixed test key
     * @return WebSocketClientTestProbe Probe with a completed handshake
     */
    private function handshakenProbe(?string $key = null): WebSocketClientTestProbe
    {
        $probe = WebSocketClientTestProbe::createSocketless();
        $key ??= base64_encode('0123456789abcdef');

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

        return $probe;
    }

    /**
     * Decode the first WebSocket frame following the 101 response bytes.
     *
     * Also pins the frame shape: a single final unmasked text frame with a
     * 7-bit payload length, and nothing else queued behind it.
     *
     * @param string $outbound Bytes queued for the client (101 response + frames)
     * @return array<string, mixed> Decoded JSON payload of the first frame
     */
    private function decodeFirstFrameAfter101(string $outbound): array
    {
        $delimiterPos = strpos($outbound, "\r\n\r\n");
        $this->assertNotFalse($delimiterPos, 'No 101 response in the outbound bytes');

        $frame = substr($outbound, $delimiterPos + 4);
        $this->assertGreaterThanOrEqual(2, strlen($frame), 'No frame follows the 101 response');
        $this->assertSame(0x81, ord($frame[0]), 'Welcome must be a final text frame');
        $payloadLength = ord($frame[1]);
        $this->assertLessThan(126, $payloadLength, 'Welcome payload must use the 7-bit length form');
        $this->assertSame(2 + $payloadLength, strlen($frame), 'Welcome must be the only frame behind the 101');

        $payload = substr($frame, 2, $payloadLength);
        $decoded = json_decode($payload, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
