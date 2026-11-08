<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket;

use Closure;
use Event;
use EventBufferEvent;
use EventSslContext;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\ReadyWork;
use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\Transport\TlsContext;
use Hilos\Socket\Transport\TlsSocketTransport;
use PHPUnit\Framework\Assert;
use Throwable;
use Socket;

/** Same-process native TLS, with one bounded owner per end on a single framework loop. */
final class TlsTestExchange
{
    public EventLoop $loop;

    /** @var array<string, TlsSocketTransport> Native transports under test */
    public array $transports = [];

    /** @var array<string, string> Decrypted bytes collected by default owners */
    public array $received = ['accepting' => '', 'dialing' => ''];

    /** @var array<string, Throwable> Original owner failures */
    public array $failures = [];

    /** @var array<string, Socket> Socket ends owned by transports and fixture */
    public array $sockets = [];

    /** @var array<string, ReadyWork> Default protocol owners, replaced before an application binds */
    private array $owners = [];

    /** @var array<string, Closure(): void> Optional per-end process facade setup */
    private array $before = [];

    private ?EventBufferEvent $bareDialer = null;

    private ?EventSslContext $bareContext = null;

    /** @var array<string, AbstractClient> Optional application owners */
    private array $clients = [];

    /**
     * @param MutualTlsFixture $fixture Certificates and sockets for this exchange
     * @param string $acceptingCertificate Server certificate
     * @param ?string $dialingCertificate Client certificate, absent for a native missing-cert peer
     * @param ?string $acceptingTrust Server trust policy
     * @param string $dialingTrust Client trust policy
     */
    public function __construct(
        MutualTlsFixture $fixture,
        string $acceptingCertificate,
        ?string $dialingCertificate,
        ?string $acceptingTrust,
        string $dialingTrust,
    ) {
        $this->loop = new EventLoop();
        [$accepted, $dialed] = $fixture->connectSockets();
        $this->sockets = ['accepting' => $accepted, 'dialing' => $dialed];
        $this->transports['accepting'] = TlsSocketTransport::accepting($accepted, TlsContext::accepting($acceptingCertificate, $acceptingTrust));
        if ($dialingCertificate !== null) {
            $this->transports['dialing'] = TlsSocketTransport::dialing($dialed, TlsContext::dialing($dialingCertificate, $dialingTrust));
        } else {
            $this->bareContext = new EventSslContext(EventSslContext::TLS_CLIENT_METHOD, [
                EventSslContext::OPT_CA_FILE => $dialingTrust,
                EventSslContext::OPT_VERIFY_PEER => true,
            ]);
            $base = $this->loop->getEventBase();
            $this->bareDialer = EventBufferEvent::sslSocket($base, $dialed, $this->bareContext, EventBufferEvent::SSL_CONNECTING);
            $this->bareDialer->enable(Event::READ | Event::WRITE);
        }
        foreach ($this->transports as $end => $transport) {
            $work = $this->owners[$end] = $this->loop->createReadyWork(function () use ($end, $transport): void {
                try {
                    if ($transport->needsHandshake()) {
                        $transport->advanceHandshake();
                        return;
                    }
                    $this->received[$end] .= (string)$transport->read(65536);
                } catch (Throwable $failure) {
                    $this->failures[$end] = $failure;
                }
            });
            $transport->attachEventLoop($this->loop, $work->markReady(...));
        }
    }

    /**
     * @param string $end End whose default reader is replaced by an application client
     * @param AbstractClient $client Owner over that same socket and transport
     * @param ?Closure(): void $before Optional setup for the end's process-global facades
     */
    public function bindClient(string $end, AbstractClient $client, ?Closure $before = null): void
    {
        ($this->owners[$end] ?? null)?->close();
        unset($this->owners[$end]);
        $this->clients[$end] = $client;
        if ($before !== null) {
            $this->before[$end] = $before;
        }
        $client->attachEventLoop($this->loop, function () use ($end, $client, $before): void {
            try {
                if ($before !== null) {
                    $before();
                }
                $client->read();
                $client->onTick();
                $client->write();
            } catch (Throwable $failure) {
                $this->failures[$end] = $failure;
            }
        });
    }

    /**
     * @param callable(): bool $finished I/O completion predicate
     */
    public function runUntil(callable $finished): void
    {
        $expired = false;
        $guard = $this->loop->createTimer(static function () use (&$expired): void {
            $expired = true;
        });
        $guard->armAt(hrtime(true) / 1_000_000_000 + 5.0);
        try {
            while (!$finished() && !$expired) {
                $this->loop->runOnce();
            }
            Assert::assertFalse($expired, 'Native TLS I/O did not complete: ' . json_encode(array_map(
                static fn(Throwable $failure): string => $failure->getMessage(), $this->failures,
            )));
        } finally {
            $guard->close();
        }
    }

    /** Runs only framework readiness until both native handshakes finish or one refuses. */
    public function handshake(): void
    {
        $this->runUntil(function (): bool {
            if ($this->failures !== []) {
                return true;
            }
            foreach ($this->transports as $transport) {
                if ($transport->needsHandshake()) {
                    return false;
                }
            }
            return true;
        });
    }

    /** Releases SSL while contexts and sockets still exist; fixture closes the remaining raw sockets. */
    public function close(): void
    {
        foreach ($this->clients as $end => $client) {
            if (isset($this->before[$end])) {
                ($this->before[$end])();
            }
            $client->close();
        }
        foreach ($this->transports as $transport) {
            $transport->close();
        }
        $this->bareDialer?->disable(Event::READ | Event::WRITE);
        $this->bareDialer = null;
        $this->bareContext = null;
        $this->loop->close();
    }
}
