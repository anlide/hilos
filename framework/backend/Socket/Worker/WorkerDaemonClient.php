<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker;

use Event;
use EventBase;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ProcessException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\AbstractSocket;
use Hilos\Socket\SocketException;
use Hilos\Socket\SocketOperation;
use Throwable;

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
    /** @var list<int> Signals received only by this worker's event base */
    private const array PROCESS_SIGNALS = [SIGTERM, SIGINT, SIGHUP];

    /** Bytes requested per socket read while draining the daemon connection. */
    private const int READ_CHUNK_BYTES = 65536;

    /** Connection lifecycle state; LOST is terminal until close() resets it. */
    protected DaemonConnectionState $state = DaemonConnectionState::IDLE;

    /** @var string Read buffer */
    private string $readBuffer = '';

    /** @var string Write buffer */
    private string $writeBuffer = '';

    /** @var array<WorkerDTO> Message queue for received messages */
    private array $messageQueue = [];

    /** One base for socket waits and process signals, available before connect(). */
    private ?EventBase $waitBase = null;

    /** Read watch on the daemon socket for the idle wait; off the watch before the socket closes. */
    private ?Event $waitEvent = null;

    /** Reusable timer armed only for the current idle wait. */
    private ?Event $waitTimer = null;

    /** Whether the persistent read watch fired during the current wait. */
    private bool $waitReadable = false;

    /** @var list<Event> Process signal watches, kept through connection loss and final sending */
    private array $processSignalEvents = [];

    /** @var array<int, true> Process signals awaiting ordinary manager code */
    private array $pendingProcessSignals = [];

    /**
     * Installs the worker's only POSIX receiver before connecting. Callbacks only
     * remember signals; the same base wakes a socket wait without lifecycle work.
     *
     * @throws ProcessException When a signal watch or its installation mask fails
     */
    public function installProcessSignalHandlers(): void
    {
        if ($this->processSignalEvents !== []) {
            return;
        }
        if (!pcntl_sigprocmask(SIG_BLOCK, self::PROCESS_SIGNALS, $originalMask)) {
            throw new ProcessException('Cannot block signals while installing the worker wait.');
        }

        try {
            $this->waitBase ??= new EventBase();
            foreach (self::PROCESS_SIGNALS as $signal) {
                $event = Event::signal($this->waitBase, $signal, function (int $receivedSignal): void {
                    $this->pendingProcessSignals[$receivedSignal] = true;
                });
                if ($event === false) {
                    throw new ProcessException("Cannot create worker signal event for signal $signal.");
                }
                $this->processSignalEvents[] = $event;
                if (!$event->add()) {
                    throw new ProcessException("Cannot install worker signal event for signal $signal.");
                }
            }
        } catch (Throwable $failure) {
            $this->close();
            throw $failure;
        } finally {
            if (!pcntl_sigprocmask(SIG_SETMASK, $originalMask)) {
                $this->close();
                throw new ProcessException('Cannot restore the signal mask after installing the worker wait.');
            }
        }
    }

    /**
     * Dispatches one ready batch without waiting, even before connection or after
     * EOF. LOOP_ONCE prevents an unread persistent socket from spinning here.
     * Retains process signal marks for consumeProcessSignals(); a completed native
     * awaitReadable() has already dispatched callbacks and needs only that drain.
     *
     * @throws ProcessException When the event base cannot dispatch ready signals
     */
    public function pollProcessSignals(): void
    {
        if ($this->processSignalEvents !== [] && !$this->waitBase->loop(EventBase::LOOP_ONCE | EventBase::LOOP_NONBLOCK)) {
            throw new ProcessException('Cannot dispatch worker process signals.');
        }
    }

    /**
     * Takes already dispatched marks without pumping the event base. Call after
     * awaitReadable(), or pollProcessSignals() on paths without a native read wait.
     *
     * @return list<int> Signals to apply outside event callbacks
     */
    public function consumeProcessSignals(): array
    {
        $signals = array_keys($this->pendingProcessSignals);
        $this->pendingProcessSignals = [];
        return $signals;
    }

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

        $host = Hilos::$env[EnvConstants::WORKER_COMM_HOST]->string();
        $port = Hilos::$env[EnvConstants::WORKER_COMM_PORT]->int();

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
     * Drain available socket bytes and parse them once, before reporting EOF or a read error.
     *
     * An empty read means the daemon closed its end: the connection goes to the
     * terminal LOST state, and already parsed messages stay in the queue.
     *
     * @throws SocketException When socket read fails or read buffer limits are exceeded
     * @throws InvalidFormatException When a frame does not decode, names no known type,
     *     or lacks a field its DTO needs
     * @throws HilosException When buffered wire input refuses to become a DTO
     */
    public function read(): void
    {
        if (!$this->isConnected()) {
            return;
        }

        $data = '';
        do {
            // warning-suppressed: after parsing the bytes, would-block is cleared and other errors go to handleSocketError()
            $chunk = @socket_read($this->socket, self::READ_CHUNK_BYTES, PHP_BINARY_READ);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $data .= $chunk;
        } while (true);

        if ($data !== '') {
            $this->readBuffer .= $data;
            $this->processReadBuffer();
        }

        if ($chunk === false) {
            $errorCode = socket_last_error($this->socket);
            if ($errorCode === self::ERR_WOULDBLOCK || $errorCode === self::WSA_WOULDBLOCK) {
                socket_clear_error($this->socket);
                return;
            }
            $this->handleSocketError(SocketOperation::READ);
        } else {
            $this->loseConnection();
        }
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

        // warning-suppressed: a false return goes to handleSocketError(), which reads the error code
        $written = @socket_write($this->socket, $this->writeBuffer);

        if ($written === false) {
            // handleSocketError will handle ERR_WOULDBLOCK/WSA_WOULDBLOCK
            $this->handleSocketError(SocketOperation::WRITE);
            return;
        }

        $this->writeBuffer = substr($this->writeBuffer, $written);
    }

    /**
     * Check whether bytes handed to send() are still waiting for the socket.
     *
     * @return bool True while the write buffer is not empty
     */
    public function hasPendingWrite(): bool
    {
        return $this->writeBuffer !== '';
    }

    /**
     * Wait until the socket takes more bytes, no longer than the given time.
     *
     * Returns either way - ready or out of time - and writes nothing: the caller
     * writes. Does nothing unless connected. An interrupted wait returns like one
     * that ran out, because select() leaves its error on the process and not on
     * the socket.
     *
     * @param float $timeoutSeconds Longest wait, in seconds
     * @throws SocketException When the select fails and the socket carries an error of its own
     */
    public function awaitWritable(float $timeoutSeconds): void
    {
        if (!$this->isConnected()) {
            return;
        }

        $read = [];
        $write = [$this->socket];
        $except = [];
        $seconds = (int)floor($timeoutSeconds);
        $microseconds = (int)(($timeoutSeconds - floor($timeoutSeconds)) * TimeConstants::US_PER_SECOND);

        // warning-suppressed: a false return goes to handleSocketError(), which reads the socket's error code
        if (@socket_select($read, $write, $except, $seconds, $microseconds) === false) {
            $this->handleSocketError(SocketOperation::SELECT);
        }
    }

    /**
     * Wait until the daemon sends something, no longer than the given time.
     *
     * Reads nothing. READABLE includes EOF; the caller reads on its next pass.
     * The persistent read watch shares its base with the process signal receiver.
     * Every early return removes the reusable one-shot timer, leaving signals
     * marked for consumeProcessSignals() and bytes for read(). Drain those marks
     * without pollProcessSignals(): this native loop already ran the callbacks.
     *
     * @param float $timeoutSeconds Longest wait, in seconds
     * @return WorkerReadWake Readiness, elapsed bound, interruption, or no socket
     * @throws SocketException When a watch, timer, or event loop cannot run
     */
    public function awaitReadable(float $timeoutSeconds): WorkerReadWake
    {
        if (!$this->isConnected() || $this->socket === null) {
            return WorkerReadWake::UNAVAILABLE;
        }
        if ($this->pendingProcessSignals !== []) {
            return WorkerReadWake::INTERRUPTED;
        }

        $this->waitBase ??= new EventBase();
        if ($this->waitEvent === null) {
            $this->waitEvent = new Event($this->waitBase, $this->socket, Event::READ | Event::PERSIST, function (): void {
                $this->waitReadable = true;
            });
            if (!$this->waitEvent->add()) {
                $this->waitEvent->free();
                $this->waitEvent = null;
                throw new SocketException('Cannot watch the daemon socket for worker input.');
            }
        }
        $this->waitTimer ??= new Event($this->waitBase, -1, Event::TIMEOUT, static function (): void {
        });
        $this->waitReadable = false;
        try {
            if (!$this->waitTimer->add(max(0.0, $timeoutSeconds))) {
                throw new SocketException('Cannot arm the worker socket wait timer.');
            }
            if (!$this->waitBase->loop(EventBase::LOOP_ONCE)) {
                throw new SocketException('Cannot wait for worker socket input.');
            }
            if ($this->waitReadable) {
                return WorkerReadWake::READABLE;
            }
            return $this->waitTimer->pending(Event::TIMEOUT) ? WorkerReadWake::INTERRUPTED : WorkerReadWake::TIMEOUT;
        } finally {
            $this->waitTimer->del();
        }
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
     * @throws InvalidFormatException When a frame does not decode, names no known type,
     *     or lacks a field its DTO needs
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
     * Release process signal ownership, close the connection and reset its buffers.
     * The empty event base remains reusable when a caller adopts another socket.
     */
    public function close(): void
    {
        foreach ($this->processSignalEvents as $event) {
            $event->del();
            $event->free();
        }
        $this->processSignalEvents = [];
        $this->pendingProcessSignals = [];
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
        if ($this->waitTimer !== null) {
            $this->waitTimer->free();
            $this->waitTimer = null;
        }
        if ($this->waitEvent !== null) {
            $this->waitEvent->free();
            $this->waitEvent = null;
        }
        if ($this->socket !== null) {
            socket_close($this->socket);
            $this->socket = null;
        }
    }
}
