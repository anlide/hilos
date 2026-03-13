<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Socket\Client;

use Demo\Chat\Core\Frontend\HtmlCache;
use Demo\Chat\Core\Frontend\HtmlResolver;
use Hilos\API\Router\HttpRouter;
use Hilos\Constants\HttpConstants;
use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\Client\Interface\HttpClientInterface;

/**
 * HTTP client that serves prerendered HTML from HtmlCache based on path and Accept-Language.
 */
class FrontendHtmlClient extends AbstractClient implements HttpClientInterface
{
    /**
     * @param resource $socket Client socket
     * @param HtmlResolver $resolver Path resolver for HTML
     * @param HtmlCache $cache HTML content cache
     */
    public function __construct(
        $socket,
        private HtmlResolver $resolver,
        private HtmlCache $cache
    ) {
        parent::__construct($socket);
    }

    /**
     * Set HTTP router. Not used - this client serves static HTML.
     *
     * @param HttpRouter $router Router instance (ignored)
     */
    public function setRouter(HttpRouter $router): void
    {
        // Not used - this client serves static HTML
    }

    /**
     * Process incoming HTTP request and write prerendered HTML response.
     */
    protected function processReadBuffer(): void
    {
        if (strpos($this->readBuffer, HttpConstants::HTTP_DELIMITER) === false) {
            return;
        }

        $request = $this->parseRequest($this->readBuffer);
        $path = $request['path'] ?? '/';
        $acceptLanguage = $request['headers']['Accept-Language'] ?? '';

        $resolved = $this->resolver->resolve($path, $acceptLanguage);
        $html = $this->cache->get($resolved['path'], $resolved['locale']);

        $status = $html !== null ? $resolved['status'] : HttpConstants::HTTP_NOT_FOUND;
        if ($html === null) {
            $html = $this->cache->get('404', $resolved['locale']) ?? '<!DOCTYPE html><html lang="en"><body><h1>404 Not Found</h1></body></html>';
        }

        $headers = [
            HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_HTML,
            'Vary' => 'Accept-Language',
        ];

        $response = [
            HttpConstants::RESPONSE_KEY_STATUS => $status,
            HttpConstants::RESPONSE_KEY_HEADERS => $headers,
            HttpConstants::RESPONSE_KEY_BODY => $html,
        ];

        $this->writeBuffer = $this->buildResponse($response);
        $this->write();
        $this->shouldClose = true;
    }

    /**
     * Parse raw HTTP request into method, path and headers.
     *
     * @param string $raw Raw HTTP request
     * @return array{method: string, path: string, headers: array<string, string>}
     */
    private function parseRequest(string $raw): array
    {
        $lines = explode(HttpConstants::HTTP_LINE_SEPARATOR, $raw);
        $firstLine = $lines[0] ?? '';
        $parts = explode(' ', $firstLine);

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

        return [
            'method' => $parts[0] ?? 'GET',
            'path' => $parts[1] ?? '/',
            'headers' => $headers,
        ];
    }

    /**
     * Build HTTP response string from status, headers and body.
     *
     * @param array<string, mixed> $response Response data
     * @return string Raw HTTP response
     */
    private function buildResponse(array $response): string
    {
        $status = $response[HttpConstants::RESPONSE_KEY_STATUS] ?? 200;
        $statusTexts = [
            200 => 'OK',
            403 => 'Forbidden',
            404 => 'Not Found',
            500 => 'Internal Server Error',
        ];
        $statusText = $statusTexts[$status] ?? 'Unknown';
        $headers = $response[HttpConstants::RESPONSE_KEY_HEADERS] ?? [];
        $body = $response[HttpConstants::RESPONSE_KEY_BODY] ?? '';

        $headers[HttpConstants::HEADER_CONTENT_LENGTH] = strlen($body);

        $out = HttpConstants::HTTP_VERSION . " {$status} {$statusText}" . HttpConstants::HTTP_LINE_SEPARATOR;
        foreach ($headers as $key => $value) {
            $out .= "{$key}: {$value}" . HttpConstants::HTTP_LINE_SEPARATOR;
        }
        $out .= HttpConstants::HTTP_LINE_SEPARATOR . $body;

        return $out;
    }

    /**
     * Called every tick. No-op for static HTML client.
     */
    public function onTick(): void
    {
    }

    /**
     * Called when connection is closed.
     */
    protected function onClose(): void
    {
    }
}
