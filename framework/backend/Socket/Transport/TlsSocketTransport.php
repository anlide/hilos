<?php

declare(strict_types=1);

namespace Hilos\Socket\Transport;

use Closure;
use Event;
use EventBufferEvent;
use EventUtil;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\ReadyWork;
use Hilos\Socket\Exception\SocketReadException;
use Hilos\Socket\Exception\Base\ConnectionResetException;
use Hilos\Socket\Exception\SocketTlsHandshakeException;
use Hilos\Socket\Exception\SocketWriteException;
use Socket;
use WeakReference;

/** Native TLS on the owner's base; application bytes and debts stay with the client until drain. */
final class TlsSocketTransport implements SocketTransportInterface
{
    /** Bounds the additional native copy independently of the client's outbound cap. */
    private const int MAX_IN_FLIGHT_BYTES = 16384;

    private ?Socket $socket;

    private ?EventBufferEvent $event = null;

    private ?EventLoop $loop = null;

    private ?ReadyWork $notification = null;

    private int $generation = 0;

    private int $events = 0;

    /** Native errno captured before another notification changes the process's errno. */
    private int $socketError = 0;

    private bool $handshakeFinished = false;

    private ?string $verifiedPeerName = null;

    private ?string $peerAddress;

    /** Original unacknowledged prefix, still charged to the client's buffer. */
    private ?string $inFlight = null;

    private bool $drained = false;

    /**
     * @param Socket $socket Connected nonblocking socket, owned by this transport
     * @param TlsContext $context Startup-prepared certificate material
     */
    private function __construct(Socket $socket, private readonly TlsContext $context)
    {
        $this->socket = $socket;
        // warning-suppressed: a reset socket has no address; its refusal still carries the TLS reason
        $this->peerAddress = @socket_getpeername($socket, $ip, $port) ? "{$ip}:{$port}" : null;
    }

    /**
     * @param Socket $socket Accepted nonblocking socket
     * @param TlsContext $context Prepared accepting context, including public or mutual trust policy
     * @return self Transport awaiting its client's loop binding
     */
    public static function accepting(Socket $socket, TlsContext $context): self
    {
        return new self($socket, $context);
    }

    /**
     * @param Socket $socket Connected nonblocking socket
     * @param TlsContext $context Prepared dialing context with CA verification
     * @return self Transport awaiting its client's loop binding
     */
    public static function dialing(Socket $socket, TlsContext $context): self
    {
        return new self($socket, $context);
    }

    /**
     * Binds before the first handshake. A live SSL connection retains its original native base.
     *
     * @param EventLoop $loop Owning process's loop
     * @param Closure(): void $wake Enqueue the client's guarded portion
     * @throws SocketTlsHandshakeException When a live connection is moved to another native base
     */
    public function attachEventLoop(EventLoop $loop, Closure $wake): void
    {
        if ($this->event !== null && $this->loop !== $loop) {
            throw new SocketTlsHandshakeException('a live TLS connection cannot change its native base');
        }
        $this->detachEventLoop();
        if ($this->socket === null) {
            return;
        }
        $this->loop = $loop;
        $this->notification = $loop->createReadyWork($wake);
        $generation = $this->generation;
        if ($this->event === null) {
            $base = $loop->getEventBase();
            // No CLOSE_ON_FREE: Socket owns the fd. The ext-event 3.1.6 object destructor
            // releases SSL and the buffer event; explicit free() would skip SSL_free().
            $this->event = EventBufferEvent::sslSocket($base, $this->socket, $this->context->native, $this->context->state);
            $this->event->setWatermark(Event::READ, 1, $this->context->readBufferSize);
        }
        // Native callbacks borrow their owner. At Zend shutdown, releasing a strong capture
        // could destroy the transport and reenter the same buffer event's native destructor.
        $owner = WeakReference::create($this);
        $this->event->setCallbacks(
            static function (EventBufferEvent $event) use ($owner, $generation): void {
                $transport = $owner->get();
                if ($transport === null || $generation !== $transport->generation) {
                    return;
                }
                $event->disable(Event::READ);
                $transport->notifyNative();
            },
            static function (EventBufferEvent $event) use ($owner, $generation): void {
                $transport = $owner->get();
                if ($transport === null || $generation !== $transport->generation) {
                    return;
                }
                if ($transport->inFlight !== null && $event->getOutput()->length === 0) {
                    $transport->drained = true;
                    $event->disable(Event::WRITE);
                }
                $transport->notifyNative();
            },
            static function (EventBufferEvent $event, int $events) use ($owner, $generation): void {
                $transport = $owner->get();
                if ($transport === null || $generation !== $transport->generation) {
                    return;
                }
                $transport->events |= $events;
                if (($events & EventBufferEvent::ERROR) !== 0) {
                    $transport->socketError = (int)EventUtil::getLastSocketErrno();
                }
                $transport->notifyNative();
            },
        );
        $this->event->enable(Event::READ | Event::WRITE);
    }

    /** Cancels callbacks and queued notifications before rebind or close, retaining the TLS session. */
    public function detachEventLoop(): void
    {
        ++$this->generation;
        $this->event?->disable(Event::READ | Event::WRITE);
        // ext-event 3.1.6 does not clear a callback zval when setCallbacks(null)
        // releases it. Replace captures with inert callables before object destruction.
        $ignore = static function (): void {};
        $this->event?->setCallbacks($ignore, $ignore, $ignore);
        $this->notification?->close();
        $this->notification = null;
    }

