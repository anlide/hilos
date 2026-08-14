<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\AbstractSocket;
use Hilos\Socket\SocketException;
use Hilos\Socket\SocketOperation;
use Hilos\Utils\Helpers\HttpHeaderHelper;
use Hilos\Utils\Logger;
use Random\RandomException;
use TypeError;

/**
 * AbstractClient - Abstract base class for client implementations.
 *
 * Provides common functionality for all client types.
 */
abstract class AbstractClient extends AbstractSocket implements ClientInterface
{
    /** @var int Read buffer size in bytes (can be overridden in child classes or via config) */
    protected int $readBufferSize;

    /** @var string Buffer for incoming data */
    protected string $readBuffer = '';

    /** @var string Buffer for outgoing data */
    protected string $writeBuffer = '';

    /**
     * Backpressure cap on the outbound buffer in bytes; 0 disables it (the default,
     * so HTTP/WebSocket responses stay unbounded). A subclass that talks to a fixed
     * peer set (the cluster peer link) sets a cap so a peer that stops draining
     * (hung, overwhelmed) drops that one link instead of growing the buffer until
     * the whole daemon hits the PHP memory limit and crash-loops.
     */
    protected int $maxWriteBufferBytes = 0;

    /** @var bool Flag indicating if client should be closed */
    protected bool $shouldClose = false;

    /**
     * When true, {@see shouldClose} is set only after {@see writeBuffer} has been fully flushed.
     * Used by HttpClient for Connection: close without truncating large responses on partial writes.
     */
    protected bool $closeWhenOutputDrained = false;

    /**
     * Create client with socket and read buffer size from env.
     *
     * @param resource|object $socket Client socket resource or Socket object
     * @throws EnvException When socket read buffer env value is missing or invalid
     */
    public function __construct($socket)
    {
        $this->socket = $socket;

        $this->readBufferSize = Hilos::$env->int(EnvConstants::SOCKET_READ_BUFFER_SIZE);
    }

    /**
     * Read data from client socket.
     *
     * @throws SocketException If socket read fails
     * @throws HilosException When buffered wire input refuses to become a DTO
     * @throws RandomException When the secure random source refuses a handshake secret
     */
    public function read(): void
    {
        // Skip if already marked for closing to prevent redundant processing
        if ($this->shouldClose) {
            return;
        }

        // Suppress the PHP warning a reset/broken peer raises (ECONNRESET, EPIPE,
        // EAGAIN, ...): otherwise the global errorHandler converts it to a generic
        // ErrorException that escapes AbstractServer::onTick()'s HilosException
        // guard and tears the whole daemon down. Suppressed, socket_read returns
        // false and handleSocketError() raises the proper SocketException the loop
        // already closes the client on. Surfaced live by the peer mesh (HIL-185),
        // whose duplicate-link collapse and node kills reset peer links routinely.
        // warning-suppressed: a false return goes to handleSocketError(), which reads the error code
        $data = @socket_read($this->socket, $this->readBufferSize, PHP_BINARY_READ);

        // Empty string means connection closed gracefully
        if ($data === '') {
            $this->shouldClose = true;
            return;
        }

        // False means error occurred
        if ($data === false) {
            $this->handleSocketError(SocketOperation::READ);
            return;
        }

        $this->readBuffer .= $data;
        $this->processReadBuffer();
    }

    /**
     * Write buffered data to socket.
     *
     * @throws SocketException If socket write fails
     * @throws HilosException When buffered wire input refuses to become a DTO
     */
    public function write(): void
    {
        if ($this->writeBuffer === '') {
            return;
        }

        if ($this->shouldClose) {
            return;
        }

        // Backpressure: a capped client whose peer has stopped draining is dropped
        // rather than buffered to the process memory limit. Closing the one bad link
        // is recoverable (the mesh re-dials); an OOM takes the whole daemon down.
        if ($this->maxWriteBufferBytes > 0 && strlen($this->writeBuffer) > $this->maxWriteBufferBytes) {
            Logger::warning(
                "Outbound buffer exceeded {$this->maxWriteBufferBytes} bytes; dropping the link to shed backpressure",
            );
            $this->writeBuffer = '';
            $this->markShouldClose();
            return;
        }

        $bufferLength = strlen($this->writeBuffer);
        // Suppress the reset/broken-pipe warning for the same reason as read():
        // let handleSocketError() raise the catchable SocketException instead of a
        // fatal ErrorException.
        // warning-suppressed: a false return goes to handleSocketError(), which reads the error code
        $written = @socket_write($this->socket, $this->writeBuffer);

        if ($written === false) {
            $this->handleSocketError(SocketOperation::WRITE);
            return;
        }

        // Log if we didn't write everything (partial write)
        if ($written < $bufferLength) {
            Logger::debug("Partial write: {$written}/{$bufferLength} bytes written");
        }

        $this->writeBuffer = substr($this->writeBuffer, $written);

        if ($this->writeBuffer === '') {
            if ($this->closeWhenOutputDrained) {
                $this->closeWhenOutputDrained = false;
                $this->shouldClose = true;
            } else {
                $this->onAfterOutboundDrained();
            }
        }
    }

