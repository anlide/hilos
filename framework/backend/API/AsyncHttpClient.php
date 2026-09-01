<?php

declare(strict_types=1);

namespace Hilos\API;

use Hilos\API\DTO\AsyncHttpResponse;
use Hilos\API\Exception\AsyncHttpBusyException;
use Hilos\API\Exception\AsyncHttpException;
use Hilos\API\Exception\AsyncHttpMalformedResponseException;
use Hilos\API\Exception\AsyncHttpResultUnavailableException;
use Hilos\API\Exception\AsyncHttpStatusException;
use Hilos\API\Exception\AsyncHttpTimeoutException;
use Hilos\API\Exception\AsyncHttpTlsHandshakeException;
use Hilos\Constants\ApiEndpoint;
use Hilos\Constants\HttpConstants;
use Hilos\Socket\Exception\SocketConnectException;
use Hilos\Socket\Exception\SocketReadException;
use Hilos\Socket\Exception\SocketSelectException;
use Hilos\Socket\Exception\SocketWriteException;
use Hilos\Socket\SocketException;

/**
 * Asynchronous non-blocking HTTP client.
 *
 * Provides non-blocking HTTP request capabilities using stream sockets and a
 * small state machine. Request failures are exposed as exceptions from
 * startNewRequest() or tick(); successful responses are consumed explicitly
 * with consumeResult().
 */
class AsyncHttpClient
{
    /** @var string Target host */
    private string $host;

    /** @var int Target port */
    private int $port;

    /** @var string Default request path from constructor */
    private string $defaultPath;

    /** @var bool Use TLS for HTTPS */
    private bool $useTls;

    /** @var string Request method for next/current request */
    private string $requestMethod = HttpConstants::METHOD_GET;

    /** @var string Request path for next/current request */
    private string $requestPath;

    /** @var ?string Request body for next/current request; null when the request carries no body */
    private ?string $requestBody = null;

    /** @var array<string, string> Extra headers for next/current request */
    private array $requestHeaders = [];

    /** @var string Pending data to write */
    private string $pendingWrite = '';

    /** @var AsyncHttpState Current state */
    private AsyncHttpState $state;

    /** @var ?resource Stream socket */
    private $socket = null;

    /** @var int Errno of the last failed socket creation */
    private int $connectErrno = 0;

    /** @var string Reason of the last failed socket creation */
    private string $connectError = '';

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

    /** @var ?AsyncHttpResponse Last completed response */
    private ?AsyncHttpResponse $lastResponse = null;

    /** @var bool Whether a completed response is available */
    private bool $hasNewResult = false;

    /**
     * Creates async HTTP client.
     *
     * @param string $host Target host
     * @param int $port Target port
     * @param ApiEndpoint|string $path Request path or enum
     * @param bool $useTls Use TLS/SSL
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
     * Sets request options for the next startNewRequest() call.
     *
     * Options reset to defaults after request completion or failure.
     *
     * @param string $method HTTP method
     * @param ?string $path Override path, or null to use default path
     * @param ?string $body Request body
     * @param array<string, string> $headers Extra headers
     * @return $this
     */
    public function setRequestOptions(
        string $method = HttpConstants::METHOD_GET,
        ?string $path = null,
        ?string $body = null,
        array $headers = [],
    ): self {
        $this->requestMethod = $method;
        $this->requestPath = $path ?? $this->defaultPath;
        $this->requestBody = $body;
        $this->requestHeaders = $headers;

        return $this;
    }

    /**
     * Advances the async HTTP state machine.
     *
     * @param float $currentTimeMs Current time in milliseconds
     * @throws AsyncHttpException When the request times out, the TLS handshake fails, or parsing fails
     * @throws SocketException When an underlying stream socket operation fails
     */
    public function tick(float $currentTimeMs): void
    {
        if ($this->state !== AsyncHttpState::DONE && $this->startTime > 0) {
            if (($currentTimeMs - $this->startTime) >= $this->timeout) {
                $this->failRequest(new AsyncHttpTimeoutException($this->timeout, $this->state));
            }
        }

        switch ($this->state) {
            case AsyncHttpState::CONNECTING:
                $this->processConnecting();
                break;

            case AsyncHttpState::HANDSHAKING:
                $this->processHandshake();
                break;

            case AsyncHttpState::SENDING:
                $this->processSending();
                break;

            case AsyncHttpState::RECEIVING:
                $this->processReceiving();
                break;

            case AsyncHttpState::DONE:
                break;
        }
    }

