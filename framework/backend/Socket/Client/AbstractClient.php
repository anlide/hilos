<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\AbstractSocket;
use Hilos\Socket\Exception\SocketTlsHandshakeException;
use Hilos\Socket\SocketException;
use Hilos\Socket\SocketOperation;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\Socket\Transport\SocketTransportInterface;
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

    /** @var SocketTransportInterface What carries the bytes: the bare socket, or a TLS session over it */
    private SocketTransportInterface $transport;

    /**
     * Create client with socket and read buffer size from env.
     *
     * A client created without a transport reads and writes the bare socket, which is what
     * every existing subclass gets by calling `parent::__construct($socket)`.
     *
     * @param resource|object $socket Client socket resource or Socket object
     * @param ?SocketTransportInterface $transport Transport over that socket, the bare one when null
     * @throws EnvException When socket read buffer env value is missing or invalid
     */
    public function __construct($socket, ?SocketTransportInterface $transport = null)
    {
        $this->socket = $socket;
        $this->transport = $transport ?? new PlainSocketTransport($socket);

        $this->readBufferSize = Hilos::$env[EnvConstants::SOCKET_READ_BUFFER_SIZE]->int();
    }

    /**
     * Read data from client socket.
     *
     * While a handshake still stands before the first application byte, a read only moves it on.
     *
     * @throws SocketException If socket read fails
     * @throws SocketTlsHandshakeException When a transport that verifies its peer refuses the handshake
     *                                     (a SocketException, contained like any other failed read)
     * @throws HilosException When buffered wire input refuses to become a DTO
     * @throws RandomException When the secure random source refuses a handshake secret
     */
    public function read(): void
    {
        // Skip if already marked for closing to prevent redundant processing
        if ($this->shouldClose) {
            return;
        }

        if ($this->transport->needsHandshake()) {
            $this->advanceHandshake();
            return;
        }

        $data = $this->transport->read($this->readBufferSize);

        // An empty read closes the connection only when the transport says the stream ended.
        // On a bare socket it always has; on an encrypted stream the socket also turns readable
        // for protocol bytes that decrypt to nothing, and closing there would cut a live
        // connection short - the mirror of the false end of a response in HIL-732.
        if ($data === '') {
            if ($this->transport->isEndOfStream()) {
                $this->shouldClose = true;
            }
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
     * While a handshake still stands before the first application byte, a write only moves it on.
     *
     * @throws SocketException If socket write fails
     * @throws SocketTlsHandshakeException When a transport that verifies its peer refuses the handshake
     *                                     (a SocketException, contained like any other failed write)
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

        // The application buffer waits for the handshake: nothing written before it finishes
        // could be read by the peer anyway.
        if ($this->transport->needsHandshake()) {
            $this->advanceHandshake();
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
        $written = $this->transport->write($this->writeBuffer);

        if ($written === false) {
            $this->handleSocketError(SocketOperation::WRITE);
            return;
        }

        // Nothing taken is not a failure: an encrypted stream answers "offer the same bytes
        // again later" this way, and the buffer is left exactly as it is for the next turn.
        if ($written === 0) {
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
            $this->transport->close();
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

    /**
     * Moves the transport's handshake one step, and closes the connection when the peer is refused.
     *
     * A refused peer gets no answer at all: a side that did not agree on encryption could not
     * read one.
     *
     * @throws SocketTlsHandshakeException When a transport that verifies its peer names the refusal
     */
    private function advanceHandshake(): void
    {
        if ($this->transport->advanceHandshake() === false) {
            $this->markShouldClose();
        }
    }
}
