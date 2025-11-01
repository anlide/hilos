<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\Exception\MissingEnvironmentVariableException;
use Hilos\Exception\SocketException;
use Hilos\Socket\AbstractSocket;
use Hilos\Socket\SocketOperation;
use Hilos\Utils\Constants\EnvConstants;
use Hilos\Utils\Constants\HttpConstants;
use Hilos\Utils\Env;

/**
 * AbstractClient - Abstract base class for client implementations
 *
 * Provides common functionality for all client types.
 */
abstract class AbstractClient extends AbstractSocket implements ClientInterface
{
    /** Read buffer size in bytes - can be overridden in child classes or via config */
    protected int $readBufferSize;

    /** @var string Buffer for incoming data */
    protected string $readBuffer = '';

    /** @var string Buffer for outgoing data */
    protected string $writeBuffer = '';

    /** @var bool Flag indicating if client should be closed */
    protected bool $shouldClose = false;

    /**
     * AbstractClient constructor
     *
     * @param resource $socket Client socket
     */
    public function __construct($socket)
    {
        $this->socket = $socket;
        
        // Read buffer size from config with default fallback
        try {
            $this->readBufferSize = Env::getInt(EnvConstants::SOCKET_READ_BUFFER_SIZE, 8192);
        } catch (MissingEnvironmentVariableException) {
            // Default buffer size if not configured
            $this->readBufferSize = 8192;
        }
    }

    /**
     * Read data from client socket
     * @throws SocketException
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
     * Write buffered data to socket
     * @throws SocketException
     */
    public function write(): void
    {
        if ($this->writeBuffer === '') {
            return;
        }

        if ($this->shouldClose) {
            return;
        }

        $written = socket_write($this->socket, $this->writeBuffer);
        
        if ($written === false) {
            $this->handleSocketError(SocketOperation::WRITE);
            return;
        }

        $this->writeBuffer = substr($this->writeBuffer, $written);
    }

    /**
     * Mark socket for closing (abstract implementation from AbstractSocket)
     */
    protected function markShouldClose(): void
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
     * NOTE: Socket is NOT set to null to allow getSocket() to work after close()
     * for cleanup purposes (e.g., unregistering from event loop before destruction)
     * 
     * @throws SocketException
     */
    public function close(): void
    {
        if (!is_resource($this->socket) && !is_object($this->socket)) {
            return; // Already closed or invalid
        }

        // socket_close returns void, check for errors via socket_last_error
        socket_close($this->socket);
        
        // Check if there was an error during close
        $this->handleSocketError(SocketOperation::CLOSE);

        // Call onClose callback if socket was closed successfully (no exception thrown)
        $this->onClose();

        // Keep $this->socket for event loop cleanup - garbage collector will handle it
    }

    /**
     * Get client IP address from socket
     *
     * Uses socket_getpeername to retrieve client IP address.
     * Returns empty string if unavailable (non-critical operation).
     *
     * @return string Client IP address (IPv4 or IPv6) or empty string if unavailable
     * @throws SocketException
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
     * Parse cookies from Cookie header
     *
     * @param array $headers HTTP headers
     * @return array Cookies as key-value pairs
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
     * Process read buffer - must be implemented by child classes
     */
    abstract protected function processReadBuffer(): void;

    /**
     * Called when socket connection is successfully closed
     *
     * This method is called after socket_close() completes without errors.
     * Can be overridden in child classes to perform cleanup or logging.
     */
    abstract protected function onClose(): void;
}