    /**
     * Checks whether a successful response is ready to consume.
     *
     * @return bool True when consumeResult() can be called
     */
    public function hasResult(): bool
    {
        return $this->hasNewResult;
    }

    /**
     * Consumes the completed response and clears the result buffer.
     *
     * @return AsyncHttpResponse Completed response
     * @throws AsyncHttpResultUnavailableException When no response is ready
     */
    public function consumeResult(): AsyncHttpResponse
    {
        if (!$this->hasNewResult || $this->lastResponse === null) {
            throw new AsyncHttpResultUnavailableException();
        }

        $response = $this->lastResponse;
        $this->hasNewResult = false;
        $this->lastResponse = null;

        return $response;
    }

    /**
     * Checks whether a request is in progress.
     *
     * @return bool True when the client is busy
     */
    public function isBusy(): bool
    {
        return $this->state !== AsyncHttpState::DONE;
    }

    /**
     * Force closes the current connection and clears response state.
     */
    public function reset(): void
    {
        $this->closeSocket();
        $this->state = AsyncHttpState::DONE;
        $this->responseBuffer = '';
        $this->pendingWrite = '';
        $this->startTime = 0.0;
        $this->lastResponse = null;
        $this->hasNewResult = false;
        $this->resetRequestOptions();
    }

    /**
     * Starts a new HTTP request.
     *
     * @param float $currentTimeMs Current time in milliseconds
     * @throws AsyncHttpBusyException When a previous request is still in progress
     * @throws SocketException When socket creation or configuration fails
     */
    public function startNewRequest(float $currentTimeMs): void
    {
        if ($this->state !== AsyncHttpState::DONE) {
            throw new AsyncHttpBusyException();
        }

        $this->startRequest($currentTimeMs);
    }

    /**
     * Opens the non-blocking stream socket to the target host.
     *
     * A failure records its reason in connectErrno and connectError before returning: the caller
     * only learns that no socket came back, and without the reason every failure reads the same.
     *
     * @return resource|false Connected non-blocking socket, or false on failure
     */
    protected function establishSocket()
    {
        $errno = 0;
        $errstr = '';
        $warning = null;
        $address = "tcp://{$this->host}:{$this->port}";
        $context = $this->createStreamContext();

        $socket = $this->runStreamOperation(
            static function () use ($address, $context, &$errno, &$errstr) {
                return stream_socket_client(
                    $address,
                    $errno,
                    $errstr,
                    0,
                    STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
                    $context,
                );
            },
            $warning,
        );

        if (!is_resource($socket)) {
            $this->connectErrno = $errno;
            $this->connectError = $errstr !== '' ? $errstr : ($warning ?? 'stream_socket_client returned no socket');

            return false;
        }

        $nonBlocking = $this->runStreamOperation(
            static fn(): bool => stream_set_blocking($socket, false),
            $warning,
        );

        if ($nonBlocking !== true) {
            // Without the close the descriptor would leak: the caller only learns that no socket
            // came back, so nobody else is left holding this one.
            fclose($socket);
            $this->connectErrno = 0;
            $this->connectError = $warning ?? 'stream_set_blocking returned false';

            return false;
        }

        return $socket;
    }

    /**
     * Returns the TLS options the request context is built from.
     *
     * The seam is the option set and not the built context: a test overrides one key - its own
     * cafile - and still exercises the production assembly the peer name and verification live in.
     *
     * @return array<string, array<string, mixed>> Stream context options
     */
    protected function streamContextOptions(): array
    {
        return [
            'ssl' => [
                'peer_name' => $this->host,
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ];
    }

    /**
     * Drives the TLS handshake on the open socket one non-blocking step.
     *
     * The crypto seam: tests override it to script the handshake without a real TLS peer.
     *
     * @param ?string $warning First captured warning message, carrying the failure reason
     * @return int|bool True once secured, 0 while the handshake needs more steps, false on failure
     */
    protected function enableCrypto(?string &$warning): int|bool
    {
        return $this->runStreamOperation(
            fn(): int|bool => stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT),
            $warning,
        );
    }

    /**
     * Builds the stream context of the next request.
     *
     * @return ?resource Stream context for a TLS request, or null when the request is plain HTTP
     */
    private function createStreamContext()
    {
        if (!$this->useTls) {
            return null;
        }

        return stream_context_create($this->streamContextOptions());
    }

