<?php

declare(strict_types=1);

namespace Hilos\StandGateway;

use Closure;
use Hilos\API\Router\HttpRouter;
use Hilos\Constants\HttpConstants;

/**
 * GatewayRoutes - the routes of one gateway connection, and the behavior levers on its provider routes.
 *
 * One per connection, built when the gateway accepts it. A behavior a spec declared is played
 * out on the connection that carries the call - a delay, a cut and a hold are about THAT
 * connection's bytes - and a route learns which connection that is from the routes it was
 * registered through. Neither a static "current client", which would hide the link, nor a hook
 * in the framework's HTTP client, which has no consumer for stretching an answer, is needed.
 *
 * Every route is wrapped in the one decoding the gateway does: the core hands a route the
 * body as a raw string, and a handler here takes fields. A provider route additionally names
 * which value of its call a declaration is keyed by - only the resident knows which value of
 * its call a spec coined. A third kind, {@see page()}, is opened by a browser rather than
 * called by the product or by a spec, and carries neither a key nor a lever.
 */
final class GatewayRoutes
{
    /** Refusal a provider route answers with when a spec dictated its status. */
    private const string ERROR_STATUS_DICTATED = 'STATUS_DICTATED';

    /** @var array<string, true> Paths registered as provider routes */
    private array $providerPaths = [];

    /**
     * Creates the routes of one connection.
     *
     * @param HttpRouter $router Router the connection dispatches through
     * @param StandGatewayHttpClient $client Connection a declared behavior is played out on
     */
    public function __construct(
        private readonly HttpRouter $router,
        private readonly StandGatewayHttpClient $client,
    ) {
    }

    /**
     * Registers a route a spec calls - a resident's test half or the gateway's own.
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param callable(array<string, mixed>, array<string, string>): array<string, mixed> $handler Gateway handler
     */
    public function test(string $method, string $path, callable $handler): void
    {
        $this->router->addRoute($method, $path, self::handler($handler));
    }

    /**
     * Registers a route a BROWSER opens in the course of the product's work.
     *
     * The third kind of half, and it is neither of the other two. Not a provider route: the call
     * carries no value a spec coined, so there is nothing to key a declaration by - which also
     * means no levers, and a declaration naming this path is refused as `PATH_NOT_PROVIDER`. Not a
     * test route either: a spec does not call it, a person's browser does, arriving at the address
     * the product sent it to.
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param callable(array<string, mixed>, array<string, string>): array<string, mixed> $handler Gateway handler
     */
    public function page(string $method, string $path, callable $handler): void
    {
        $this->router->addRoute($method, $path, self::handler($handler));
    }

    /**
     * Registers a route the product calls, with the behavior levers a spec may dictate on it.
     *
     * On every call the behavior declared next for this path and the call's key is taken. None -
     * the resident answers as it always did. A dictated status answers the refusal without asking
     * the resident; otherwise the resident answers, and either answer then leaves the way the
     * connection was told to send it.
     *
     * @param string $method HTTP method
     * @param string $path Route path, which is also the path a declaration names
     * @param callable(array<string, mixed>, array<string, string>): array<string, mixed> $handler Resident handler
     * @param Closure(array<string, mixed>, array<string, string>): string $key Value of the call a declaration is keyed by
     */
    public function provider(string $method, string $path, callable $handler, Closure $key): void
    {
        $this->providerPaths[$path] = true;

        $this->router->addRoute($method, $path, self::handler(function (array $fields, array $headers) use ($path, $handler, $key): array {
            $behavior = Store::takeBehavior($path, $key($fields, $headers));
            if ($behavior === null) {
                return $handler($fields, $headers);
            }

            // Told before the answer exists: the router hands it to the connection right after this returns.
            $this->client->dictate($behavior);

            if ($behavior->status !== null) {
                return StandGatewayTlsServer::json(['ok' => false, 'error' => self::ERROR_STATUS_DICTATED], $behavior->status);
            }

            return $handler($fields, $headers);
        }));
    }

    /**
     * Whether a path is a provider route of a resident, and so a path a behavior can be declared for.
     *
     * @param string $path Route path
     * @return bool True when a resident registered the path as a provider route
     */
    public function isProvider(string $path): bool
    {
        return isset($this->providerPaths[$path]);
    }

    /**
     * Wraps a gateway handler into the shape the router calls.
     *
     * The framework client posts a form; the test routes are called from Playwright,
     * which posts JSON. Accepting both keeps one handler shape for every route, and the
     * query string is merged in beneath the body.
     *
     * The handler gets the request headers as its second argument; one that does not
     * read them may leave the parameter out. What it returns reaches the router as is:
     * a payload is answered as JSON with 200, and a response built by
     * {@see StandGatewayTlsServer::json()} keeps its own status.
     *
     * @param callable(array<string, mixed>, array<string, string>): array<string, mixed> $handler Gateway handler
     * @return Closure(array{request: array<string, mixed>, params: array<string, string>}): array<string, mixed> Route handler
     */
    private static function handler(callable $handler): Closure
    {
        return static function (array $args) use ($handler): array {
            $request = $args['request'];
            $raw = $request[HttpConstants::REQUEST_KEY_BODY];
            $fields = [];

            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $fields = $decoded;
                } else {
                    parse_str($raw, $fields);
                }
            }

            parse_str($request[HttpConstants::REQUEST_KEY_QUERY], $query);

            return $handler($fields + $query, $request[HttpConstants::REQUEST_KEY_HEADERS]);
        };
    }
}
