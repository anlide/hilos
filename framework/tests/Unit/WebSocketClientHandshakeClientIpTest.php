<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the address the handshake settles on (HIL-680).
 *
 * Two things are pinned here. socket_getpeername() answers a blank string when there is
 * no peer name, and that blank is an input problem, not an address: it is normalized to
 * null at the WebSocketClient boundary so no handshake consumer has to know that the
 * empty string means "unknown". And the forwarded address is read from X-Real-IP only
 * when the peer sending it is inside the configured trusted networks - every other
 * combination, including a blank peer that has nothing to check the list against, is
 * answered with the peer itself, which is what a deployment facing the network directly
 * always gets.
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
        putenv(EnvConstants::HILOS_TRUSTED_PROXIES->name);
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

    public function testATrustedPeerNamesTheVisitor(): void
    {
        $this->trustNetworks('10.0.0.0/8');
        $probe = WebSocketClientTestProbe::createSocketless();
        $probe->peerIp = '10.1.2.3';

        $probe->feed($this->handshakeRequest('203.0.113.7'));

        $this->assertSame('203.0.113.7', $probe->capturedClientIp);
    }

    public function testAnUntrustedPeerNamesOnlyItself(): void
    {
        $this->trustNetworks('10.0.0.0/8');
        $probe = WebSocketClientTestProbe::createSocketless();
        $probe->peerIp = '192.168.1.5';

        $probe->feed($this->handshakeRequest('203.0.113.7'));

        $this->assertSame('192.168.1.5', $probe->capturedClientIp);
    }

    public function testATrustedPeerSendingGarbageFallsBackToItsOwnAddress(): void
    {
        $this->trustNetworks('10.0.0.0/8');
        $probe = WebSocketClientTestProbe::createSocketless();
        $probe->peerIp = '10.1.2.3';

        $probe->feed($this->handshakeRequest('not-an-address'));

        $this->assertSame('10.1.2.3', $probe->capturedClientIp);
    }

    public function testWithNoTrustedNetworksTheHeaderIsIgnored(): void
    {
        $this->trustNetworks('');
        $probe = WebSocketClientTestProbe::createSocketless();
        $probe->peerIp = '10.1.2.3';

        $probe->feed($this->handshakeRequest('203.0.113.7'));

        $this->assertSame('10.1.2.3', $probe->capturedClientIp);
    }

    public function testWithoutAPeerNameTheHeaderIsIgnored(): void
    {
        $this->trustNetworks('10.0.0.0/8');
        $probe = WebSocketClientTestProbe::createSocketless();
        $probe->peerIp = '';

        $probe->feed($this->handshakeRequest('203.0.113.7'));

        $this->assertNull($probe->capturedClientIp);
    }

    /**
     * @param string $networks Comma-separated networks to configure as trusted
     */
    private function trustNetworks(string $networks): void
    {
        putenv(EnvConstants::HILOS_TRUSTED_PROXIES->name . '=' . $networks);
    }

    /**
     * Builds the minimal upgrade request the probe accepts.
     *
     * @param ?string $realIp Value of the X-Real-IP header, or null to leave the header out
     * @return string Raw handshake request bytes
     */
    private function handshakeRequest(?string $realIp = null): string
    {
        return "GET /ws HTTP/1.1\r\n"
            . "host: localhost:8092\r\n"
            . "upgrade: websocket\r\n"
            . "connection: Upgrade\r\n"
            . ($realIp === null ? '' : 'x-real-ip: ' . $realIp . "\r\n")
            . 'sec-websocket-key: ' . base64_encode('0123456789abcdef') . "\r\n"
            . "sec-websocket-version: 13\r\n"
            . "\r\n";
    }
}
