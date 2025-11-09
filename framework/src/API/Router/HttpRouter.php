<?php

declare(strict_types=1);

namespace Hilos\API\Router;

use Hilos\Constants\HttpConstants;

/**
 * HttpRouter - Routes HTTP requests to handlers
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
     * HttpRouter constructor
     */
    public function __construct()
    {
        $this->registry = new RouteRegistry();
        $this->resolver = new RouteResolver();
    }

    /**
     * Register a route
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
     * Route an HTTP request
     *
     * @param array $request Request data
     * @return array Response data
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
     * Get route registry
     *
     * @return RouteRegistry Registry instance
     */
    public function getRegistry(): RouteRegistry
    {
        return $this->registry;
    }
}
