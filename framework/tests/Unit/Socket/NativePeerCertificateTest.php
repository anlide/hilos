<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use Event;
use EventBase;
use EventBufferEvent;
use EventListener;
use EventSslContext;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;

/** Real native TLS: certificate retrieval is separate from handshake trust and peer identity. */
final class NativePeerCertificateTest extends TestCase
{
    private const string ACCEPTING = 'accepting';

    private const string DIALING = 'dialing';

    private MutualTlsFixture $fixture;

    private ?EventBase $base = null;

    private ?EventListener $listener = null;

    /** @var array<string, EventSslContext> Contexts kept alive until their native SSL objects are freed */
    private array $contexts = [];

    /** @var array<string, EventBufferEvent> TLS connections owned by this exchange */
    private array $ends = [];

    /** @var array<string, int> Native event flags observed on each end */
    private array $events = [];

    /** @var array<string, list<string>> Native OpenSSL errors on each end */
    private array $errors = [];

    /** @var array<string, string> Application bytes received on each end */
    private array $received = [];

    /** Both native ends authenticate, expose the opposite leaf and keep working after repeated getter calls. */
    public function testBothNativeEndsExposeTheirPeerLeafAfterMutualTls(): void
    {
        $method = new ReflectionMethod(EventBufferEvent::class, 'sslGetPeerCertificate');
        $this->assertTrue($method->isInternal());
        $this->assertTrue($method->isPublic());
        $this->assertFalse($method->isStatic());
        $this->assertSame(0, $method->getNumberOfParameters());
        $type = $method->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame('string', $type->getName());
        $this->assertTrue($type->allowsNull());

        $authority = $this->fixture->issueAuthority('Hilos cluster CA');
        $acceptingCertificate = $this->fixture->issueNode('m1', $authority);
        $dialingCertificate = $this->fixture->issueNode('m2', $authority);
        $this->startExchange($acceptingCertificate, $dialingCertificate, $this->fixture->trustFile($authority));
        $this->pumpUntil(fn(): bool => $this->connected(self::ACCEPTING) && $this->connected(self::DIALING));
        $this->assertSame([], $this->errors[self::ACCEPTING]);
        $this->assertSame([], $this->errors[self::DIALING]);
        $this->assertPeerLeaf(self::ACCEPTING, $dialingCertificate, 'm2');
        $this->assertPeerLeaf(self::DIALING, $acceptingCertificate, 'm1');

        $this->assertTrue($this->ends[self::DIALING]->write('ping'));
        $this->assertTrue($this->ends[self::ACCEPTING]->write('pong'));
        $this->pumpUntil(fn(): bool => $this->received[self::ACCEPTING] === 'ping' && $this->received[self::DIALING] === 'pong');
        $this->assertPeerLeaf(self::ACCEPTING, $dialingCertificate, 'm2');
        $this->assertPeerLeaf(self::DIALING, $acceptingCertificate, 'm1');

        $plain = new EventBufferEvent($this->base);
        try {
            $this->assertNull($plain->sslGetPeerCertificate(), 'a plain buffer event has no SSL object');
        } finally {
            $plain->free();
        }
        $this->assertNull($plain->sslGetPeerCertificate(), 'a freed buffer event has no SSL object');
    }

    /** Requiring a client certificate fails in OpenSSL before the acceptor reports a completed handshake. */
    public function testNativeAcceptorRejectsAMissingClientCertificate(): void
    {
        $authority = $this->fixture->issueAuthority('Hilos cluster CA');
        $this->startExchange($this->fixture->issueNode('m1', $authority), null, $this->fixture->trustFile($authority));
        $this->pumpUntil(fn(): bool => ($this->events[self::ACCEPTING] & EventBufferEvent::ERROR) !== 0);
        $this->assertFalse($this->connected(self::ACCEPTING));
        $this->assertStringContainsString('peer did not return a certificate', implode('\n', $this->errors[self::ACCEPTING]));
        $this->assertNull($this->ends[self::ACCEPTING]->sslGetPeerCertificate());
    }

    /** Each end independently refuses a peer signed by a CA outside its trust file. */
    public function testNativeTlsRejectsAForeignAuthorityInEitherDirection(): void
    {
        $authority = $this->fixture->issueAuthority('Hilos cluster CA');
        $foreign = $this->fixture->issueAuthority('Someone else CA');
        $trustFile = $this->fixture->trustFile($authority);
        foreach ([self::ACCEPTING, self::DIALING] as $verifier) {
            $this->startExchange(
                $this->fixture->issueNode('m1', $verifier === self::DIALING ? $foreign : $authority),
                $this->fixture->issueNode('m2', $verifier === self::ACCEPTING ? $foreign : $authority),
                $trustFile,
            );
            $this->pumpUntil(fn(): bool => ($this->events[$verifier] & EventBufferEvent::ERROR) !== 0);
            $this->assertFalse($this->connected($verifier), $verifier . ' must not authenticate a foreign CA');
            $this->assertStringContainsString('certificate verify failed', implode('\n', $this->errors[$verifier]));
            $this->closeExchange();
        }
    }

    /** Gives one test its own certificates and loopback listeners. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new MutualTlsFixture();
    }

    /** Frees native handles before their contexts, listener sockets and certificate files. */
    protected function tearDown(): void
    {
        $this->closeExchange();
        $this->fixture->close();
        parent::tearDown();
    }