    /**
     * Starts the HTTP stream socket request.
     *
     * @param float $currentTimeMs Current time in milliseconds
     * @throws SocketException When socket creation or configuration fails
     */
    private function startRequest(float $currentTimeMs): void
    {
        $this->startTime = $currentTimeMs;
        $this->hasNewResult = false;
        $this->lastResponse = null;

        $socket = $this->establishSocket();

        if (!is_resource($socket)) {
            $this->failRequest(new SocketConnectException($this->connectErrno, $this->connectError));
        }

        $this->socket = $socket;
        $this->state = AsyncHttpState::CONNECTING;
        $this->responseBuffer = '';
    }

    /**
     * Processes non-blocking connection progress.
     *
     * @throws SocketException When select fails or socket state is invalid
     */
    private function processConnecting(): void
    {
        if ($this->socket === null || !is_resource($this->socket)) {
            $this->failRequest(new SocketConnectException(0, 'Socket is not available while connecting'));
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
            $this->failRequest(new SocketSelectException(0, $warning ?? 'stream_select returned false while connecting'));
        }

        if ($result > 0 && $write !== []) {
            $this->state = $this->useTls ? AsyncHttpState::HANDSHAKING : AsyncHttpState::SENDING;
        }
    }

    /**
     * Advances the TLS handshake; the first request byte leaves only once crypto is up.
     *
     * @throws AsyncHttpTlsHandshakeException When the TLS handshake fails
     * @throws SocketException When the socket is gone while handshaking
     */
    private function processHandshake(): void
    {
        if ($this->socket === null || !is_resource($this->socket)) {
            $this->failRequest(new SocketConnectException(0, 'Socket is not available while handshaking'));
        }

        $warning = null;
        $enabled = $this->enableCrypto($warning);

        if ($enabled === 0) {
            return;
        }

        if ($enabled !== true) {
            $this->failRequest(new AsyncHttpTlsHandshakeException(
                $warning ?? 'stream_socket_enable_crypto returned false',
            ));
        }

        $this->state = AsyncHttpState::SENDING;
    }

    /**
     * Processes non-blocking request writes.
     *
     * @throws SocketException When socket write fails
     */
    private function processSending(): void
    {
        if ($this->socket === null || !is_resource($this->socket)) {
            $this->failRequest(new SocketWriteException(0, 'Socket is not available while sending'));
        }

        if ($this->pendingWrite === '') {
            $this->pendingWrite = $this->buildRequest();
        }

        $warning = null;
        $written = $this->runStreamOperation(
            fn(): int|false => fwrite($this->socket, $this->pendingWrite),
            $warning,
        );

        if ($written === false) {
            $this->failRequest(new SocketWriteException(0, $warning ?? 'fwrite returned false'));
        }

        $this->pendingWrite = substr($this->pendingWrite, $written);

        if ($this->pendingWrite === '') {
            $this->state = AsyncHttpState::RECEIVING;
        }
    }

    /**
     * Builds HTTP request string from current options.
     *
     * @return string Raw HTTP request
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

        $body = $this->requestBody;
        if ($body !== null && !isset($this->requestHeaders[HttpConstants::HEADER_CONTENT_LENGTH])) {
            $lines[] = HttpConstants::HEADER_CONTENT_LENGTH . ': ' . strlen($body);
        }

        return implode(HttpConstants::HTTP_LINE_SEPARATOR, $lines)
            . HttpConstants::HTTP_DELIMITER
            . ($body === null ? '' : $body);
    }

    /**
     * Processes non-blocking response reads.
     *
     * @throws AsyncHttpException When response parsing fails
     * @throws SocketException When select or read fails
     */
    private function processReceiving(): void
    {
        if ($this->socket === null || !is_resource($this->socket)) {
            $this->failRequest(new SocketReadException(0, 'Socket is not available while receiving'));
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
            $this->failRequest(new SocketSelectException(0, $warning ?? 'stream_select returned false while receiving'));
        }

        if ($result > 0 && $read !== []) {
            $chunk = $this->runStreamOperation(
                fn(): string|false => fread($this->socket, 8192),
                $warning,
            );

            if ($chunk === false) {
                $this->failRequest(new SocketReadException(0, $warning ?? 'fread returned false'));
            }

            // An empty read on a non-blocking socket means "no data yet", never "the peer is
            // done": on TLS the socket is announced readable as soon as protocol bytes arrive,
            // while the decrypted application bytes are not there yet. The end of the response is
            // declared by feof() below, and by nothing else.
            $this->responseBuffer .= $chunk;
        }

        $closed = $this->runStreamOperation(
            fn(): bool => is_resource($this->socket) && feof($this->socket),
            $warning,
        );

        if ($closed) {
            $this->parseResponse();
        }
    }