    /**
     * @param int $length Maximum decrypted bytes to take
     * @return string|false Bytes, or empty while waiting or at EOF
     * @throws SocketReadException When TLS failed after its accepted input was consumed
     * @throws ConnectionResetException When the native socket was reset
     */
    public function read(int $length): string|false
    {
        if ($this->event === null) {
            throw new SocketReadException(0, 'TLS requires its owning event loop before reading');
        }
        // external-boundary: EventBufferEvent::read returns null when its native input buffer is empty
        $data = $this->event->read($length) ?? '';
        if ($data !== '') {
            if ($this->event->getInput()->length > 0 || $this->hasTerminalEvent()) {
                $this->notification?->markReady();
            }
        } elseif (($this->events & EventBufferEvent::ERROR) !== 0) {
            if ($this->socketError === SOCKET_ECONNRESET) {
                throw new ConnectionResetException();
            }
            throw new SocketReadException($this->socketError, $this->describeError());
        }
        if (!$this->hasTerminalEvent()) {
            $this->event?->enable(Event::READ);
        }
        return $data;
    }

    /**
     * Enqueue one bounded prefix, then acknowledge it exactly once after native drain.
     *
     * @param string $data Unchanged prefix with any newly appended tail
     * @return int|false Drained prefix length, or zero while native output is pending
     * @throws SocketWriteException When a queued prefix changed or native enqueue failed
     */
    public function write(string $data): int|false
    {
        if ($this->inFlight !== null) {
            if (!str_starts_with($data, $this->inFlight)) {
                throw new SocketWriteException(0, 'the unacknowledged TLS output prefix changed');
            }
            if (!$this->drained || $this->hasTerminalEvent()) {
                return 0;
            }
            $length = strlen($this->inFlight);
            $this->inFlight = null;
            $this->drained = false;
            if (strlen($data) > $length) {
                $this->notification?->markReady();
            }
            return $length;
        }
        if ($data === '' || $this->event === null || !$this->handshakeFinished || $this->hasTerminalEvent()) {
            return 0;
        }
        $this->inFlight = substr($data, 0, self::MAX_IN_FLIGHT_BYTES);
        if (!$this->event->write($this->inFlight)) {
            throw new SocketWriteException(0, 'native TLS output could not be queued');
        }
        $this->event->enable(Event::WRITE);
        return 0;
    }

    /** Releases native SSL before closing its PHP-owned socket; repeatable. */
    public function close(): void
    {
        $this->detachEventLoop();
        $this->event = null;
        $this->loop = null;
        $this->inFlight = null;
        $this->drained = false;
        if ($this->socket !== null) {
            socket_close($this->socket);
            $this->socket = null;
        }
    }

    /** @return bool Whether a clean terminal event has no decrypted remainder */
    public function isEndOfStream(): bool
    {
        return ($this->events & EventBufferEvent::EOF) !== 0 && ($this->event?->getInput()->length ?? 0) === 0;
    }

    /** @return bool Whether the verified native handshake still needs completion */
    public function needsHandshake(): bool
    {
        return !$this->handshakeFinished;
    }

    /**
     * Reads native status under the client's guard; never pumps TLS or polls a socket.
     *
     * @return int|bool True after verification, zero while pending, false for a public refusal
     * @throws SocketTlsHandshakeException When unbound or when a verified handshake was refused
     */
    public function advanceHandshake(): int|bool
    {
        if ($this->event === null) {
            throw new SocketTlsHandshakeException('TLS requires the owning event loop before its handshake');
        }
        if ($this->hasTerminalEvent()) {
            if ($this->context->verifiesPeer) {
                $reason = $this->describeError();
                throw new SocketTlsHandshakeException($this->peerAddress === null ? $reason : "{$this->peerAddress}: {$reason}");
            }
            return false;
        }
        if (($this->events & EventBufferEvent::CONNECTED) === 0) {
            return 0;
        }
        if (!$this->handshakeFinished) {
            $this->handshakeFinished = true;
            if ($this->context->verifiesPeer) {
                $pem = $this->event->sslGetPeerCertificate();
                $parsed = $pem === null ? false : openssl_x509_parse($pem);
                $name = $parsed === false ? null : ($parsed['subject']['CN'] ?? null);
                $this->verifiedPeerName = is_string($name) ? $name : null;
            }
            $this->notification?->markReady();
        }
        return true;
    }

    /** @return ?string Verified leaf CN, absent until successful authentication and on public ports */
    public function verifiedPeerName(): ?string
    {
        return $this->verifiedPeerName;
    }

    /** Every native notification counts, even when the owner's ticket is already queued. */
    private function notifyNative(): void
    {
        $this->loop->markNativeReady($this->notification);
    }

    /** @return bool Whether native I/O has ended */
    private function hasTerminalEvent(): bool
    {
        return ($this->events & (EventBufferEvent::EOF | EventBufferEvent::ERROR)) !== 0;
    }

    /** @return string Native OpenSSL refusal for the client's contained failure path */
    private function describeError(): string
    {
        $errors = [];
        while ($error = $this->event->sslError()) {
            $errors[] = $error;
        }
        return $errors === [] ? 'the TLS connection ended without a reason' : implode('; ', $errors);
    }
}