    /**
     * Called when the outbound buffer becomes empty and the connection is not scheduled for close.
     * HttpClient uses this to process pipelined or subsequent HTTP requests on keep-alive.
     *
     * @throws HilosException When buffered wire input refuses to become a DTO
     */
    protected function onAfterOutboundDrained(): void
    {
    }

    /**
     * Mark socket for closing (abstract implementation from AbstractSocket).
     */
    public function markShouldClose(): void
    {
        $this->shouldClose = true;
    }

    /**
     * Check if client should be closed
     *
     * @return bool True if should close
     */
    public function shouldClose(): bool
    {
        return $this->shouldClose;
    }

    /**
     * Close client connection
     *
     * Idempotent method - can be called multiple times safely.
     * Sets socket to null after successful close to prevent double close.
     *
     * @throws SocketException If socket close fails
     * @throws HilosException When the subclass fails to announce the close
     */
    public function close(): void
    {
        if ($this->socket === null) {
            return; // Already closed
        }

        if (!is_resource($this->socket) && !is_object($this->socket)) {
            $this->socket = null;
            return; // Already closed or invalid
        }

        try {
            // socket_close returns void
            socket_close($this->socket);
        } catch (TypeError $e) {
            // Socket already closed or invalid - ignore
            $this->socket = null;
            return;
        }

        // Set to null after successful close to prevent double close
        $this->socket = null;

        // Check if there was an error during close
        $this->handleSocketError(SocketOperation::CLOSE);

        // Call onClose callback
        $this->onClose();
    }

    /**
     * Get client IP address from socket
     *
     * Uses socket_getpeername to retrieve client IP address.
     * Returns empty string if unavailable (non-critical operation).
     *
     * @return string Client IP address (IPv4 or IPv6) or empty string if unavailable
     * @throws SocketException If getpeername fails
     */
    protected function getClientIp(): string
    {
        $ip = '';

        // socket_getpeername doesn't require port parameter
        if (socket_getpeername($this->socket, $ip)) {
            return $ip;
        }

        // Check for errors (non-critical operation, just clear error state)
        $this->handleSocketError(SocketOperation::GETPEERNAME);

        return '';
    }

    /**
     * Parse HTTP headers from request lines after the request line.
     *
     * Header names are normalized to lowercase (RFC 7230 treats field names as
     * case-insensitive, and e.g. Node clients send them lowercase); header
     * values keep their original case.
     *
     * @param list<string> $lines Request lines from explode of raw request
     * @return array<string, string> Lowercase header name to value map
     */
    protected function parseHeaders(array $lines): array
    {
        $headers = [];
        for ($i = 1; $i < count($lines); $i++) {
            if ($lines[$i] === '') {
                break;
            }
            $headerParts = explode(':', $lines[$i], 2);
            if (count($headerParts) === 2) {
                $headers[strtolower(trim($headerParts[0]))] = trim($headerParts[1]);
            }
        }

        return $headers;
    }

    /**
     * Parse cookies from Cookie header.
     *
     * @param array<string, string> $headers HTTP headers map
     * @return array<string, string> Cookie name => value pairs
     */
    protected function parseCookies(array $headers): array
    {
        return HttpHeaderHelper::parseCookies($headers);
    }

    /**
     * Process read buffer - must be implemented by child classes.
     *
     * @throws HilosException When buffered wire input refuses to become a DTO
     * @throws RandomException When the secure random source refuses a handshake secret
     */
    abstract protected function processReadBuffer(): void;

    /**
     * Tick method - called on each server tick.
     *
     * Must be implemented by child classes to perform periodic operations (e.g., timeout checks).
     * Can be left empty if no periodic operations are needed.
     */
    abstract public function onTick(): void;

    /**
     * Called when socket connection is successfully closed.
     *
     * This method is called after socket_close() completes without errors.
     * Can be overridden in child classes to perform cleanup or logging.
     *
     * @throws HilosException When the subclass fails to announce the close
     */
    abstract protected function onClose(): void;
}
