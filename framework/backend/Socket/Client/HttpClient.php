<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\API\Router\HttpRouter;
use Hilos\Constants\HttpConstants;
use Hilos\Socket\Client\Interface\HttpClientInterface;

/**
 * HttpClient - Represents a single HTTP client connection.
 *
 * Handles reading HTTP requests and writing responses for a single client.
 * Created by HttpServer when accepting new connections.
 */
class HttpClient extends AbstractClient implements HttpClientInterface
{
    /** @var ?HttpRouter Router for handling requests */
    private ?HttpRouter $router = null;

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
     * Process read buffer and check for complete HTTP request.
     *
     * Triggers processRequest() when HTTP delimiter is found.
     */
    protected function processReadBuffer(): void
    {
        // Check if we have complete HTTP request (ends with HTTP delimiter)
        if (strpos($this->readBuffer, HttpConstants::HTTP_DELIMITER) !== false) {
            $this->processRequest();
        }
    }

    /**
     * Process complete HTTP request and send response.
     *
     * Parses request, routes via router, builds and sends HTTP response.
     */
    private function processRequest(): void
    {
        // Parse HTTP request
        $request = $this->parseRequest($this->readBuffer);

        // Route request
        if ($this->router !== null) {
            $response = $this->router->route($request);
        } else {
            // Default response
            $response = [
                HttpConstants::RESPONSE_KEY_STATUS => HttpConstants::HTTP_OK,
                HttpConstants::RESPONSE_KEY_HEADERS => [HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON],
                HttpConstants::RESPONSE_KEY_BODY => json_encode(['status' => 'ok']),
            ];
        }

        // Build and send HTTP response
        $this->writeBuffer = $this->buildResponse($response);
        $this->write();

        // Close connection after response (HTTP/1.1 Connection: close)
        $this->shouldClose = true;
    }

    /**
     * Parse HTTP request.
     *
     * @param string $rawRequest Raw HTTP request
     * @return array{method: string, path: string, version: string, headers: array<string, string>, body: string} Parsed request
     */
    private function parseRequest(string $rawRequest): array
    {
        $lines = explode(HttpConstants::HTTP_LINE_SEPARATOR, $rawRequest);
        $firstLine = $lines[0] ?? '';

        // Parse: GET /path HTTP/1.1
        $parts = explode(' ', $firstLine);

        return [
            'method' => $parts[0] ?? 'GET',
            'path' => $parts[1] ?? '/',
            'version' => $parts[2] ?? 'HTTP/1.1',
            'headers' => $this->parseHeaders($lines),
            'body' => '',
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
            $parts = explode(':', $lines[$i], 2);
            if (count($parts) === 2) {
                $headers[trim($parts[0])] = trim($parts[1]);
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
            HttpConstants::HTTP_NOT_FOUND => 'Not Found',
            HttpConstants::HTTP_INTERNAL_ERROR => 'Internal Server Error',
        ];
        return $statusTexts[$status] ?? 'Unknown';
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
