<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\API\Router\HttpRouter;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Socket\Client\Interface\HttpClientInterface;
use Hilos\Socket\SocketException;
use Hilos\Utils\Helpers\HttpHeaderHelper;

/**
 * HttpClient - Represents a single HTTP client connection.
 *
 * Handles reading HTTP requests and writing responses for a single client.
 * Created by HttpServer when accepting new connections.
 *
 * Persistent connections (keep-alive) are controlled by {@see EnvConstants::HTTP_STATUS_KEEP_ALIVE}
 * and the client's Connection / HTTP version. When the server closes after a response, closing is
 * deferred until the outbound buffer is fully drained (avoids truncated bodies on partial writes).
 */
class HttpClient extends AbstractClient implements HttpClientInterface
{
    /** @var array{status: string} Default JSON body when no router is assigned */
    private const array DEFAULT_RESPONSE_BODY = ['status' => 'ok'];

    /** @var ?HttpRouter Router for handling requests */
    private ?HttpRouter $router = null;

    /** @var bool Server policy: allow HTTP keep-alive when the client also allows it */
    private bool $serverAllowsPersistentConnections = true;

    /**
     * Create HTTP client with socket and load keep-alive policy from env.
     *
     * @param resource|object $socket Client socket resource or Socket object
     * @throws EnvException When socket buffer or keep-alive env values are missing or invalid
     */
    public function __construct($socket)
    {
        parent::__construct($socket);

        $this->serverAllowsPersistentConnections = Hilos::$env->bool(EnvConstants::HTTP_STATUS_KEEP_ALIVE);
    }

    /**
     * Set router for handling HTTP requests.
     *
     * @param HttpRouter $router Router instance
     */
    public function setRouter(HttpRouter $router): void
    {
        $this->router = $router;
    }

    /**
     * Parse complete HTTP request(s) from the read buffer when outbound data is fully sent.
     *
     * @throws SocketException When outbound write fails during request handling
     * @throws InvalidFormatException When the request query string carries a non-string value
     */
    protected function processReadBuffer(): void
    {
        if ($this->writeBuffer !== '') {
            return;
        }

        while (true) {
            $pos = strpos($this->readBuffer, HttpConstants::HTTP_DELIMITER);
            if ($pos === false) {
                break;
            }
            $end = $pos + strlen(HttpConstants::HTTP_DELIMITER);
            $rawRequest = substr($this->readBuffer, 0, $end);
            $this->readBuffer = substr($this->readBuffer, $end);
            $this->processSingleHttpRequest($rawRequest);
            if ($this->writeBuffer !== '') {
                break;
            }
        }
    }

    /**
     * Route one complete HTTP request and queue its response.
     *
     * @param string $rawRequest Raw HTTP request including header/body delimiter
     * @throws SocketException When outbound write fails while sending the response
     */
    private function processSingleHttpRequest(string $rawRequest): void
    {
        $request = $this->parseRequest($rawRequest);
        $persistent = $this->effectivePersistentConnectionForResponse(
            $request[HttpConstants::REQUEST_KEY_HEADERS],
            $request[HttpConstants::REQUEST_KEY_VERSION],
        );

        if ($this->router !== null) {
            $response = $this->router->route($request);
        } else {
            $response = [
                HttpConstants::RESPONSE_KEY_STATUS => HttpConstants::HTTP_OK,
                HttpConstants::RESPONSE_KEY_HEADERS => [HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON],
                HttpConstants::RESPONSE_KEY_BODY => json_encode(self::DEFAULT_RESPONSE_BODY),
            ];
        }

        $headers = $response[HttpConstants::RESPONSE_KEY_HEADERS] ?? [];
        if (!is_array($headers)) {
            $headers = [];
        }
        $headers[HttpConstants::HEADER_CONNECTION] = $persistent
            ? HttpConstants::CONNECTION_VALUE_KEEP_ALIVE
            : HttpConstants::CONNECTION_VALUE_CLOSE;
        $response[HttpConstants::RESPONSE_KEY_HEADERS] = $headers;

        $this->writeBuffer = $this->buildResponse($response);
        $this->closeWhenOutputDrained = !$persistent;
        $this->write();
    }

