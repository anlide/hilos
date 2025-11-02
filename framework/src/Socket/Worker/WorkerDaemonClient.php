<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker;

use Hilos\Exception\MissingEnvironmentVariableException;
use Hilos\Exception\SocketException;
use Hilos\Socket\AbstractSocket;
use Hilos\Socket\SocketOperation;
use Hilos\Utils\Constants\EnvConstants;
use Hilos\Utils\Env;

/**
 * WorkerDaemonClient - Client for worker to connect to daemon
 *
 * Handles socket connection from worker process to daemon WorkerServer.
 * Manages reading/writing messages using JSON protocol (ready for DTO wrapping).
 * 
 * This is framework-level functionality as it's needed by any worker process
 * to establish communication with daemon.
 */
class WorkerDaemonClient extends AbstractSocket
{
    /** @var string Read buffer */
    private string $readBuffer = '';

    /** @var string Write buffer */
    private string $writeBuffer = '';

    /** @var bool Connection status */
    private bool $connected = false;

    /** @var bool Whether connection attempt has been made */
    private bool $connectionAttempted = false;

    /** @var callable|null Message handler callback */
    private $messageHandler = null;

    /**
     * Connect to daemon WorkerServer (non-blocking)
     *
     * Starts connection attempt. Use checkConnection() to verify connection status.
     * Connection is established when isConnected() returns true.
     *
     * @throws SocketException
     * @throws MissingEnvironmentVariableException
     */
    public function connect(): void
    {
        if ($this->connectionAttempted && $this->socket !== null) {
            return;
        }

        $host = Env::get(EnvConstants::WORKER_COMM_HOST);
        $port = Env::getInt(EnvConstants::WORKER_COMM_PORT);

        // Create socket
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            $this->handleSocketError(SocketOperation::CREATE);
            return;
        }
        $this->socket = $socket;

        // Set non-blocking mode
        if (!socket_set_nonblock($this->socket)) {
            $this->handleSocketError(SocketOperation::SET_NONBLOCK);
            return;
        }

        // Connect (non-blocking)
        $result = @socket_connect($this->socket, $host, $port);
        if ($result === false) {
            $errorCode = socket_last_error($this->socket);
            // EWOULDBLOCK, WSA_WOULDBLOCK, and EINPROGRESS are expected for non-blocking connect
            if ($errorCode !== self::ERR_WOULDBLOCK 
                && $errorCode !== self::WSA_WOULDBLOCK 
                && $errorCode !== self::ERR_INPROGRESS) {
                $this->handleSocketError(SocketOperation::CONNECT);
                return;
            }
            // Connection started, will complete asynchronously
        }

        // Connection started (non-blocking), will be checked asynchronously
        // Note: Worker ID will be sent after connection is established
        // by the worker manager that has access to worker ID
        $this->connectionAttempted = true;
        $this->connected = false; // Will be set to true when connection is verified
    }

    /**
     * Check connection status asynchronously
     *
     * For non-blocking sockets, this checks if connection is established
     * using socket_select. Must be called repeatedly until isConnected() returns true.
     *
     * @throws SocketException
     */
    public function checkConnection(): void
    {
        if ($this->connected || $this->socket === null || !$this->connectionAttempted) {
            return;
        }

        // Check if socket is ready for writing (indicates connection is established)
        $write = [$this->socket];
        $except = [$this->socket];
        $read = [];

        $result = socket_select($read, $write, $except, 0);

        if ($result === false) {
            // Error occurred
            $errorCode = socket_last_error($this->socket);
            if ($errorCode !== self::ERR_WOULDBLOCK && $errorCode !== self::WSA_WOULDBLOCK) {
                socket_clear_error($this->socket);
                $this->handleSocketError(SocketOperation::CONNECT);
            }
            return;
        }

        if ($result > 0) {
            // Check if there's an error in the exception set
            if (!empty($except)) {
                // Connection failed
                $this->handleSocketError(SocketOperation::CONNECT);
                return;
            }

            // Socket is ready for writing - connection is established
            // Check for connection errors using SO_ERROR
            $errorCode = socket_get_option($this->socket, SOL_SOCKET, SO_ERROR);
            if ($errorCode !== 0 && $errorCode !== false) {
                // Connection error occurred
                socket_clear_error($this->socket);
                $this->handleSocketError(SocketOperation::CONNECT);
                return;
            }

            // Connection is established
            $this->connected = true;
        }
    }

    /**
     * Set message handler callback
     *
     * @param callable $handler Callback function(array $data): void
     */
    public function setMessageHandler(callable $handler): void
    {
        $this->messageHandler = $handler;
    }

    /**
     * Read data from socket
     *
     * @throws SocketException
     */
    public function read(): void
    {
        if (!$this->connected || $this->socket === null) {
            return;
        }

        $data = socket_read($this->socket, 8192, PHP_BINARY_READ);
        
        if ($data === '') {
            // Connection closed
            $this->connected = false;
            return;
        }

        if ($data === false) {
            $errorCode = socket_last_error($this->socket);
            if ($errorCode === self::ERR_WOULDBLOCK || $errorCode === self::WSA_WOULDBLOCK) {
                socket_clear_error($this->socket);
                return;
            }
            $this->handleSocketError(SocketOperation::READ);
            return;
        }

        $this->readBuffer .= $data;
        $this->processReadBuffer();
    }

    /**
     * Write buffered data to socket
     *
     * @throws SocketException
     */
    public function write(): void
    {
        if (!$this->connected || $this->socket === null || $this->writeBuffer === '') {
            return;
        }

        $written = socket_write($this->socket, $this->writeBuffer);
        
        if ($written === false) {
            $errorCode = socket_last_error($this->socket);
            if ($errorCode === self::ERR_WOULDBLOCK || $errorCode === self::WSA_WOULDBLOCK) {
                socket_clear_error($this->socket);
                return;
            }
            $this->handleSocketError(SocketOperation::WRITE);
            return;
        }

        $this->writeBuffer = substr($this->writeBuffer, $written);
    }

    /**
     * Send message to daemon
     *
     * @param array $data Message data
     */
    public function send(array $data): void
    {
        $json = json_encode($data);
        $this->writeBuffer .= $json . "\n";
    }

    /**
     * Process read buffer - extract complete messages
     */
    private function processReadBuffer(): void
    {
        while (($pos = strpos($this->readBuffer, "\n")) !== false) {
            $message = substr($this->readBuffer, 0, $pos);
            $this->readBuffer = substr($this->readBuffer, $pos + 1);
            
            $data = json_decode($message, true);
            if ($data !== null && $this->messageHandler !== null) {
                ($this->messageHandler)($data);
            }
        }
    }

    /**
     * Check if connected
     *
     * @return bool True if connected
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->socket !== null;
    }

    /**
     * Close connection
     *
     * @throws SocketException
     */
    public function close(): void
    {
        if ($this->socket !== null) {
            socket_close($this->socket);
            $this->socket = null;
        }
        $this->connected = false;
        $this->connectionAttempted = false;
        $this->readBuffer = '';
        $this->writeBuffer = '';
    }

    /**
     * Mark socket for closing (abstract implementation)
     */
    protected function markShouldClose(): void
    {
        $this->connected = false;
        // Keep connectionAttempted = true to prevent reconnection attempts
    }
}

