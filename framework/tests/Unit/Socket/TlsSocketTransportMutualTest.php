<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use EventBufferEvent;
use Hilos\Socket\Exception\SocketTlsHandshakeException;
use Hilos\Socket\Transport\TlsSocketTransport;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Real native TLS authenticates both roles; certificate retrieval alone grants no trust. */
final class TlsSocketTransportMutualTest extends TestCase
{
    private MutualTlsFixture $fixture;

    private ?TlsTestExchange $exchange = null;

    public function testBothSidesVerifyingFinishTheHandshakeAndVouchForEachOthersName(): void
    {
        $authority = $this->fixture->issueAuthority('Hilos cluster CA');
        $trust = $this->fixture->trustFile($authority);
        $exchange = $this->exchange = new TlsTestExchange($this->fixture,
            $this->fixture->issueNode('m1', $authority), $this->fixture->issueNode('m2', $authority), $trust, $trust);
        $accepting = $exchange->transports['accepting'];
        $dialing = $exchange->transports['dialing'];
        $this->assertNull($accepting->verifiedPeerName());
        $this->assertNull($dialing->verifiedPeerName());
        $exchange->handshake();
        $this->assertSame([], $exchange->failures);
        $this->assertFalse($accepting->needsHandshake());
        $this->assertFalse($dialing->needsHandshake());
        $this->assertSame('m2', $accepting->verifiedPeerName());
        $this->assertSame('m1', $dialing->verifiedPeerName());
        $this->assertSame(0, $dialing->write('ping'));
        $exchange->runUntil(fn(): bool => $exchange->received['accepting'] === 'ping');
        $this->assertSame(4, $dialing->write('ping'));
        $this->assertSame(0, $accepting->write('pong'));
        $exchange->runUntil(fn(): bool => $exchange->received['dialing'] === 'pong');
        $this->assertSame(4, $accepting->write('pong'));
    }

    public function testAVerifyingAcceptorNamesADialerWithoutACertificate(): void
    {
        $authority = $this->fixture->issueAuthority('Hilos cluster CA');
        $trust = $this->fixture->trustFile($authority);
        $this->exchange = new TlsTestExchange($this->fixture, $this->fixture->issueNode('m1', $authority), null, $trust, $trust);
        $this->exchange->handshake();
        $this->assertRefusal('accepting', 'peer did not return a certificate');
        $this->assertNull($this->exchange->transports['accepting']->verifiedPeerName());
    }

    public function testAVerifyingAcceptorNamesADialerOfAForeignAuthority(): void
    {
        $authority = $this->fixture->issueAuthority('Hilos cluster CA');
        $foreign = $this->fixture->issueAuthority('Someone else CA');
        $trust = $this->fixture->trustFile($authority);
        $this->exchange = new TlsTestExchange($this->fixture,
            $this->fixture->issueNode('m1', $authority), $this->fixture->issueNode('x1', $foreign), $trust, $trust);
        $this->exchange->handshake();
        $this->assertRefusal('accepting', 'certificate verify failed');
    }

    public function testADialerNamesAnAcceptorOfAForeignAuthority(): void
    {
        $authority = $this->fixture->issueAuthority('Hilos cluster CA');
        $foreign = $this->fixture->issueAuthority('Someone else CA');
        $trust = $this->fixture->trustFile($authority);
        $this->exchange = new TlsTestExchange($this->fixture,
            $this->fixture->issueNode('x1', $foreign), $this->fixture->issueNode('m2', $authority), $trust, $trust);
        $this->exchange->handshake();
        $this->assertRefusal('dialing', 'certificate verify failed');
        $this->assertNull($this->exchange->transports['dialing']->verifiedPeerName());
    }

    public function testAnAcceptorWithoutATrustFileAsksForNoCertificateAndVouchesForNobody(): void
    {
        $authority = $this->fixture->issueAuthority('Hilos cluster CA');
        $foreign = $this->fixture->issueAuthority('Someone else CA');
        $this->exchange = new TlsTestExchange($this->fixture,
            $this->fixture->issueNode('gateway', $authority), $this->fixture->issueNode('x1', $foreign), null,
            $this->fixture->trustFile($authority));
        $this->exchange->handshake();
        $this->assertSame([], $this->exchange->failures);
        $this->assertFalse($this->exchange->transports['accepting']->needsHandshake());
        $this->assertNull($this->exchange->transports['accepting']->verifiedPeerName());
        $this->assertSame('gateway', $this->exchange->transports['dialing']->verifiedPeerName());
    }

    public function testNativeGetterIsNullBeforeHandshakeAndReturnsExactlyThePeerLeafAfterIt(): void
    {
        $authority = $this->fixture->issueAuthority('Hilos cluster CA');
        $trust = $this->fixture->trustFile($authority);
        $certificates = ['accepting' => $this->fixture->issueNode('m1', $authority), 'dialing' => $this->fixture->issueNode('m2', $authority)];
        $this->exchange = new TlsTestExchange($this->fixture, $certificates['accepting'], $certificates['dialing'], $trust, $trust);
        $native = new ReflectionProperty(TlsSocketTransport::class, 'event');
        foreach ($this->exchange->transports as $transport) {
            $event = $native->getValue($transport);
            $this->assertInstanceOf(EventBufferEvent::class, $event);
            $this->assertNull($event->sslGetPeerCertificate());
        }
        $this->exchange->handshake();
        $this->assertSame([], $this->exchange->failures);
        foreach ($this->exchange->transports as $end => $transport) {
            $this->assertTrue(openssl_x509_export('file://' . $certificates[$end === 'accepting' ? 'dialing' : 'accepting'], $expected));
            $this->assertSame($expected, $native->getValue($transport)->sslGetPeerCertificate());
        }
    }

    protected function setUp(): void
    {
        $this->fixture = new MutualTlsFixture();
    }

    protected function tearDown(): void
    {
        $this->exchange?->close();
        $this->exchange = null;
        $this->fixture->close();
    }

    /**
     * @param string $end Verifying end expected to refuse
     * @param string $reason Expected native OpenSSL diagnostic
     */
    private function assertRefusal(string $end, string $reason): void
    {
        $refusal = $this->exchange->failures[$end] ?? null;
        $this->assertInstanceOf(SocketTlsHandshakeException::class, $refusal);
        $this->assertStringStartsWith('Socket TLS handshake failed: 127.0.0.1:', $refusal->getMessage());
        $this->assertStringContainsString($reason, $refusal->getMessage());
    }
}