    /**
     * Parses the completed HTTP response buffer.
     *
     * @throws AsyncHttpException When the response is malformed or has a non-success status
     */
    private function parseResponse(): void
    {
        if ($this->responseBuffer === '') {
            $this->failRequest(new AsyncHttpMalformedResponseException('empty response buffer'));
        }

        $parts = explode(HttpConstants::HTTP_DELIMITER, $this->responseBuffer, 2);
        if (count($parts) < 2) {
            $this->failRequest(new AsyncHttpMalformedResponseException('headers and body delimiter not found'));
        }

        $headersRaw = $parts[0];
        $body = $parts[1];
        try {
            $statusCode = $this->parseStatusCode($headersRaw);
        } catch (AsyncHttpMalformedResponseException $e) {
            $this->failRequest($e);
        }

        if ($this->isChunkedEncoding($headersRaw)) {
            $body = $this->decodeChunkedBody($body);
            if ($body === null) {
                $this->failRequest(new AsyncHttpMalformedResponseException('invalid chunked transfer body'));
            }
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $this->failRequest(new AsyncHttpStatusException($statusCode, $body));
        }

        $this->completeRequest(new AsyncHttpResponse($statusCode, $headersRaw, $body));
    }

    /**
     * Parses HTTP status code from raw headers.
     *
     * @param string $headersRaw Raw HTTP headers
     * @return int HTTP status code
     * @throws AsyncHttpMalformedResponseException When status line is missing or invalid
     */
    private function parseStatusCode(string $headersRaw): int
    {
        $statusLine = strtok($headersRaw, HttpConstants::HTTP_LINE_SEPARATOR);
        if (!is_string($statusLine) || !preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})(?:\s|$)/', $statusLine, $matches)) {
            throw new AsyncHttpMalformedResponseException('invalid HTTP status line');
        }

        return (int)$matches[1];
    }

    /**
     * Checks whether response uses chunked transfer encoding.
     *
     * @param string $headersRaw Raw HTTP headers
     * @return bool True if Transfer-Encoding header is chunked
     */
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
     * Decodes HTTP chunked transfer encoding body.
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
            $chunkHex = trim(explode(';', $sizeLine)[0]);
            if ($chunkHex === '' || !ctype_xdigit($chunkHex)) {
                return null;
            }

            $chunkSize = (int)hexdec($chunkHex);
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
     * Stores a successful response and resets active request state.
     *
     * @param AsyncHttpResponse $response Completed response
     */
    private function completeRequest(AsyncHttpResponse $response): void
    {
        $this->lastResponse = $response;
        $this->hasNewResult = true;
        $this->closeActiveRequest();
    }

    /**
     * Resets active request state and throws the provided failure.
     *
     * @param AsyncHttpException|SocketException $exception Failure to expose to caller
     * @throws AsyncHttpException When an HTTP lifecycle failure is provided
     * @throws SocketException When a socket failure is provided
     */
    private function failRequest(AsyncHttpException|SocketException $exception): never
    {
        $this->lastResponse = null;
        $this->hasNewResult = false;
        $this->closeActiveRequest();

        throw $exception;
    }

    /**
     * Closes active socket state after request completion or failure.
     */
    private function closeActiveRequest(): void
    {
        $this->closeSocket();
        $this->state = AsyncHttpState::DONE;
        $this->responseBuffer = '';
        $this->pendingWrite = '';
        $this->startTime = 0.0;
        $this->resetRequestOptions();
    }

    /**
     * Resets one-shot request options to constructor defaults.
     */
    private function resetRequestOptions(): void
    {
        $this->requestMethod = HttpConstants::METHOD_GET;
        $this->requestPath = $this->defaultPath;
        $this->requestBody = null;
        $this->requestHeaders = [];
    }

    /**
     * Closes HTTP socket.
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
     * @param ?string $warning First captured warning message
     * @return mixed Operation result
     */
    private function runStreamOperation(callable $operation, ?string &$warning): mixed
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            // The first warning holds the reason: PHP reports a refused TLS handshake three times
            // over, and the last of them is the useless "Unable to connect ... (Unknown error)".
            $warning ??= $message;
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
