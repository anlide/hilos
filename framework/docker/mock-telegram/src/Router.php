<?php

declare(strict_types=1);

namespace Hilos\MockTelegram;

/**
 * Router - method + exact path to a handler, and a JSON answer.
 *
 * The mock has a fixed handful of routes and no path parameters, so matching is an
 * exact comparison; anything cleverer would be scaffolding for a server that will
 * never grow one.
 */
final class Router
{
    /** @var list<array{method: string, path: string, handler: callable}> Registered routes */
    private array $routes = [];

    /**
     * Registers one route.
     *
     * @param string $method HTTP method
     * @param string $path Exact request path
     * @param callable $handler Handler receiving the decoded request body
     */
    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[] = ['method' => strtoupper($method), 'path' => $path, 'handler' => $handler];
    }

    /**
     * Dispatches the current request, answering 404 when nothing matches.
     */
    public function dispatch(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        foreach ($this->routes as $route) {
            if ($route['method'] === $method && $route['path'] === $path) {
                self::json(($route['handler'])(self::body()));

                return;
            }
        }

        http_response_code(404);
        self::json(['ok' => false, 'error' => 'ROUTE_NOT_FOUND']);
    }

    /**
     * Writes one JSON answer.
     *
     * @param array<string, mixed> $payload Response payload
     */
    public static function json(array $payload): void
    {
        header('Content-Type: application/json');
        echo json_encode($payload);
    }

    /**
     * Reads the request body as fields, accepting both a form post and JSON.
     *
     * The framework client posts a form; the test routes are called from Playwright,
     * which posts JSON. Accepting both keeps one handler shape for every route.
     *
     * @return array<string, mixed> Request fields, merged with the query string
     */
    private static function body(): array
    {
        $raw = (string)file_get_contents('php://input');
        $fields = [];

        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $fields = $decoded;
            } else {
                parse_str($raw, $fields);
            }
        }

        parse_str((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY), $query);

        return $fields + $query;
    }
}
