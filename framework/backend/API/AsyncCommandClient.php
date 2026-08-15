<?php

declare(strict_types=1);

namespace Hilos\API;

use Hilos\API\Exception\AsyncCommandResultUnavailableException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Core\Exception\NonArrayPayloadException;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Exception\SocketConnectException;
use Hilos\Socket\Exception\SocketReadException;
use Hilos\Socket\Exception\SocketSelectException;
use Hilos\Socket\Exception\SocketSetNonBlockException;
use Hilos\Socket\Exception\SocketWriteException;
use Hilos\Socket\SocketException;

/**
 * Asynchronous non-blocking client for the daemon command channel.
 *
 * Speaks newline-delimited JSON {@see CommandRequestDTO} / {@see CommandReplyDTO}
 * over a raw TCP socket (no HTTP). A small state machine drives
 * CONNECTING → SENDING → RECEIVING → DONE so the caller can poll
 * tick()/hasResult() and render a spinner while waiting. The caller owns the
 * wall-clock timeout, mirroring the StatusCommand poll loop.
 */
class AsyncCommandClient
{
    /** @var string Target host */
    private string $host;

    /** @var int Target port */
    private int $port;

    /** @var AsyncCommandState Current state */
    private AsyncCommandState $state = AsyncCommandState::DONE;

    /** @var ?resource Stream socket */
    private $socket = null;

    /** @var string Pending request bytes to write */
    private string $pendingWrite = '';

    /** @var string Reply buffer */
    private string $responseBuffer = '';

    /** @var ?CommandReplyDTO Last completed reply */
    private ?CommandReplyDTO $lastReply = null;

    /** @var bool Whether a completed reply is available */
    private bool $hasNewResult = false;

