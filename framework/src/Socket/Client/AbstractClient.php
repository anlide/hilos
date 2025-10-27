<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

/**
 * AbstractClient - Abstract base class for client implementations
 *
 * Provides common functionality for all client types.
 */
abstract class AbstractClient implements ClientInterface
{
    /** @var resource Client socket resource */
    protected $socket;

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
    }

    /**
     * Get client socket
     *
     * @return resource Socket resource
     */
    public function getSocket()
    {
        return $this->socket;
    }

    /**
     * Read data from client socket
     */
    public function read(): void
    {
        // Skip if already marked for closing to prevent redundant processing
        if ($this->shouldClose) {
            return;
        }

        $data = @socket_read($this->socket, 8192, PHP_BINARY_READ);
        if ($data === false || $data === '') {
            $this->shouldClose = true;
            return;
        }

        $this->readBuffer .= $data;
        $this->processReadBuffer();
    }

    /**
     * Write buffered data to socket
     */
    public function write(): void
    {
        if ($this->writeBuffer === '') {
            return;
        }

        $written = @socket_write($this->socket, $this->writeBuffer);
        if ($written === false) {
            $this->shouldClose = true;
            return;
        }

        $this->writeBuffer = substr($this->writeBuffer, $written);
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
     */
    public function close(): void
    {
        if (is_resource($this->socket) || is_object($this->socket)) {
            socket_close($this->socket);
            // Keep $this->socket for event loop cleanup - garbage collector will handle it
        }
    }

    /**
     * Process read buffer - must be implemented by child classes
     */
    abstract protected function processReadBuffer(): void;
}

