<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

/**
 * SignalRouteConfig - Maps non-action signals to page names.
 *
 * Typed counterpart to {@see ActionRouteConfig} for the routes consumed by
 * {@see PageSignalRouter}. Each signal type maps either to a single page name
 * (type-wide route, e.g. all binary frames) or to a `signalName => pageName`
 * map (named routes, e.g. agent/cron signals).
 */
final class SignalRouteConfig
{
    /**
     * Create signal route config with signal-type-to-page mapping.
     *
     * @param array<string, string|array<string, string>> $routes Signal type to page name, or signal type to name-keyed page map
     */
    public function __construct(private readonly array $routes = [])
    {
    }

    /**
     * Resolve page name for a routed signal.
     *
     * @param string $signalType Signal type constant
     * @param string $name Signal name
     * @return ?string Page name, or null when no route exists for the type/name
     */
    public function getPageForSignal(string $signalType, string $name): ?string
    {
        $route = $this->routes[$signalType] ?? null;

        if (is_string($route)) {
            return $route !== '' ? $route : null;
        }

        if (is_array($route)) {
            $page = $route[$name] ?? null;
            return is_string($page) && $page !== '' ? $page : null;
        }

        return null;
    }
}