    /**
     * Resolve whether the response may use a persistent TCP connection.
     *
     * @param array<string, string> $headers Request headers (lowercase header names)
     * @param string $version HTTP version from the request line
     * @return bool True when keep-alive is allowed for this response
     */
    private function effectivePersistentConnectionForResponse(array $headers, string $version): bool
    {
        if (!$this->serverAllowsPersistentConnections) {
            return false;
        }

        $connection = HttpHeaderHelper::get($headers, HttpConstants::HEADER_CONNECTION);
        if ($connection !== null) {
            $conn = strtolower(trim($connection));
            if (str_contains($conn, HttpConstants::CONNECTION_VALUE_CLOSE)) {
                return false;
            }
            if (str_contains($conn, HttpConstants::CONNECTION_VALUE_KEEP_ALIVE)) {
                return true;
            }
        }

        $ver = strtoupper(trim($version));

        return str_contains($ver, HttpConstants::HTTP_VERSION_NUMBER);
    }

    /**
     * Parse raw HTTP request into method, path, headers, and query params.
     *
     * @param string $rawRequest Raw HTTP request
     * @return array{
     *     method: string,
     *     path: string,
     *     version: string,
     *     headers: array<string, string>,
     *     body: string,
     *     query: string,
     *     queryParams: RequestQueryParams
     * } Parsed request keyed by HttpConstants::REQUEST_KEY_* constants
     */
    private function parseRequest(string $rawRequest): array
    {
        $lines = explode(HttpConstants::HTTP_LINE_SEPARATOR, $rawRequest);
        $firstLine = $lines[0];

        // Parse: GET /path?a=1 HTTP/1.1
        $parts = explode(' ', $firstLine);
        $rawPath = $parts[1] ?? HttpConstants::PATH_ROOT;
        $path = $rawPath;
        $queryString = '';
        $queryPos = strpos($rawPath, HttpConstants::QUERY_STRING_SEPARATOR);
        if ($queryPos !== false) {
            $path = substr($rawPath, 0, $queryPos);
            $queryString = substr($rawPath, $queryPos + 1);
        }

        return [
            HttpConstants::REQUEST_KEY_METHOD => $parts[0] ?? HttpConstants::METHOD_GET,
            HttpConstants::REQUEST_KEY_PATH => $path,
            HttpConstants::REQUEST_KEY_VERSION => $parts[2] ?? HttpConstants::HTTP_VERSION,
            HttpConstants::REQUEST_KEY_HEADERS => $this->parseHeaders($lines),
            HttpConstants::REQUEST_KEY_BODY => '',
            HttpConstants::REQUEST_KEY_QUERY => $queryString,
            HttpConstants::REQUEST_KEY_QUERY_PARAMS => RequestQueryParams::fromQueryString($queryString),
        ];
    }

    /**
     * Build raw HTTP response string from status, headers, and body.
     *
     * @param array{status?: int, headers?: array<string, string>, body?: string} $response Response data
     * @return string Serialized HTTP response
     */
    private function buildResponse(array $response): string
    {
        $status = $response[HttpConstants::RESPONSE_KEY_STATUS] ?? HttpConstants::HTTP_OK;
        $statusText = HttpConstants::HTTP_STATUS_TEXTS[$status]
            ?? HttpConstants::HTTP_STATUS_TEXT_UNKNOWN;
        $headers = $response[HttpConstants::RESPONSE_KEY_HEADERS] ?? [];
        // external-boundary: "no body" and "an empty body" are one state — it is counted, then concatenated
        $body = $response[HttpConstants::RESPONSE_KEY_BODY] ?? '';

        $http = HttpConstants::HTTP_VERSION . " {$status} {$statusText}" . HttpConstants::HTTP_LINE_SEPARATOR;

        $headers[HttpConstants::HEADER_CONTENT_LENGTH] = strlen($body);
        foreach ($headers as $key => $value) {
            $http .= "{$key}: {$value}" . HttpConstants::HTTP_LINE_SEPARATOR;
        }

        $http .= HttpConstants::HTTP_LINE_SEPARATOR;
        $http .= $body;

        return $http;
    }

    /**
     * After a full response is sent on a keep-alive connection, parse any pipelined request in the buffer.
     *
     * @throws SocketException When outbound write fails while handling a subsequent request
     * @throws InvalidFormatException When the request query string carries a non-string value
     */
    protected function onAfterOutboundDrained(): void
    {
        $this->processReadBuffer();
    }

    /**
     * Periodic tick hook; HTTP clients have no timeout or heartbeat work.
     */
    public function onTick(): void
    {
        // No periodic operations needed for HTTP clients
    }

    /**
     * Connection close hook; no HTTP-specific cleanup is required.
     */
    protected function onClose(): void
    {
        // HTTP client cleanup if needed
    }
}
