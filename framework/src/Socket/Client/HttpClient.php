<?php

declare(strict_types=1);

namespace Hilos\Socket\Client;

use Hilos\API\Router\HttpRouter;

/**
 * HttpClient - Represents a single HTTP client connection
 *
 * Handles reading HTTP requests and writing responses for a single client.
 * Created by HttpServer when accepting new connections.
 */
class HttpClient extends AbstractClient
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
     * Process read buffer - check for complete HTTP request
     */
    protected function processReadBuffer(): void
    {
        // Check if we have complete HTTP request (ends with \r\n\r\n)
        if (strpos($this->readBuffer, "\r\n\r\n") !== false) {
            $this->processRequest();
        }
    }

    /**
     * Process complete HTTP request
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
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['status' => 'ok']),
            ];
        }

        // Build and send HTTP response
        $this->writeBuffer = $this->buildResponse($response);
        $this->write();
        
        // Close connection after response (HTTP/1.1 Connection: close)
        $this->shouldClose = true;
    }

    /**
     * Parse HTTP request
     *
     * @param string $rawRequest Raw HTTP request
     * @return array Parsed request
     */
    private function parseRequest(string $rawRequest): array
    {
        $lines = explode("\r\n", $rawRequest);
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
     * Parse HTTP headers
     *
     * @param array $lines Request lines
     * @return array Headers
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
     * Build HTTP response
     *
     * @param array $response Response data
     * @return string HTTP response string
     */
    private function buildResponse(array $response): string
    {
        $status = $response['status'] ?? 200;
        $statusText = $this->getStatusText($status);
        $headers = $response['headers'] ?? [];
        $body = $response['body'] ?? '';

        $http = "HTTP/1.1 {$status} {$statusText}\r\n";
        
        $headers['Content-Length'] = strlen($body);
        foreach ($headers as $key => $value) {
            $http .= "{$key}: {$value}\r\n";
        }
        
        $http .= "\r\n";
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
            200 => 'OK',
            404 => 'Not Found',
            500 => 'Internal Server Error',
        ];
        return $statusTexts[$status] ?? 'Unknown';
    }

}

