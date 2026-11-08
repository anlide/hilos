<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Closure;
use Hilos\Constants\EnvConstants;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Core\EventLoop\ReadyWork;
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
    /** Complete messages per parser portion; bounds handler work independently of input bytes. */
    protected const int MAX_MESSAGES_PER_READ = 64;

    /** @var int Maximum bytes one read() takes from this socket */
    protected int $readBufferSize;

    /** @var string Buffer for incoming data */
    protected string $readBuffer = '';

    /** @var string Buffer for outgoing data */
    protected string $writeBuffer = '';

    /**
     * Backpressure cap on the outbound buffer in bytes; 0 disables it (the default,
     * so HTTP responses stay unbounded). A subclass whose peer may stop draining (the
     * cluster peer link, a browser socket) sets a cap so a hung or overwhelmed peer
     * drops that one link instead of growing the buffer until the whole daemon hits
     * the PHP memory limit and crash-loops.
     */
    protected int $maxWriteBufferBytes = 0;

    /** @var bool Flag indicating if client should be closed */
    protected bool $shouldClose = false;

    /**
     * When true, {@see shouldClose} is set only after {@see writeBuffer} has been fully flushed.
     * Used by HttpClient for Connection: close without truncating large responses on partial writes.
     */
    protected bool $closeWhenOutputDrained = false;

    /** Discard bounded portions of input without parsing; queued output continues until EOF or a real read error. */
    private bool $readingStopped = false;

    /** @var SocketTransportInterface What carries the bytes: the bare socket, or a TLS session over it */
    private SocketTransportInterface $transport;

    /** Process-local work, owned by this connection and released before its socket. */
    private ?ReadyWork $readyWork = null;

    /** A complete buffered message owes the next owner portion, even without new socket bytes. */
    private bool $readBufferReady = false;

    /** A terminal read waits only for already actionable parser portions, never for more input. */
    private bool $readEnded = false;

    /** Original read failure retained until the accepted prefix has been dispatched. */
    private ?SocketException $pendingReadFailure = null;

    /** HTTP may write its response while parsing; other output follows the accepted input prefix. */
    private bool $processingReadBuffer = false;

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
     * The owner retains its read/tick/write guard and dropClient() exit inside the callback.
     *
     * @param EventLoop $loop Owning master's loop
     * @param Closure(): void $wake One bounded, guarded client portion
     * @throws SocketTlsHandshakeException When a live TLS session is rebound to another native base
     * @throws EventLoopException When a client protocol deadline cannot bind to the native loop
     */
    public function attachEventLoop(EventLoop $loop, Closure $wake): void
    {
        $this->detachEventLoop();
        if ($this->socket === null) {
            return;
        }

        $this->readyWork = $loop->createReadyWork($wake);
        $this->transport->attachEventLoop($loop, $this->readyWork->markReady(...));
        $this->markReady();
    }

    /** Releases this binding's queued portion and transport watches; safe to repeat. */
    public function detachEventLoop(): void
    {
        $this->transport->detachEventLoop();
        $this->readyWork?->close();
        $this->readyWork = null;
    }

    /**
     * Resume one buffered parser portion, or collect up to readBufferSize new bytes.
     * EOF/errors follow actionable accepted input; stopped or blocked parsing never delays close.
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

        if ($this->readBufferReady || $this->readEnded) {
            $this->readBufferReady = false;
            if (!$this->readingStopped) {
                $this->processReadPortion();
            }
            $this->finishReadEnd();
            if (!$this->readBufferReady && !$this->shouldClose) {
                // A native READ notification may have coalesced with this parser continuation.
                // One later transport probe consumes that readiness and rearms its one-shot watch.
                $this->markReady();
            }
            return;
        }

        $data = '';
        $readFailure = null;
        do {
            try {
                $chunk = $this->transport->read($this->readBufferSize - strlen($data));
            } catch (SocketException $failure) {
                $readFailure = $failure;
                $chunk = false;
            }
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $data .= $chunk;
        } while (strlen($data) < $this->readBufferSize);

        if ($data !== '' && !$this->readingStopped) {
            $this->readBuffer .= $data;
            $this->onReadData();
            $this->processReadPortion();
        }

        if ($chunk === false) {
            if (!$this->readBufferReady || $this->shouldClose || $this->readingStopped) {
                if ($readFailure !== null) {
                    throw $readFailure;
                }
                $this->handleSocketError(SocketOperation::READ);
            } else {
                try {
                    if ($readFailure !== null) {
                        throw $readFailure;
                    }
                    $this->handleSocketError(SocketOperation::READ);
                } catch (SocketException $failure) {
                    // Keep the original errno-to-exception mapping before another operation can
                    // clear errno. Only the budget yielded: the accepted prefix still comes first.
                    $this->shouldClose = false;
                    $this->pendingReadFailure = $failure;
                    $this->readEnded = true;
                }
            }
        } elseif ($chunk === '' && $this->transport->isEndOfStream()) {
            // TLS protocol bytes may produce an empty read without ending the stream.
            $this->readEnded = true;
        }
        $this->finishReadEnd();
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
        if (($this->readEnded || $this->readBufferReady) && !$this->processingReadBuffer) {
            return;
        }

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

        $this->enforceOutboundCap();
        if ($this->shouldClose) {
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
                $this->markShouldClose();
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

    /** @return int Pending bytes protected by this client's ordinary outbound cap */
    protected function outboundBackpressureBytes(): int
    {
        return strlen($this->writeBuffer);
    }

    /**
     * Backpressure: a capped client whose peer has stopped draining is dropped rather than
     * buffered to the process memory limit. Closing the one bad link is recoverable (the mesh
     * re-dials, a browser reconnects); an OOM takes the whole daemon down.
     *
     * Exactly at the cap the client stays, and an uncapped one is never dropped here. write()
     * checks before handing the buffer to the transport; a subclass whose bytes can grow while
     * write() returns before that check calls it where they grow.
     */
    protected function enforceOutboundCap(): void
    {
        if ($this->maxWriteBufferBytes <= 0 || $this->shouldClose
            || $this->outboundBackpressureBytes() <= $this->maxWriteBufferBytes
        ) {
            return;
        }

        Logger::warning(
            "Outbound buffer exceeded {$this->maxWriteBufferBytes} bytes; dropping the link to shed backpressure",
        );
        $this->writeBuffer = '';
        $this->markShouldClose();
    }

    /**
     * Mark socket for closing (abstract implementation from AbstractSocket).
     */
    public function markShouldClose(): void
    {
        if ($this->shouldClose) {
            return;
        }

        $this->shouldClose = true;
        $this->markReady();
    }

    /**
     * Stop delivering input to the protocol while continuing to write to the connection.
     *
     * Further reads discard bounded, non-blocking portions without changing readBuffer.
     * EOF or a real read error marks the connection for close even with queued output.
     */
    public function stopReading(): void
    {
        $this->readingStopped = true;
        $this->readBufferReady = false;
    }

    /**
     * Close once everything queued for this connection has been handed to the socket.
     *
     * An empty buffer closes at once because write() returns before its drained branch.
     *
     * @throws EventLoopException When a client protocol deadline cannot be armed
     */
    public function closeOnceWritten(): void
    {
        if ($this->writeBuffer === '') {
            $this->markShouldClose();
            return;
        }

        $this->closeWhenOutputDrained = true;
        $this->markReady();
    }

    /**
     * @return bool Whether bytes remain in the outbound buffer
     */
    public function hasPendingWrite(): bool
    {
        return $this->writeBuffer !== '';
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
     * socket_close() reports no error, so there is nothing of its own to check here.
     * The last socket error held by this process belongs to an earlier operation,
     * often the read that found a peer reset. Checking it again raised that failure
     * a second time and skipped onClose(), leaving a killed worker's agents on the
     * roster (HIL-1162).
     *
     * @throws HilosException When the subclass fails to announce the close
     */
    public function close(): void
    {
        $this->detachEventLoop();
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

        // Call onClose callback
        $this->onClose();
    }

    /**
     * Keeps byte ordering and cap/debt accounting at their existing protocol-owned sites.
     *
     * @param string $bytes Newly serialized output
     * @param bool $replace Whether an HTTP response replaces the current response buffer
     */
    protected function queueWrite(string $bytes, bool $replace = false): void
    {
        if ($replace) {
            $this->writeBuffer = $bytes;
        } else {
            $this->writeBuffer .= $bytes;
        }
        if ($bytes !== '') {
            $this->markReady();
        }
    }

    /** Marks newly actionable work once; no effect before attachment or after detach. */
    protected function markReady(): void
    {
        $this->readyWork?->markReady();
    }

    /**
     * Defer complete input, or one probe after a real unblock, to read().
     * An incomplete/held parser does not call this again until new input or another release.
     */
    protected function continueReadBuffer(): void
    {
        if ($this->readingStopped || $this->shouldClose || $this->readBuffer === '') {
            return;
        }
        $this->readBufferReady = true;
        $this->markReady();
    }

    /**
     * Take the next JSON message, or yield before consuming it when this portion is full.
     * Look ahead on a copy only at the boundary, keeping raw bytes and their debts in readBuffer.
     *
     * @param int $processed Complete messages already handled in this portion
     * @return ?string Next message, or null when incomplete or deferred to the next portion
     * @throws SocketException When input exceeds the existing framing limits
     */
    protected function extractReadBufferJsonMessage(int $processed): ?string
    {
        if ($processed < self::MAX_MESSAGES_PER_READ) {
            return $this->extractCompleteJsonMessage($this->readBuffer);
        }

        $remaining = $this->readBuffer;
        if ($this->extractCompleteJsonMessage($remaining) !== null) {
            $this->continueReadBuffer();
        }
        return null;
    }

    /**
     * Records actual inbound bytes; buffered continuations do not renew transport liveness.
     *
     * @throws HilosException When a protocol's renewed liveness deadline cannot be armed
     */
    protected function onReadData(): void
    {
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
     *
     * @throws HilosException When the implementation's periodic work fails
     */
    abstract public function onTick(): void;

    /**
     * Called once the socket is closed, however the connection ended: a clean close
     * by the peer, a reset, or this side dropping it.
     *
     * Can be overridden in child classes to perform cleanup or logging.
     *
     * @throws HilosException When the subclass fails to announce the close
     */
    abstract protected function onClose(): void;

    /**
     * Keep immediate protocol responses inside the accepted-input portion.
     *
     * @throws HilosException When the protocol refuses the buffered input
     * @throws RandomException When a handshake cannot obtain a secret
     */
    private function processReadPortion(): void
    {
        $this->processingReadBuffer = true;
        try {
            $this->processReadBuffer();
        } finally {
            $this->processingReadBuffer = false;
        }
    }

    /**
     * Deliver the terminal outcome once complete runnable input is exhausted.
     *
     * @throws SocketException The original terminal read failure, after its accepted input
     */
    private function finishReadEnd(): void
    {
        if (!$this->readEnded || $this->readBufferReady) {
            return;
        }
        $this->markShouldClose();
        if ($this->pendingReadFailure !== null) {
            $failure = $this->pendingReadFailure;
            $this->pendingReadFailure = null;
            throw $failure;
        }
    }

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
