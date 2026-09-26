<?php

declare(strict_types=1);

namespace Hilos\API\Router;

use Hilos\Auth\Session\SessionCookieName;
use Hilos\Auth\Session\SessionToken;
use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\Exception\ClusterDisabledException;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\HilosHttpHeaders;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use Hilos\Utils\Helpers\HttpHeaderHelper;
use Hilos\Utils\Helpers\RandomHelper;
use Throwable;

/**
 * HttpRouter - Routes HTTP requests to handlers.
 *
 * Main router for HTTP requests. Uses RouteRegistry to find matching routes
 * and RouteResolver to execute handlers.
 *
 * An address an agent declares ({@see AbstractAgent::AGENT_HTTP_ROUTES}) has no handler to
 * execute: its answer needs the database or the files, which the master process this router runs
 * in may not touch. The router answers it with a {@see ParkedHttpRequest} instead of a response,
 * and the connection parks until the agent's reply arrives
 * (docs/agents/architecture/agent-http-routes.md).
 */
class HttpRouter
{
    /**
     * @var int Random bytes in the correlation id a parked request is held under. Drawn from the
     *     tolerant axis of RandomHelper: the id never leaves the daemon and only has to not collide
     *     with another parked request (docs/agents/code-style/random-source.md).
     */
    private const int CORRELATION_ID_BYTES = 16;

    /** @var RouteRegistry Route registry */
    private RouteRegistry $registry;

    /** @var array<string, array<string, string>> Agent type answering each agent-declared address, by method and path */
    private array $agentRoutes = [];

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
        $this->sessionCookieName = SessionCookieName::resolve();
    }

    /**
     * Registers HTTP route with method, path and handler.
     *
     * A route on the method and path of an agent-declared address replaces it, the way any later
     * route replaces an earlier one on the same address: the handler answers from now on, not the
     * agent.
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $path URL path
     * @param callable $handler Handler function
     */
    public function addRoute(string $method, string $path, callable $handler): void
    {
        $this->registry->register($method, $path, $handler);
        unset($this->agentRoutes[strtoupper($method)][$path]);
    }

    /**
     * Registers an address the given agent answers instead of a handler.
     *
     * The registry gets a marker in place of a handler, so the address matches like any other
     * route and a later {@see addRoute()} on it still replaces it; {@see route()} never runs the
     * marker, it parks the request for the agent.
     *
     * @param string $method HTTP method
     * @param string $path Exact URL path, without placeholders
     * @param string $agentType Agent type that declares the address
     */
    public function addAgentRoute(string $method, string $path, string $agentType): void
    {
        $this->registry->register($method, $path, self::agentRouteMarker(...));
        $this->agentRoutes[strtoupper($method)][$path] = $agentType;
    }

    /**
     * Routes HTTP request to matching handler, or parks it for the agent that answers its address.
     *
     * A parked request starts its analytics row here, the way a handled one does; the row is
     * finished by whoever writes the agent's reply.
     *
     * @param array<string, mixed> $request Request data (method, path, etc.)
     * @return array{status: int, headers: array<string, string>, body: string}|ParkedHttpRequest HTTP
     *     response payload, or the request to hand to the agent that declares the address
     * @throws InvalidFormatException When the request carries a query-string map it cannot read
     * @throws EnvException When a parked request reads whether the cluster is on and the flag is invalid
     * @throws ClusterConfigurationException When a parked request reads this node's id and its config is invalid
     * @throws ClusterDisabledException When the cluster reports itself on and then refuses its identity
     */
    public function route(array $request): array|ParkedHttpRequest
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

        if (isset($this->agentRoutes[$route['method']][$route['path']])) {
            $cluster = Hilos::$cluster;

            return new ParkedHttpRequest(
                new HttpRequestDTO(
                    correlationId: RandomHelper::hex(self::CORRELATION_ID_BYTES),
                    method: $route['method'],
                    path: $route['path'],
                    query: $queryParams->toArray(),
                    sessionToken: $sessionToken,
                    originNodeId: $cluster !== null && $cluster->isEnabled() ? $cluster->identity()->nodeId : null,
                ),
                $apiRequestId,
                hrtime(true),
            );
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
     * Stands in the registry for the handler of an agent-declared address, and is never meant to run.
     *
     * {@see route()} parks such a request before any handler is resolved, so reaching this means
     * something resolved the registry entry on its own - which is a bug of that caller, not a
     * request to answer.
     *
     * @return never
     * @throws LogicException Always
     */
    private static function agentRouteMarker(): never
    {
        throw new LogicException('An agent-declared HTTP address is answered by its agent, not by a handler');
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