    /**
     * Creates the command client.
     *
     * @param string $host Target host
     * @param int $port Target port
     */
    public function __construct(string $host, int $port)
    {
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * Opens a socket and queues the request for sending.
     *
     * @param CommandRequestDTO $request Request to send
     * @throws SocketException When socket creation or configuration fails
     */
    public function startRequest(CommandRequestDTO $request): void
    {
        $this->reset();

        $errno = 0;
        $errstr = '';
        $warning = null;
        $address = "tcp://{$this->host}:{$this->port}";
        $socket = $this->runStreamOperation(
            static function () use ($address, &$errno, &$errstr) {
                return stream_socket_client(
                    $address,
                    $errno,
                    $errstr,
                    0,
                    STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
                );
            },
            $warning,
        );

        if (!is_resource($socket)) {
            throw new SocketConnectException(
                $errno,
                $errstr !== '' ? $errstr : ($warning ?? 'stream_socket_client returned no socket'),
            );
        }

        $nonBlocking = $this->runStreamOperation(
            static fn(): bool => stream_set_blocking($socket, false),
            $warning,
        );
        if ($nonBlocking !== true) {
            throw new SocketSetNonBlockException(0, $warning ?? 'stream_set_blocking returned false');
        }

        $this->socket = $socket;
        $this->pendingWrite = $request->toJson() . "\n";
        $this->state = AsyncCommandState::CONNECTING;
    }

    /**
     * Advances the state machine one step.
     *
     * @throws SocketException When an underlying socket operation fails
     * @throws InvalidJsonException When the reply line does not decode as JSON
     * @throws NonArrayPayloadException When the reply line decodes into something other than an array
     * @throws InvalidFormatException When the reply is missing a field the DTO cannot be built without
     * @throws HilosException When the reply refuses to be restored into a DTO at all
     */
    public function tick(): void
    {
        match ($this->state) {
            AsyncCommandState::CONNECTING => $this->processConnecting(),
            AsyncCommandState::SENDING => $this->processSending(),
            AsyncCommandState::RECEIVING => $this->processReceiving(),
            AsyncCommandState::DONE => null,
        };
    }

    /**
     * Checks whether a reply is ready to consume.
     *
     * @return bool True when consumeResult() can be called
     */
    public function hasResult(): bool
    {
        return $this->hasNewResult;
    }

    /**
     * Consumes the completed reply and clears the result buffer.
     *
     * @return CommandReplyDTO Completed reply
     * @throws AsyncCommandResultUnavailableException When no reply is ready
     */
    public function consumeResult(): CommandReplyDTO
    {
        if (!$this->hasNewResult || $this->lastReply === null) {
            throw new AsyncCommandResultUnavailableException();
        }

        $reply = $this->lastReply;
        $this->hasNewResult = false;
        $this->lastReply = null;

        return $reply;
    }

    /**
     * Checks whether a request is in progress.
     *
     * @return bool True when the client is busy
     */
    public function isBusy(): bool
    {
        return $this->state !== AsyncCommandState::DONE;
    }

    /**
     * Closes the connection and clears request/reply state.
     */
    public function reset(): void
    {
        $this->closeSocket();
        $this->state = AsyncCommandState::DONE;
        $this->pendingWrite = '';
        $this->responseBuffer = '';
        $this->lastReply = null;
        $this->hasNewResult = false;
    }

    /**
     * Processes non-blocking connection progress.
     *
     * @throws SocketException When select fails or the socket is invalid
     */
    private function processConnecting(): void
    {
        if ($this->socket === null || !is_resource($this->socket)) {
            throw new SocketConnectException(0, 'Socket is not available while connecting');
        }

        $read = [];
        $write = [$this->socket];
        $except = [];
        $warning = null;
        $result = $this->runStreamOperation(
            static function () use (&$read, &$write, &$except): int|false {
                return stream_select($read, $write, $except, 0, 0);
            },
            $warning,
        );

        if ($result === false) {
            throw new SocketSelectException(0, $warning ?? 'stream_select returned false while connecting');
        }

        if ($result > 0 && $write !== []) {
            $this->state = AsyncCommandState::SENDING;
        }
    }

    /**
     * Processes non-blocking request writes.
     *
     * @throws SocketException When the socket write fails
     */
    private function processSending(): void
    {
        if ($this->socket === null || !is_resource($this->socket)) {
            throw new SocketWriteException(0, 'Socket is not available while sending');
        }

        $warning = null;
        $written = $this->runStreamOperation(
            fn(): int|false => fwrite($this->socket, $this->pendingWrite),
            $warning,
        );

        if ($written === false) {
            throw new SocketWriteException(0, $warning ?? 'fwrite returned false');
        }

        $this->pendingWrite = substr($this->pendingWrite, $written);
        if ($this->pendingWrite === '') {
            $this->state = AsyncCommandState::RECEIVING;
        }
    }

    /**
     * Processes non-blocking reply reads, completing on the first full line.
     *
     * @throws SocketException When select or read fails
     */
    private function processReceiving(): void
    {
        if ($this->socket === null || !is_resource($this->socket)) {
            throw new SocketReadException(0, 'Socket is not available while receiving');
        }

        $read = [$this->socket];
        $write = [];
        $except = [];
        $warning = null;
        $result = $this->runStreamOperation(
            static function () use (&$read, &$write, &$except): int|false {
                return stream_select($read, $write, $except, 0, 0);
            },
            $warning,
        );

        if ($result === false) {
            throw new SocketSelectException(0, $warning ?? 'stream_select returned false while receiving');
        }

        if ($result > 0 && $read !== []) {
            $chunk = $this->runStreamOperation(
                fn(): string|false => fread($this->socket, 8192),
                $warning,
            );

            if ($chunk === false) {
                throw new SocketReadException(0, $warning ?? 'fread returned false');
            }

            $this->responseBuffer .= $chunk;
        }

        $newlinePos = strpos($this->responseBuffer, "\n");
        if ($newlinePos !== false) {
            $line = substr($this->responseBuffer, 0, $newlinePos);
            $this->lastReply = CommandReplyDTO::fromJson($line);
            $this->hasNewResult = true;
            $this->closeSocket();
            $this->state = AsyncCommandState::DONE;
        }
    }

    /**
     * Closes the socket if open.
     */
    private function closeSocket(): void
    {
        if ($this->socket !== null && is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;
    }

    /**
     * Executes a stream operation while capturing PHP warnings as text.
     *
     * @param callable $operation Stream operation
     * @param ?string $warning Captured warning message
     * @return mixed Operation result
     */
    private function runStreamOperation(callable $operation, ?string &$warning): mixed
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Destructor - cleanup resources.
     */
    public function __destruct()
    {
        $this->closeSocket();
    }
}