    /**
     * Configures real native SSL sockets with CA verification at both ends, requiring a client certificate.
     *
     * @param string $acceptingCertificate Server certificate and key PEM file
     * @param ?string $dialingCertificate Client certificate and key PEM file, absent for the missing-cert case
     * @param string $trustFile Trusted CA file used by both ends
     */
    private function startExchange(string $acceptingCertificate, ?string $dialingCertificate, string $trustFile): void
    {
        $this->base = new EventBase();
        $this->events = [self::ACCEPTING => 0, self::DIALING => 0];
        $this->errors = [self::ACCEPTING => [], self::DIALING => []];
        $this->received = [self::ACCEPTING => '', self::DIALING => ''];
        $verification = [
            EventSslContext::OPT_CA_FILE => $trustFile,
            EventSslContext::OPT_VERIFY_PEER => true,
            EventSslContext::OPT_ALLOW_SELF_SIGNED => false,
        ];
        $this->contexts[self::ACCEPTING] = new EventSslContext(EventSslContext::TLS_SERVER_METHOD, $verification + [
            EventSslContext::OPT_LOCAL_CERT => $acceptingCertificate,
            EventSslContext::OPT_LOCAL_PK => $acceptingCertificate,
            EventSslContext::OPT_REQUIRE_CLIENT_CERT => true,
        ]);
        $this->contexts[self::DIALING] = new EventSslContext(EventSslContext::TLS_CLIENT_METHOD, $verification + (
            $dialingCertificate === null ? [] : [
                EventSslContext::OPT_LOCAL_CERT => $dialingCertificate,
                EventSslContext::OPT_LOCAL_PK => $dialingCertificate,
            ]
        ));
        $socket = $this->fixture->listen();
        $this->assertTrue(socket_getsockname($socket, $address, $port));
        // The fixture owns the listener Socket. Native buffer events own the accepted and dialed descriptors.
        $this->listener = new EventListener($this->base, function (EventListener $listener, int $descriptor): void {
            $listener->disable();
            $this->attachEnd(self::ACCEPTING, $descriptor, EventBufferEvent::SSL_ACCEPTING);
        }, null, 0, -1, $socket);
        $this->attachEnd(self::DIALING, null, EventBufferEvent::SSL_CONNECTING);
        $this->assertTrue($this->ends[self::DIALING]->connect('127.0.0.1:' . $port));
    }

    /**
     * @param string $end End being configured
     * @param ?int $descriptor Accepted native descriptor, or null for a new dialing socket
     * @param int $state Native SSL handshake role
     */
    private function attachEnd(string $end, ?int $descriptor, int $state): void
    {
        $event = EventBufferEvent::sslSocket(
            $this->base,
            $descriptor,
            $this->contexts[$end],
            $state,
            EventBufferEvent::OPT_CLOSE_ON_FREE,
        );
        $this->ends[$end] = $event;
        $this->assertNull($event->sslGetPeerCertificate(), $end . ' has no leaf before the handshake');
        $event->setCallbacks(
            function (EventBufferEvent $event) use ($end): void {
                $this->received[$end] .= $event->read(8192);
            },
            null,
            function (EventBufferEvent $event, int $events) use ($end): void {
                $this->events[$end] |= $events;
                if (($events & EventBufferEvent::ERROR) !== 0) {
                    while ($error = $event->sslError()) {
                        $this->errors[$end][] = $error;
                    }
                }
            },
        );
        $this->assertTrue($event->enable(Event::READ | Event::WRITE));
    }

    /**
     * @param string $end End whose successful native handshake is queried
     * @return bool Whether the native CONNECTED callback was delivered
     */
    private function connected(string $end): bool
    {
        return ($this->events[$end] & EventBufferEvent::CONNECTED) !== 0;
    }

    /**
     * Waits only for I/O completion; the deadline bounds a broken exchange, not a production timer.
     *
     * @param callable(): bool $finished Completion predicate for this exchange
     */
    private function pumpUntil(callable $finished): void
    {
        $deadline = hrtime(true) + 5_000_000_000;
        do {
            $this->base->loop(EventBase::LOOP_NONBLOCK);
            if ($finished()) {
                return;
            }
            usleep(500);
        } while (hrtime(true) < $deadline);

        $this->fail('Native TLS exchange did not complete: ' . json_encode($this->errors, JSON_THROW_ON_ERROR));
    }

    /**
     * @param string $end End reading its peer's leaf
     * @param string $certificateFile Expected leaf and key PEM file
     * @param string $name Expected authenticated node common name
     */
    private function assertPeerLeaf(string $end, string $certificateFile, string $name): void
    {
        $pem = $this->ends[$end]->sslGetPeerCertificate();
        $this->assertIsString($pem);
        $this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', $pem);
        $this->assertTrue(openssl_x509_export('file://' . $certificateFile, $expected));
        $this->assertSame($expected, $pem, 'the getter returns exactly the opposite leaf, with no key or chain');
        $parsed = openssl_x509_parse($pem);
        $this->assertIsArray($parsed);
        $this->assertSame($name, $parsed['subject']['CN']);
        $this->assertSame($pem, $this->ends[$end]->sslGetPeerCertificate(), 'the getter does not consume the leaf');
    }

    /** Frees SSL and sockets while the base and callback contexts still exist. */
    private function closeExchange(): void
    {
        foreach ($this->ends as $event) {
            $event->free();
            $this->assertNull($event->sslGetPeerCertificate(), 'free releases the native TLS handle');
        }
        $this->ends = [];
        $this->listener?->free();
        $this->listener = null;
        $this->contexts = [];
        $this->base?->free();
        $this->base = null;
    }
}
