<?php

declare(strict_types=1);

namespace Hilos\API;

use Hilos\Utils\Constants\ApiEndpoint;
use Hilos\Utils\Constants\HttpConstants;

/**
 * AsyncHttpClient - Asynchronous non-blocking HTTP client
 *
 * Provides non-blocking HTTP request capabilities using stream sockets and state machine.
 * Designed for use in event loops and real-time monitoring applications.
 *
 * Features:
 * - Non-blocking socket operations
 * - State machine based request handling (CONNECTING → SENDING → RECEIVING → DONE)
 * - Configurable timeout per request
 * - Result polling via hasResult()/getResult()
 *
 * Note: This class handles only the HTTP request lifecycle. Request scheduling
 * (delays between requests, etc.) should be implemented by the calling code.
 *
 * Usage:
 * ```php
 * $client = new AsyncHttpClient('localhost', 8080, '/api/status');
 * $client->setTimeout(2000);  // 2 seconds timeout
 *
 * // In event loop:
 * $currentTimeMs = microtime(true) * 1000;
 *
 * // Start new request when ready
 * if (!$client->isBusy()) {
 *     $client->startNewRequest($currentTimeMs);
 * }
 *
 * // Process ongoing request
 * $client->tick($currentTimeMs);
 *
 * // Check for result
 * if ($client->hasResult()) {
 *     $response = $client->getResult();
 *     // Process response
 * }
 * ```
 */
class AsyncHttpClient
{
    /** @var string Target host */
    private string $host;

    /** @var int Target port */
    private int $port;

    /** @var string Request path */
    private string $path;

    /** @var AsyncHttpState Current state */
    private AsyncHttpState $state {
        get {
            return $this->state;
        }
    }

    /** @var ?resource Stream socket */
    private $socket = null;

    /** @var string HTTP response buffer */
    private string $responseBuffer = '';

    /** @var float HTTP request start time for timeout tracking in milliseconds */
    private float $startTime = 0.0;

    /** @var float Maximum HTTP request timeout in milliseconds */
    public float $timeout = 2000.0 {
        set {
            $this->timeout = $value;
        }
    }

    /** @var ?string Last response body */
    private ?string $lastResponse = null;

    /** @var bool Whether last request was successful */
    private bool $lastSuccess = false;

    /** @var bool Whether result is available */
    private bool $hasNewResult = false;

    /**
     * AsyncHttpClient constructor
     *
     * @param string $host Target host
     * @param int $port Target port
     * @param ApiEndpoint|string $path Request path or enum (default: '/')
     */
    public function __construct(string $host, int $port, ApiEndpoint|string $path = '/')
    {
        $this->host = $host;
        $this->port = $port;
        $this->path = $path instanceof ApiEndpoint ? $path->value : $path;
        $this->state = AsyncHttpState::DONE;
    }

    /**
     * Process state machine - call this in event loop
     *
     * @param float $currentTimeMs Current time in milliseconds
     */
    public function tick(float $currentTimeMs): void
    {
        // Check for timeout in any active state (not DONE)
        if ($this->state !== AsyncHttpState::DONE && $this->startTime > 0) {
            if (($currentTimeMs - $this->startTime) >= $this->timeout) {
                // Timeout - force completion
                $this->completeRequest(false);
                return;
            }
        }

        switch ($this->state) {
            case AsyncHttpState::CONNECTING:
                $this->processConnecting();
                break;

            case AsyncHttpState::SENDING:
                $this->processSending();
                break;

            case AsyncHttpState::RECEIVING:
                $this->processReceiving();
                break;

            case AsyncHttpState::DONE:
                // Nothing to do - waiting for external trigger to start new request
                break;
        }
    }

    /**
     * Check if new result is available
     *
     * @return bool True if result is ready
     */
    public function hasResult(): bool
    {
        return $this->hasNewResult;
    }

    /**
     * Get last request result
     *
     * Clears the result flag after retrieval.
     *
     * @return array Result with HttpConstants::RESPONSE_KEY_SUCCESS and HttpConstants::RESPONSE_KEY_BODY keys
     */
    public function getResult(): array
    {
        $this->hasNewResult = false;

        return [
            HttpConstants::RESPONSE_KEY_SUCCESS => $this->lastSuccess,
            HttpConstants::RESPONSE_KEY_BODY => $this->lastResponse,
        ];
    }

    /**
     * Check if client is currently busy
     *
     * @return bool True if request is in progress
     */
    public function isBusy(): bool
    {
        return $this->state !== AsyncHttpState::DONE;
    }

