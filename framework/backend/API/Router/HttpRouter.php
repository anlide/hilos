<?php

declare(strict_types=1);

namespace Hilos\API\Router;

use Hilos\Constants\HttpConstants;

/**
 * HttpRouter - Routes HTTP requests to handlers.
 *
 * Main router for HTTP requests. Uses RouteRegistry to find matching routes
 * and RouteResolver to execute handlers.
 */
class HttpRouter
{
    /** @var RouteRegistry Route registry */
    private RouteRegistry $registry;

    /** @var RouteResolver Route resolver */
    private RouteResolver $resolver;

    /**
     * Creates HTTP router with default registry and resolver.
     */
    public function __construct()
    {
        $this->registry = new RouteRegistry();
        $this->resolver = new RouteResolver();
    }

    /**
     * Registers HTTP route with method, path and handler.
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $path URL path
     * @param callable $handler Handler function
     */
    public function addRoute(string $method, string $path, callable $handler): void
    {
        $this->registry->register($method, $path, $handler);
    }

    /**
     * Routes HTTP request to matching handler.
     *
     * @param array<string, mixed> $request Request data (method, path, etc.)
     * @return array<string, mixed> Response data (status, headers, body)
     */
    public function route(array $request): array
    {
        $method = $request['method'] ?? 'GET';
        $path = $request['path'] ?? '/';

        // Find matching route
        $route = $this->registry->match($method, $path);

        if ($route === null) {
            return [
                HttpConstants::RESPONSE_KEY_STATUS => HttpConstants::HTTP_NOT_FOUND,
                HttpConstants::RESPONSE_KEY_HEADERS => [HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON],
                HttpConstants::RESPONSE_KEY_BODY => json_encode(['error' => 'Not Found']),
            ];
        }

        // Resolve and execute handler
        try {
            $response = $this->resolver->resolve($route, $request);
            return $response;
        } catch (\Throwable $e) {
            return [
                HttpConstants::RESPONSE_KEY_STATUS => HttpConstants::HTTP_INTERNAL_ERROR,
                HttpConstants::RESPONSE_KEY_HEADERS => [HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON],
                HttpConstants::RESPONSE_KEY_BODY => json_encode(['error' => 'Internal Server Error', 'message' => $e->getMessage()]),
            ];
        }
    }

    /**
     * Returns route registry instance.
     *
     * @return RouteRegistry Registry instance
     */
    public function getRegistry(): RouteRegistry
    {
        return $this->registry;
    }
}
