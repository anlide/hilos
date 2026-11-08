<?php

declare(strict_types=1);

namespace Hilos\Socket\Transport;

use Closure;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\Exception\EventLoopException;

/**
 * Nonblocking bare bytes, with EOF separate from would-block and real errors left on the socket.
 * I/O watches belong to this transport; protocol work belongs to the client's coalesced wake.
 */
final class PlainSocketTransport implements SocketTransportInterface
{
    /** @var resource|object|null Connected socket, null after close */
    private $socket;

    /** @var bool Whether a read has already answered with the peer's close */
    private bool $endOfStream = false;

    private ?EventLoop $loop = null;

    /** @var ?Closure(): void Enqueues the client's work without parsing or writing here */
    private ?Closure $wake = null;

    private bool $readWatching = false;

    private bool $writeWatching = false;

    /**
     * @param resource|object $socket Connected socket resource or Socket object
     */
    public function __construct($socket)
    {
        $this->socket = $socket;
    }

    /**
     * Rebinding removes both old directions and their pending notifications.
     *
     * @param EventLoop $loop Owning process's loop
     * @param Closure(): void $wake Enqueue the client's coalesced, guarded portion
     * @throws EventLoopException When a native transport watch cannot be installed
     */
    public function attachEventLoop(EventLoop $loop, Closure $wake): void
    {
        $this->detachEventLoop();
        if ($this->socket === null) {
            return;
        }

        $this->loop = $loop;
        $this->wake = $wake;
        if (!$this->endOfStream) {
            $this->watchRead();
        }
    }

    /** Drops native references before close, leaving the socket itself owned by this transport. */
    public function detachEventLoop(): void
    {
        if ($this->loop !== null) {
            $this->loop->unregister($this->socket);
        }
        $this->readWatching = false;
        $this->writeWatching = false;
        $this->wake = null;
        $this->loop = null;
    }

    /**
     * Reads with socket_read(); an empty answer is remembered as the end of the stream.
     *
     * @param int $length Most bytes to read in one call
     * @return string|false Bytes read, '' on would-block or EOF, false on a real read failure
     * @throws EventLoopException When a native transport watch cannot be installed
     */
    public function read(int $length): string|false
    {
        // Real errors retain their errno for the client's typed SocketException path;
        // a PHP warning would bypass it and reach the master as a node failure.
        // warning-suppressed: would-block is normalized below; other false returns retain the socket error for the client
        $data = @socket_read($this->socket, $length, PHP_BINARY_READ);

        if ($data === false && $this->isWouldBlock()) {
            socket_clear_error($this->socket);
            $this->watchRead();
            return '';
        }

        if ($data === '') {
            $this->endOfStream = true;
        }
        if ($data === '' || $data === false) {
            $this->loop?->unregisterRead($this->socket);
            $this->readWatching = false;
        } else {
            $this->watchRead();
        }

        return $data;
    }

    /**
     * Writes with socket_write().
     *
     * @param string $data Bytes to send
     * @return int|false Bytes written, 0 while waiting for WRITE, false on a real write failure
     * @throws EventLoopException When a native transport watch cannot be installed
     */
    public function write(string $data): int|false
    {
        if ($data === '') {
            $this->loop?->unregisterWrite($this->socket);
            $this->writeWatching = false;
            return 0;
        }
        if ($this->writeWatching) {
            return 0;
        }

        // Suppress the reset/broken-pipe warning for the same reason as read():
        // let handleSocketError() raise the catchable SocketException instead of a
        // fatal ErrorException.
        // warning-suppressed: would-block is normalized below; other false returns retain the socket error for the client
        $written = @socket_write($this->socket, $data);
        if ($written === false && $this->isWouldBlock()) {
            socket_clear_error($this->socket);
            $written = 0;
        }
        if ($written !== false && $written < strlen($data) && $this->loop !== null) {
            $this->writeWatching = true;
            $this->loop->registerWrite($this->socket, function (): void {
                $this->loop->unregisterWrite($this->socket);
                $this->writeWatching = false;
                ($this->wake)();
            });
        }

        return $written;
    }

    /**
     * Removes both watches before closing the socket; safe to repeat.
     */
    public function close(): void
    {
        $this->detachEventLoop();
        if ($this->socket !== null) {
            socket_close($this->socket);
            $this->socket = null;
        }
    }

    /**
     * On a bare socket an empty read is the end, so this repeats what the last read said.
     *
     * @return bool True once a read has returned the peer's close
     */
    public function isEndOfStream(): bool
    {
        return $this->endOfStream;
    }

    /**
     * A bare socket has no handshake.
     *
     * @return bool Always false
     */
    public function needsHandshake(): bool
    {
        return false;
    }

    /**
     * A bare socket has no handshake, so there is never a step to take.
     *
     * @return int|bool Always true
     */
    public function advanceHandshake(): int|bool
    {
        return true;
    }

    /**
     * A bare socket vouches for nobody: whoever is on the other end says who it is, and nothing checks it.
     *
     * @return ?string Always null
     */
    public function verifiedPeerName(): ?string
    {
        return null;
    }

    /** A level still readable after one bounded portion wakes the owner on the next poll. */
    private function watchRead(): void
    {
        if ($this->loop === null || $this->readWatching || $this->endOfStream) {
            return;
        }

        $this->readWatching = true;
        $this->loop->registerRead($this->socket, function (): void {
            $this->loop->unregisterRead($this->socket);
            $this->readWatching = false;
            ($this->wake)();
        });
    }

    /** @return bool Whether this failed operation only exhausted currently available socket capacity */
    private function isWouldBlock(): bool
    {
        $error = socket_last_error($this->socket);
        return $error === SOCKET_EAGAIN || $error === SOCKET_EWOULDBLOCK;
    }
}
