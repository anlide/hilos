<?php

declare(strict_types=1);

namespace Hilos\API\Router;

use Hilos\Constants\HttpConstants;
use Hilos\Constants\HilosHttpHeaders;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Hilos;

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
        $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
        $queryParams = $this->queryParamsFromRequest($request);
        $request['queryParams'] = $queryParams;
        $sessionToken = $headers[HilosHttpHeaders::HILOS_SESSION_TOKEN]
            ?? $queryParams->getString(HilosHttpHeaders::HILOS_SESSION_TOKEN);
        $userAgent = $headers['User-Agent'] ?? null;
        $acceptLanguage = $headers['Accept-Language'] ?? null;

        // Find matching route
        $route = $this->registry->match($method, $path);
        $apiRequestId = Hilos::$ac?->startApiRequest(
            is_string($sessionToken) ? $sessionToken : null,
            (string)$method,
            (string)$path,
            is_array($route['params'] ?? null) ? $route['params'] : null,
            is_string($userAgent) ? $userAgent : null,
            is_string($acceptLanguage) ? $acceptLanguage : null,
        );

        if ($route === null) {
            $response = [
                HttpConstants::RESPONSE_KEY_STATUS => HttpConstants::HTTP_NOT_FOUND,
                HttpConstants::RESPONSE_KEY_HEADERS => [HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON],
                HttpConstants::RESPONSE_KEY_BODY => json_encode(['error' => 'Not Found']),
            ];
            Hilos::$ac?->finishApiRequest($apiRequestId, HttpConstants::HTTP_NOT_FOUND, 0);
            return $response;
        }

        // Resolve and execute handler
        $startedAt = hrtime(true);
        try {
            $response = $this->resolver->resolve($route, $request);
            $durationMs = (int)round((hrtime(true) - $startedAt) / 1_000_000);
            $statusCode = isset($response[HttpConstants::RESPONSE_KEY_STATUS])
                ? (int)$response[HttpConstants::RESPONSE_KEY_STATUS]
                : HttpConstants::HTTP_OK;
            Hilos::$ac?->finishApiRequest($apiRequestId, $statusCode, $durationMs);
            return $response;
        } catch (\Throwable $e) {
            $durationMs = (int)round((hrtime(true) - $startedAt) / 1_000_000);
            Hilos::$ac?->finishApiRequest($apiRequestId, HttpConstants::HTTP_INTERNAL_ERROR, $durationMs);
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

    /**
     * Returns typed query params from a request payload.
     *
     * @param array<string, mixed> $request Request data
     */
    private function queryParamsFromRequest(array $request): RequestQueryParams
    {
        $queryParams = $request['queryParams'] ?? null;
        if ($queryParams instanceof RequestQueryParams) {
            return $queryParams;
        }

        return is_array($queryParams)
            ? RequestQueryParams::fromStringMap($queryParams)
            : RequestQueryParams::empty();
    }
}
