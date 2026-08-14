<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\AbstractSocket;
use Hilos\Socket\SocketException;
use Hilos\Socket\SocketOperation;

/**
 * WorkerDaemonClient - Client for worker to connect to daemon.
 *
 * Handles socket connection from worker process to daemon WorkerServer.
 * Manages reading/writing messages using JSON protocol (ready for DTO wrapping).
 *
 * This is framework-level functionality as it's needed by any worker process
 * to establish communication with daemon.
 */
class WorkerDaemonClient extends AbstractSocket
{
    /** Connection lifecycle state; LOST is terminal until close() resets it. */
    protected DaemonConnectionState $state = DaemonConnectionState::IDLE;

    /** @var string Read buffer */
    private string $readBuffer = '';

    /** @var string Write buffer */
    private string $writeBuffer = '';

    /** @var array<WorkerDTO> Message queue for received messages */
    private array $messageQueue = [];

    /**
     * Connect to daemon WorkerServer (non-blocking)
     *
     * Starts connection attempt. Use checkConnection() to verify connection status.
     * Connection is established when isConnected() returns true.
     * Does nothing outside the IDLE state: a connection that was established or
     * lost is never re-dialled, because the daemon supervises workers and starts
     * a replacement instead of expecting one to come back.
     *
     * @throws SocketException When socket operations fail
     * @throws EnvException When WORKER_COMM_HOST or WORKER_COMM_PORT is missing or invalid
     */
    public function connect(): void
    {
        if ($this->state !== DaemonConnectionState::IDLE) {
            return;
        }

        $host = Hilos::$env[EnvConstants::WORKER_COMM_HOST];
        $port = Hilos::$env->int(EnvConstants::WORKER_COMM_PORT);

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
        $result = socket_connect($this->socket, $host, $port);
        if ($result === false) {
            // handleSocketError will handle ERR_INPROGRESS, ERR_WOULDBLOCK/WSA_WOULDBLOCK (returns silently)
            // For other errors, it will throw appropriate exceptions
            $this->handleSocketError(SocketOperation::CONNECT);
            // Connection started, will complete asynchronously (if no exception was thrown)
        }

        // Connection started (non-blocking), will be checked asynchronously
        // Note: Worker ID will be sent after connection is established
        // by the worker manager that has access to worker ID
        $this->state = DaemonConnectionState::CONNECTING;
    }

    /**
     * Check connection status asynchronously
     *
     * For non-blocking sockets, this checks if connection is established
     * using socket_select. Must be called repeatedly until isConnected() returns true.
     * Only the CONNECTING state is polled: a socket the peer has already closed
     * still selects as writable, so any other state would be resurrected here.
     *
     * @throws SocketException When socket select or connection check fails
     */
    public function checkConnection(): void
    {
        if ($this->state !== DaemonConnectionState::CONNECTING || $this->socket === null) {
            return;
        }

        // Check if socket is ready for writing (indicates connection is established)
        $write = [$this->socket];
        $except = [$this->socket];
        $read = [];

        $result = socket_select($read, $write, $except, 0);

        if ($result === false) {
            // Error occurred - handleSocketError will handle ERR_WOULDBLOCK/WSA_WOULDBLOCK
            $this->handleSocketError(SocketOperation::CONNECT);
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
            $this->state = DaemonConnectionState::CONNECTED;
        }
    }

    /**
     * Get next message from queue
     *
     * Returns one message from queue and removes it.
     * Returns null if queue is empty.
     *
     * @return ?WorkerDTO Message DTO or null if queue is empty
     */
    public function getNextMessage(): ?WorkerDTO
    {
        if (empty($this->messageQueue)) {
            return null;
        }

        return array_shift($this->messageQueue);
    }

