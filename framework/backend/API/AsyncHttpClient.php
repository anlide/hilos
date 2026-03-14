<?php

declare(strict_types=1);

namespace Hilos\API;

use Hilos\Constants\ApiEndpoint;
use Hilos\Constants\HttpConstants;

/**
 * AsyncHttpClient - Asynchronous non-blocking HTTP client.
 *
 * Provides non-blocking HTTP request capabilities using stream sockets and state machine.
 * Designed for use in event loops and real-time monitoring applications.
 *
 * Features:
 * - Non-blocking socket operations
 * - State machine based request handling (CONNECTING → SENDING → RECEIVING → DONE)
 * - Configurable timeout per request
 * - Result polling via hasResult()/getResult()
 * - GET and POST methods with optional JSON body (for LLM APIs, etc.)
 *
 * Note: This class handles only the HTTP request lifecycle. Request scheduling
 * (delays between requests, etc.) should be implemented by the calling code.
 *
 * Usage (GET, backward compatible):
 * ```php
 * $client = new AsyncHttpClient('localhost', 8080, '/api/status');
 * $client->timeout = 2000;
 * if (!$client->isBusy()) {
 *     $client->startNewRequest(microtime(true) * 1000);
 * }
 * $client->tick(microtime(true) * 1000);
 * if ($client->hasResult()) {
 *     $response = $client->getResult();
 * }
 * ```
 *
 * Usage (POST with JSON body):
 * ```php
 * $client = new AsyncHttpClient('127.0.0.1', 11434, '/api/generate');
 * $client->timeout = 60000;  // 60 seconds for LLM
 * $client->setRequestOptions(HttpConstants::METHOD_POST, null, json_encode($payload), [
 *     HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON,
 * ]);
 * if (!$client->isBusy()) {
 *     $client->startNewRequest(microtime(true) * 1000);
 * }
 * ```
 */
class AsyncHttpClient
{
    /** @var string Target host */
    private string $host;

    /** @var int Target port */
    private int $port;

    /** @var string Default request path (from constructor) */
    private string $defaultPath;

    /** @var bool Use TLS (ssl://) for HTTPS */
    private bool $useTls;

    /** @var string Request method for next/current request */
    private string $requestMethod = HttpConstants::METHOD_GET;

    /** @var string Request path for next/current request (when null, use defaultPath) */
    private string $requestPath;

    /** @var string Request body for next/current request (POST/PUT) */
    private string $requestBody = '';

    /** @var array<string, string> Extra headers for next/current request */
    private array $requestHeaders = [];

    /** @var string Pending data to write (for large bodies) */
    private string $pendingWrite = '';

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
     * @param bool $useTls Use TLS/SSL (for HTTPS)
     */
    public function __construct(string $host, int $port, ApiEndpoint|string $path = '/', bool $useTls = false)
    {
        $this->host = $host;
        $this->port = $port;
        $this->useTls = $useTls;
        $pathStr = $path instanceof ApiEndpoint ? $path->value : $path;
        $this->defaultPath = $pathStr;
        $this->requestPath = $pathStr;
        $this->state = AsyncHttpState::DONE;
    }