    /**
     * Force close current connection and reset to done state
     */
    public function reset(): void
    {
        $this->closeSocket();
        $this->state = AsyncHttpState::DONE;
        $this->responseBuffer = '';
        $this->startTime = 0.0;
    }

    /**
     * Start new HTTP request
     *
     * Can only be called when state is DONE.
     *
     * @param float $currentTimeMs Current time in milliseconds
     * @return bool True if request started successfully
     */
    public function startNewRequest(float $currentTimeMs): bool
    {
        // Only allow starting new request when in DONE state
        if ($this->state !== AsyncHttpState::DONE) {
            return false;
        }

        $this->startRequest($currentTimeMs);
        return true;
    }

    /**
     * Internal method to start HTTP request
     *
     * @param float $currentTimeMs Current time in milliseconds
     */
    private function startRequest(float $currentTimeMs): void
    {
        $this->startTime = $currentTimeMs;
        $this->hasNewResult = false;

        // Use stream_socket_client with STREAM_CLIENT_ASYNC_CONNECT for non-blocking connect
        $this->socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            0,  // timeout = 0 for non-blocking
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
        );

        if ($this->socket === false) {
            $this->completeRequest(false);
            return;
        }

        // Set non-blocking mode
        stream_set_blocking($this->socket, false);

        $this->state = AsyncHttpState::CONNECTING;
        $this->responseBuffer = '';
    }

    /**
     * Process connecting state
     */
    private function processConnecting(): void
    {
        if ($this->socket === null || !is_resource($this->socket)) {
            $this->completeRequest(false);
            return;
        }

        // Check if socket is writable (connected) using stream_select
        $read = null;
        $write = [$this->socket];
        $except = null;

        $result = @stream_select($read, $write, $except, 0, 0);

        if ($result === false) {
            $this->completeRequest(false);
            return;
        }

        // If socket is in write array, connection is ready
        if ($result > 0 && !empty($write)) {
            // Connected! Switch to sending
            $this->state = AsyncHttpState::SENDING;
        }
    }

    /**
     * Process sending state
     */
    private function processSending(): void
    {
        if ($this->socket === null || !is_resource($this->socket)) {
            $this->completeRequest(false);
            return;
        }

        $request = "GET {$this->path} HTTP/1.1\r\n";
        $request .= "Host: {$this->host}\r\n";
        $request .= "Connection: close\r\n";
        $request .= "\r\n";

        $written = @fwrite($this->socket, $request);

        if ($written === false) {
            $this->completeRequest(false);
            return;
        }

        // Switch to receiving
        $this->state = AsyncHttpState::RECEIVING;
    }

    /**
     * Process receiving state
     */
    private function processReceiving(): void
    {
        if ($this->socket === null || !is_resource($this->socket)) {
            $this->completeRequest(false);
            return;
        }

        // Check if socket is readable using stream_select
        $read = [$this->socket];
        $write = null;
        $except = null;

        $result = @stream_select($read, $write, $except, 0, 0);

        if ($result === false) {
            $this->completeRequest(false);
            return;
        }

        if ($result > 0 && !empty($read)) {
            // Read available data
            $chunk = @fread($this->socket, 8192);

            if ($chunk === false) {
                $this->completeRequest(false);
                return;
            }

            if ($chunk === '') {
                // EOF - parse response
                $this->parseResponse();
                return;
            }

            $this->responseBuffer .= $chunk;
        }

        // Check if connection closed
        if (is_resource($this->socket) && @feof($this->socket)) {
            $this->parseResponse();
        }
    }

    /**
     * Parse HTTP response
     */
    private function parseResponse(): void
    {
        if (empty($this->responseBuffer)) {
            $this->completeRequest(false);
            return;
        }

        // Split headers and body
        $parts = explode("\r\n\r\n", $this->responseBuffer, 2);
        if (count($parts) < 2) {
            $this->completeRequest(false);
            return;
        }

        $body = $parts[1];

        // Success - store response
        $this->lastResponse = $body;
        $this->completeRequest(true);
    }

    /**
     * Complete HTTP request
     *
     * @param bool $success Whether request was successful
     */
    private function completeRequest(bool $success): void
    {
        $this->lastSuccess = $success;
        $this->hasNewResult = true;

        if (!$success) {
            $this->lastResponse = null;
        }

        $this->closeSocket();
        $this->state = AsyncHttpState::DONE;
        $this->responseBuffer = '';
        $this->startTime = 0.0;
    }

    /**
     * Close HTTP socket
     */
    private function closeSocket(): void
    {
        if ($this->socket !== null && is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    /**
     * Destructor - cleanup resources
     */
    public function __destruct()
    {
        $this->closeSocket();
    }
}
