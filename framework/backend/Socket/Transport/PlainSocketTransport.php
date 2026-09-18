<?php

declare(strict_types=1);

namespace Hilos\Socket\Transport;

/**
 * A connection whose bytes travel bare - the transport every server had before there was a seam.
 *
 * Its reads and writes are the socket calls the client used to make itself, word for word, and
 * so are their answers: a failed call returns false and leaves the error code on the socket for
 * the client to read, and an empty read is the peer closing - "nothing yet" arrives as false with
 * EAGAIN instead, which the client's error handling already swallows.
 */
final class PlainSocketTransport implements SocketTransportInterface
{
    /** @var resource|object Connected socket */
    private $socket;

    /** @var bool Whether a read has already answered with the peer's close */
    private bool $endOfStream = false;

    /**
     * @param resource|object $socket Connected socket resource or Socket object
     */
    public function __construct($socket)
    {
        $this->socket = $socket;
    }

    /**
     * Reads with socket_read(); an empty answer is remembered as the end of the stream.
     *
     * @param int $length Most bytes to read in one call
     * @return string|false Bytes read, '' when the peer closed, false on a failed read (EAGAIN included)
     */
    public function read(int $length): string|false
    {
        // Suppress the PHP warning a reset/broken peer raises (ECONNRESET, EPIPE,
        // EAGAIN, ...): otherwise the global errorHandler converts it to a generic
        // ErrorException, which names no socket error and reaches AbstractServer's
        // tick guard as a node failure rather than the routine drop it is.
        // Suppressed, socket_read returns false and handleSocketError() raises the
        // proper SocketException the loop already closes the client on. Surfaced
        // live by the peer mesh (HIL-185), whose duplicate-link collapse and node
        // kills reset peer links routinely.
        // warning-suppressed: a false return goes to handleSocketError(), which reads the error code
        $data = @socket_read($this->socket, $length, PHP_BINARY_READ);

        if ($data === '') {
            $this->endOfStream = true;
        }

        return $data;
    }

    /**
     * Writes with socket_write().
     *
     * @param string $data Bytes to send
     * @return int|false Bytes written, false on a failed write (EAGAIN included)
     */
    public function write(string $data): int|false
    {
        // Suppress the reset/broken-pipe warning for the same reason as read():
        // let handleSocketError() raise the catchable SocketException instead of a
        // fatal ErrorException.
        // warning-suppressed: a false return goes to handleSocketError(), which reads the error code
        return @socket_write($this->socket, $data);
    }

    /**
     * Closes the socket with socket_close().
     */
    public function close(): void
    {
        socket_close($this->socket);
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
}
