<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\API\Router\HttpRouteTally;
use Hilos\Core\Exception\InvalidFormatException;

/** The master's HTTP listener and route counts for one node. */
final readonly class DaemonHttpPicture
{
    /**
     * @param string $host Configured listener host
     * @param int $port Configured listener port
     * @param int $countingSince Unix second when this master began counting
     * @param list<DaemonHttpRoutePicture> $routes Routes sorted by path and then method
     * @param HttpRouteTally $unrouted Requests answered before a route was chosen
     * @throws InvalidFormatException When the port, route list, order, or uniqueness is invalid
     */
    public function __construct(
        public string $host,
        public int $port,
        public int $countingSince,
        public array $routes,
        public HttpRouteTally $unrouted,
    ) {
        if ($port < 0 || $port > 65535 || !array_is_list($routes)) {
            throw new InvalidFormatException('Daemon HTTP picture has an invalid port or route list');
        }
        $previousPath = null;
        $previousMethod = null;
        foreach ($routes as $route) {
            if (!$route instanceof DaemonHttpRoutePicture || ($previousPath !== null && (
                strcmp($previousPath, $route->path) > 0
                || ($previousPath === $route->path && strcmp($previousMethod, $route->method) >= 0)
            ))) {
                throw new InvalidFormatException('Daemon HTTP routes must be unique and sorted by path and method');
            }
            $previousPath = $route->path;
            $previousMethod = $route->method;
        }
    }
}
