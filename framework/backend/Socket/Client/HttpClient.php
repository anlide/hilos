<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\API\Router\HttpRouter;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Socket\Client\Interface\HttpClientInterface;
use Hilos\Utils\Env;
use Hilos\Utils\Exception\MissingEnvironmentVariableException;

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
    /** @var ?HttpRouter Router for handling requests */
    private ?HttpRouter $router = null;

    /** @var bool Server policy: allow HTTP keep-alive when the client also allows it */
    private bool $serverAllowsPersistentConnections = true;

    /**
     * @param resource|object $socket Client socket resource or Socket object
     */
    public function __construct($socket)
    {
        parent::__construct($socket);

        try {
            $raw = Env::get(EnvConstants::HTTP_STATUS_KEEP_ALIVE, 'true');
        } catch (MissingEnvironmentVariableException) {
            $raw = 'true';
        }
        $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $this->serverAllowsPersistentConnections = $parsed ?? true;
    }

    /**
     * Set router for handling requests
     *
     * @param HttpRouter $router Router instance
     */
    public function setRouter(HttpRouter $router): void
    {
        $this->router = $router;
    }

    /**
     * Process read buffer: handle complete request(s) when no response is still being transmitted.
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
     * Handle one complete HTTP request message and queue its response.
     */
    private function processSingleHttpRequest(string $rawRequest): void
    {
        $request = $this->parseRequest($rawRequest);
        $persistent = $this->effectivePersistentConnectionForResponse($request['headers'], $request['version']);

        if ($this->router !== null) {
            $response = $this->router->route($request);
        } else {
            $response = [
                HttpConstants::RESPONSE_KEY_STATUS => HttpConstants::HTTP_OK,
                HttpConstants::RESPONSE_KEY_HEADERS => [HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON],
                HttpConstants::RESPONSE_KEY_BODY => json_encode(['status' => 'ok']),
            ];
        }

        $headers = $response[HttpConstants::RESPONSE_KEY_HEADERS] ?? [];
        if (!is_array($headers)) {
            $headers = [];
        }
        $headers[HttpConstants::HEADER_CONNECTION] = $persistent ? 'keep-alive' : 'close';
        $response[HttpConstants::RESPONSE_KEY_HEADERS] = $headers;

        $this->writeBuffer = $this->buildResponse($response);
        $this->closeWhenOutputDrained = !$persistent;
        $this->write();
    }

    /**
     * Whether this response may leave the TCP connection open for another request.
     */
    private function effectivePersistentConnectionForResponse(array $headers, string $version): bool
    {
        if (!$this->serverAllowsPersistentConnections) {
            return false;
        }

        $conn = strtolower($this->getHeaderCaseInsensitive($headers, HttpConstants::HEADER_CONNECTION));
        if ($conn !== '') {
            if (str_contains($conn, 'close')) {
                return false;
            }
            if (str_contains($conn, 'keep-alive')) {
                return true;
            }
        }

        $ver = strtoupper(trim($version));

        return str_contains($ver, '1.1');
    }

    /**
     * @param array<string, string> $headers
     */
    private function getHeaderCaseInsensitive(array $headers, string $name): string
    {
        $want = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower($key) === $want) {
                return is_string($value) ? trim($value) : '';
            }
        }

        return '';
    }

    /**
     * Parse HTTP request.
     *
     * @param string $rawRequest Raw HTTP request
     * @return array{method: string, path: string, version: string, headers: array<string, string>, body: string, query: string, queryParams: RequestQueryParams}
     */
    private function parseRequest(string $rawRequest): array
    {
        $lines = explode(HttpConstants::HTTP_LINE_SEPARATOR, $rawRequest);
        $firstLine = $lines[0] ?? '';

        // Parse: GET /path?a=1 HTTP/1.1
        $parts = explode(' ', $firstLine);
        $rawPath = $parts[1] ?? '/';
        $path = $rawPath;
        $queryString = '';
        $queryPos = strpos($rawPath, '?');
        if ($queryPos !== false) {
            $path = substr($rawPath, 0, $queryPos);
            $queryString = substr($rawPath, $queryPos + 1);
        }

        return [
            'method' => $parts[0] ?? 'GET',
            'path' => $path,
            'version' => $parts[2] ?? 'HTTP/1.1',
            'headers' => $this->parseHeaders($lines),
            'body' => '',
            'query' => $queryString,
            'queryParams' => RequestQueryParams::fromQueryString($queryString),
        ];
    }

    /**
     * Parse HTTP headers.
     *
     * @param list<string> $lines Request lines (from explode of raw request)
     * @return array<string, string> Headers (name => value)
     */
    private function parseHeaders(array $lines): array
    {
        $headers = [];
        for ($i = 1; $i < count($lines); $i++) {
            if ($lines[$i] === '') {
                break;
            }
            $headerParts = explode(':', $lines[$i], 2);
            if (count($headerParts) === 2) {
                $headers[trim($headerParts[0])] = trim($headerParts[1]);
            }
        }

        return $headers;
    }

    /**
     * Build HTTP response.
     *
     * @param array{status?: int, headers?: array<string, string>, body?: string} $response Response data
     * @return string HTTP response string
     */
    private function buildResponse(array $response): string
    {
        $status = $response[HttpConstants::RESPONSE_KEY_STATUS] ?? HttpConstants::HTTP_OK;
        $statusText = $this->getStatusText($status);
        $headers = $response[HttpConstants::RESPONSE_KEY_HEADERS] ?? [];
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
     * Get HTTP status text
     *
     * @param int $status Status code
     * @return string Status text
     */
    private function getStatusText(int $status): string
    {
        $statusTexts = [
            HttpConstants::HTTP_OK => 'OK',
            HttpConstants::HTTP_UNAUTHORIZED => 'Unauthorized',
            HttpConstants::HTTP_NOT_FOUND => 'Not Found',
            HttpConstants::HTTP_INTERNAL_ERROR => 'Internal Server Error',
        ];

        return $statusTexts[$status] ?? 'Unknown';
    }

    /**
     * After a full response is sent on a keep-alive connection, try to parse another request already in the buffer.
     */
    protected function onAfterOutboundDrained(): void
    {
        $this->processReadBuffer();
    }

    /**
     * Tick method - called on each server tick.
     */
    public function onTick(): void
    {
        // No periodic operations needed for HTTP clients
    }

    /**
     * Called when socket connection is successfully closed.
     */
    protected function onClose(): void
    {
        // HTTP client cleanup if needed
    }
}