    /**
     * Read data from socket.
     *
     * An empty read means the daemon closed its end: the connection goes to the
     * terminal LOST state, and already parsed messages stay in the queue.
     *
     * @throws SocketException When socket read fails or read buffer limits are exceeded
     * @throws InvalidArgumentException When a complete message has invalid JSON or type
     * @throws InvalidFormatException When a frame's payload is not the object its DTO needs
     * @throws HilosException When buffered wire input refuses to become a DTO
     */
    public function read(): void
    {
        if (!$this->isConnected()) {
            return;
        }

        $data = socket_read($this->socket, 8192, PHP_BINARY_READ);

        if ($data === '') {
            $this->loseConnection();
            return;
        }

        if ($data === false) {
            // handleSocketError will handle ERR_WOULDBLOCK/WSA_WOULDBLOCK
            $this->handleSocketError(SocketOperation::READ);
            return;
        }

        $this->readBuffer .= $data;
        $this->processReadBuffer();
    }

    /**
     * Write buffered data to socket.
     *
     * Silently does nothing once the connection is lost; undelivered bytes are
     * dropped rather than kept for a reconnect that never happens.
     *
     * @throws SocketException When socket write fails
     */
    public function write(): void
    {
        if (!$this->isConnected() || $this->writeBuffer === '') {
            return;
        }

        $written = socket_write($this->socket, $this->writeBuffer);

        if ($written === false) {
            // handleSocketError will handle ERR_WOULDBLOCK/WSA_WOULDBLOCK
            $this->handleSocketError(SocketOperation::WRITE);
            return;
        }

        $this->writeBuffer = substr($this->writeBuffer, $written);
    }

    /**
     * Send message to daemon (appends to write buffer).
     *
     * @param WorkerDTO|array<string, mixed> $data Message DTO or array to encode as JSON
     */
    public function send(WorkerDTO|array $data): void
    {
        if ($data instanceof WorkerDTO) {
            $json = $data->toJson();
        } else {
            $json = json_encode($data);
        }
        $this->writeBuffer .= $json . "\n";
    }

    /**
     * Extract complete JSON messages from the read buffer and enqueue DTOs.
     *
     * @throws SocketException When read buffer or JSON depth exceeds limits
     * @throws InvalidArgumentException When message JSON or worker message type is invalid
     * @throws InvalidFormatException When a frame's payload is not the object its DTO needs
     */
    private function processReadBuffer(): void
    {
        while ($this->readBuffer !== '') {
            $message = $this->extractCompleteJsonMessage($this->readBuffer);
            if ($message === null) {
                // Incomplete message, wait for more data
                break;
            }

            $this->messageQueue[] = WorkerDTO::factoryWorkerDTO($message);
        }
    }

    /**
     * Check if connected.
     *
     * @return bool True if connected
     */
    public function isConnected(): bool
    {
        return $this->state === DaemonConnectionState::CONNECTED && $this->socket !== null;
    }

    /**
     * Check whether the daemon connection is gone for good.
     *
     * @return bool True once the connection reached its terminal LOST state
     */
    public function isConnectionLost(): bool
    {
        return $this->state === DaemonConnectionState::LOST;
    }

    /**
     * Close connection and return the client to its initial state.
     */
    public function close(): void
    {
        $this->closeSocket();
        $this->state = DaemonConnectionState::IDLE;
        $this->readBuffer = '';
        $this->writeBuffer = '';
        $this->messageQueue = [];
    }

    /**
     * Mark socket for closing (abstract implementation).
     */
    public function markShouldClose(): void
    {
        $this->loseConnection();
    }

    /**
     * Move the connection to its terminal state and release the socket.
     *
     * Buffers are dropped because nothing will be sent or parsed further, while
     * the queue of already parsed messages survives for the caller to drain.
     */
    private function loseConnection(): void
    {
        $this->closeSocket();
        $this->state = DaemonConnectionState::LOST;
        $this->readBuffer = '';
        $this->writeBuffer = '';
    }

    /**
     * Close the underlying socket if it is still open.
     */
    private function closeSocket(): void
    {
        if ($this->socket !== null) {
            socket_close($this->socket);
            $this->socket = null;
        }
    }
}
