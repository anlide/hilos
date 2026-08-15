<?php

declare(strict_types=1);

namespace Hilos\API\Router;

use Hilos\Auth\Session\SessionToken;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\HilosHttpHeaders;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Utils\Helpers\HttpHeaderHelper;
use Throwable;

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

    /** @var string Name of the cookie the daemon issues the session token in */
    private string $sessionCookieName;

    /**
     * Creates HTTP router with default registry and resolver.
     *
     * The session cookie's name is read once here rather than per request: it is a boot-time
     * setting, and a router that reached for env on every request would owe that failure to
     * everything routing through it, down to the socket read that started the request.
     *
     * @throws EnvException When the session cookie name cannot be read
     */
    public function __construct()
    {
        $this->registry = new RouteRegistry();
        $this->resolver = new RouteResolver();
        $this->sessionCookieName = Hilos::$env->string(EnvConstants::HILOS_SESSION_COOKIE_NAME);
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
     * @return array{status: int, headers: array<string, string>, body: string} HTTP response payload
     * @throws InvalidFormatException When the request carries a query-string map it cannot read
     */
    public function route(array $request): array
    {
        $method = $request[HttpConstants::REQUEST_KEY_METHOD] ?? HttpConstants::METHOD_GET;
        $path = $request[HttpConstants::REQUEST_KEY_PATH] ?? HttpConstants::PATH_ROOT;
        $headers = is_array($request[HttpConstants::REQUEST_KEY_HEADERS] ?? null)
            ? $request[HttpConstants::REQUEST_KEY_HEADERS]
            : [];
        $queryParams = $this->queryParamsFromRequest($request);
        $request[HttpConstants::REQUEST_KEY_QUERY_PARAMS] = $queryParams;
        $sessionToken = $this->sessionTokenFromRequest($headers);
        $userAgent = HttpHeaderHelper::get($headers, HttpConstants::HEADER_USER_AGENT);
        $acceptLanguage = HttpHeaderHelper::get($headers, HttpConstants::HEADER_ACCEPT_LANGUAGE);

        // Find matching route
        $route = $this->registry->match($method, $path);
        $apiRequestId = Hilos::$ac?->startApiRequest(
            $sessionToken,
            (string)$method,
            (string)$path,
            is_array($route['params'] ?? null) ? $route['params'] : null,
            $userAgent,
            $acceptLanguage,
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
            $durationMs = (int)round((hrtime(true) - $startedAt) / TimeConstants::NS_PER_MILLISECOND);
            $statusCode = isset($response[HttpConstants::RESPONSE_KEY_STATUS])
                ? (int)$response[HttpConstants::RESPONSE_KEY_STATUS]
                : HttpConstants::HTTP_OK;
            Hilos::$ac?->finishApiRequest($apiRequestId, $statusCode, $durationMs);
            return $response;
        } catch (Throwable $e) {
            $durationMs = (int)round((hrtime(true) - $startedAt) / TimeConstants::NS_PER_MILLISECOND);
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
     * Reads the session token a request presents, or null when it presents none.
     *
     * There are two legitimate carriers and no third. A non-browser API client sets the
     * header itself; a browser cannot, and presents instead the cookie the daemon set on its
     * WebSocket handshake. The url is not a carrier: a secret written into a query string
     * settles in proxy and server logs, stays in browser history and leaves in the Referer
     * of the next request (docs/agents/antipatterns/secret-in-query.md).
     *
     * A value that could not have been issued here is not treated as a token at all - it
     * would otherwise let any caller name a browser session that never existed, and every
     * distinct string invented would open one.
     *
     * A request carrying neither is simply anonymous. That is the ordinary state of a
     * browser that has not opened a socket yet: this path mints nothing, because it has no
     * Set-Cookie to answer with.
     *
     * @param array<string, mixed> $headers Request headers
     * @return ?string Session token presented by the request, or null when it presents none
     */
    private function sessionTokenFromRequest(array $headers): ?string
    {
        $presented = HttpHeaderHelper::get($headers, HilosHttpHeaders::HILOS_SESSION_TOKEN)
            ?? HttpHeaderHelper::parseCookies($headers)[$this->sessionCookieName]
            ?? null;

        return $presented !== null && SessionToken::isValid($presented) ? $presented : null;
    }

    /**
     * Returns typed query params from a request payload.
     *
     * @param array<string, mixed> $request Request data
     */
    private function queryParamsFromRequest(array $request): RequestQueryParams
    {
        $queryParams = $request[HttpConstants::REQUEST_KEY_QUERY_PARAMS] ?? null;
        if ($queryParams instanceof RequestQueryParams) {
            return $queryParams;
        }

        return is_array($queryParams)
            ? RequestQueryParams::fromStringMap($queryParams)
            : RequestQueryParams::empty();
    }
}
