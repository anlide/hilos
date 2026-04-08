<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Socket\AbstractSocket;
use Hilos\Socket\SocketException;
use Hilos\Socket\SocketOperation;
use Hilos\Utils\Env;
use Hilos\Utils\Exception\MissingEnvironmentVariableException;
use Hilos\Utils\Logger;

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

    /** @var bool Flag indicating if client should be closed */
    protected bool $shouldClose = false;

    /**
     * When true, {@see shouldClose} is set only after {@see writeBuffer} has been fully flushed.
     * Used by HttpClient for Connection: close without truncating large responses on partial writes.
     */
    protected bool $closeWhenOutputDrained = false;

    /**
     * Create client with socket. Reads buffer size from env.
     *
     * @param resource|object $socket Client socket resource or Socket object
     */
    public function __construct($socket)
    {
        $this->socket = $socket;

        try {
            $this->readBufferSize = Env::getInt(EnvConstants::SOCKET_READ_BUFFER_SIZE, 8192);
        } catch (MissingEnvironmentVariableException) {
            $this->readBufferSize = 8192;
        }
    }

    /**
     * Read data from client socket.
     *
     * @throws SocketException If socket read fails
     */
    public function read(): void
    {
        // Skip if already marked for closing to prevent redundant processing
        if ($this->shouldClose) {
            return;
        }

        $data = socket_read($this->socket, $this->readBufferSize, PHP_BINARY_READ);

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
     */
    public function write(): void
    {
        if ($this->writeBuffer === '') {
            return;
        }

        if ($this->shouldClose) {
            return;
        }

        $bufferLength = strlen($this->writeBuffer);
        $written = socket_write($this->socket, $this->writeBuffer);

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
        } catch (\TypeError $e) {
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
     * Parse cookies from Cookie header.
     *
     * @param array<string, string> $headers HTTP headers map
     * @return array<string, string> Cookie name => value pairs
     */
    protected function parseCookies(array $headers): array
    {
        $cookies = [];

        // Cookie header format: "name1=value1; name2=value2; name3=value3"
        $cookieHeader = $headers[HttpConstants::HEADER_COOKIE] ?? '';
        if (empty($cookieHeader)) {
            return $cookies;
        }

        // Split by semicolon and parse each cookie
        $cookiePairs = explode(';', $cookieHeader);
        foreach ($cookiePairs as $pair) {
            $pair = trim($pair);
            if (empty($pair)) {
                continue;
            }

            // Split name=value
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $name = trim($parts[0]);
                $value = trim($parts[1]);
                $cookies[$name] = $value;
            }
        }

        return $cookies;
    }

    /**
     * Process read buffer - must be implemented by child classes.
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
     */
    abstract protected function onClose(): void;
}
