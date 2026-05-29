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
 * FrontendHtmlClient - HTTP client that serves prerendered HTML from HtmlCache.
 *
 * Serves prerendered HTML based on path and Accept-Language content negotiation.
 */
final class FrontendHtmlClient extends AbstractClient implements HttpClientInterface
{
    /**
     * Create HTTP client for serving prerendered HTML.
     *
     * @param resource $socket Client socket resource
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
     * Processes incoming HTTP request and writes prerendered HTML response.
     *
     * Parses request, resolves path via HtmlResolver, gets HTML from cache,
     * builds response with status and headers.
     */
    protected function processReadBuffer(): void
    {
        if (!str_contains($this->readBuffer, HttpConstants::HTTP_DELIMITER)) {
            return;
        }

        $request = $this->parseRequest($this->readBuffer);
        $path = $request[HttpConstants::REQUEST_KEY_PATH] ?? HttpConstants::PATH_ROOT;
        $acceptLanguage = $request[HttpConstants::REQUEST_KEY_HEADERS][HttpConstants::HEADER_ACCEPT_LANGUAGE] ?? '';

        $resolved = $this->resolver->resolve($path, $acceptLanguage);
        $html = $this->cache->get($resolved['path'], $resolved['locale']);

        $status = $html !== null ? $resolved['status'] : HttpConstants::HTTP_NOT_FOUND;
        if ($html === null) {
            $html = $this->cache->get('404', $resolved['locale']) ?? '<!DOCTYPE html><html lang="en"><body><h1>404 Not Found</h1></body></html>';
        }

        $headers = [
            HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_HTML,
            HttpConstants::HEADER_VARY => HttpConstants::HEADER_ACCEPT_LANGUAGE,
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
     * @return array<string, mixed> Parsed request (method, path, headers)
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
            HttpConstants::REQUEST_KEY_METHOD => $parts[0] ?? HttpConstants::METHOD_GET,
            HttpConstants::REQUEST_KEY_PATH => $parts[1] ?? HttpConstants::PATH_ROOT,
            HttpConstants::REQUEST_KEY_HEADERS => $headers,
        ];
    }

    /**
     * Build HTTP response string from status, headers and body.
     *
     * @param array{status?: int, headers?: array<string, string>, body?: string} $response Response data
     * @return string Raw HTTP response
     */
    private function buildResponse(array $response): string
    {
        $status = $response[HttpConstants::RESPONSE_KEY_STATUS] ?? HttpConstants::HTTP_OK;
        $statusText = HttpConstants::HTTP_STATUS_TEXTS[$status]
            ?? HttpConstants::HTTP_STATUS_TEXT_UNKNOWN;
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
     * Called every tick.
     *
     * No-op for static HTML client (no periodic work needed).
     */
    public function onTick(): void
    {
    }

    /**
     * Called when connection is closed.
     *
     * No-op for static HTML client (no cleanup needed).
     */
    protected function onClose(): void
    {
    }
}