    /**
     * Set request options for the next startNewRequest() call.
     * Resets to defaults (GET, constructor path, no body) after each request.
     *
     * @param string $method HTTP method (HttpConstants::METHOD_GET or METHOD_POST)
     * @param ?string $path Override path (null = use default from constructor)
     * @param ?string $body Request body (for POST)
     * @param array<string, string> $headers Extra headers (e.g. Content-Type: application/json)
     * @return $this
     */
    public function setRequestOptions(string $method = HttpConstants::METHOD_GET, ?string $path = null, ?string $body = null, array $headers = []): self
    {
        $this->requestMethod = $method;
        $this->requestPath = $path ?? $this->defaultPath;
        $this->requestBody = $body ?? '';
        $this->requestHeaders = $headers;

        return $this;
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
     * Get last request result.
     *
     * Clears the result flag after retrieval.
     *
     * @return array{success: bool, body: ?string} Result with success and body keys
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
     * Force close current connection and reset to done state.
     */
    public function reset(): void
    {
        $this->closeSocket();
        $this->state = AsyncHttpState::DONE;
        $this->responseBuffer = '';
        $this->pendingWrite = '';
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
        $scheme = $this->useTls ? 'ssl' : 'tcp';
        $this->socket = @stream_socket_client(
            "{$scheme}://{$this->host}:{$this->port}",
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
     * Process connecting state.
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
     * Process sending state.
     */
    private function processSending(): void
    {
        if ($this->socket === null || !is_resource($this->socket)) {
            $this->completeRequest(false);
            return;
        }

        // Build full request if not yet done
        if ($this->pendingWrite === '') {
            $this->pendingWrite = $this->buildRequest();
        }

        $written = @fwrite($this->socket, $this->pendingWrite);

        if ($written === false) {
            $this->completeRequest(false);
            return;
        }

        $this->pendingWrite = substr($this->pendingWrite, $written);

        if ($this->pendingWrite === '') {
            $this->state = AsyncHttpState::RECEIVING;
        }
    }

    /**
     * Build HTTP request string from current options.
     *
     * @return string Raw HTTP request (headers + body)
     */
    private function buildRequest(): string
    {
        $path = $this->requestPath;
        if ($path === '' || !str_starts_with($path, '/')) {
            $path = '/' . ltrim($path, '/');
        }

        $lines = [
            $this->requestMethod . ' ' . $path . ' ' . HttpConstants::HTTP_VERSION,
            HttpConstants::HEADER_HOST . ': ' . $this->host,
            HttpConstants::HEADER_CONNECTION . ': close',
        ];

        foreach ($this->requestHeaders as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        if ($this->requestBody !== '' && !isset($this->requestHeaders[HttpConstants::HEADER_CONTENT_LENGTH])) {
            $lines[] = HttpConstants::HEADER_CONTENT_LENGTH . ': ' . strlen($this->requestBody);
        }

        $headers = implode(HttpConstants::HTTP_LINE_SEPARATOR, $lines);
        $request = $headers . HttpConstants::HTTP_DELIMITER . $this->requestBody;

        return $request;
    }

    /**
     * Process receiving state.
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
     * Parse HTTP response.
     */
    private function parseResponse(): void
    {
        if (empty($this->responseBuffer)) {
            $this->completeRequest(false);
            return;
        }

        // Split headers and body
        $parts = explode(HttpConstants::HTTP_DELIMITER, $this->responseBuffer, 2);
        if (count($parts) < 2) {
            $this->completeRequest(false);
            return;
        }

        $headersRaw = $parts[0];
        $body = $parts[1];

        if ($this->isChunkedEncoding($headersRaw)) {
            $body = $this->decodeChunkedBody($body);
            if ($body === null) {
                $this->completeRequest(false);
                return;
            }
        }

        // Success - store response
        $this->lastResponse = $body;
        $this->completeRequest(true);
    }

    private function isChunkedEncoding(string $headersRaw): bool
    {
        foreach (explode(HttpConstants::HTTP_LINE_SEPARATOR, $headersRaw) as $line) {
            if (stripos($line, HttpConstants::HEADER_TRANSFER_ENCODING . ':') === 0) {
                $value = trim(substr($line, strlen(HttpConstants::HEADER_TRANSFER_ENCODING) + 1));
                return strtolower($value) === 'chunked';
            }
        }
        return false;
    }

    /**
     * Decode HTTP chunked transfer encoding body.
     *
     * @param string $chunkedBody Raw chunked body
     * @return ?string Decoded body or null on parse error
     */
    private function decodeChunkedBody(string $chunkedBody): ?string
    {
        $result = '';
        $offset = 0;
        $len = strlen($chunkedBody);

        while ($offset < $len) {
            $lineEnd = strpos($chunkedBody, "\r\n", $offset);
            if ($lineEnd === false) {
                return null;
            }
            $sizeLine = substr($chunkedBody, $offset, $lineEnd - $offset);
            $chunkSize = (int) hexdec(trim(explode(';', $sizeLine)[0]));
            $offset = $lineEnd + 2;

            if ($chunkSize === 0) {
                break;
            }

            if ($offset + $chunkSize > $len) {
                return null;
            }
            $result .= substr($chunkedBody, $offset, $chunkSize);
            $offset += $chunkSize + 2;
        }

        return $result;
    }

    /**
     * Complete HTTP request.
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
        $this->pendingWrite = '';
        $this->startTime = 0.0;

        // Reset request options to defaults for next request (backward compatibility)
        $this->requestMethod = HttpConstants::METHOD_GET;
        $this->requestPath = $this->defaultPath;
        $this->requestBody = '';
        $this->requestHeaders = [];
    }

    /**
     * Close HTTP socket.
     */
    private function closeSocket(): void
    {
        if ($this->socket !== null && is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    /**
     * Destructor - cleanup resources.
     */
    public function __destruct()
    {
        $this->closeSocket();
    }
}
