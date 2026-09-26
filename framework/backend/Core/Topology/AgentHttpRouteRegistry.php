<?php

declare(strict_types=1);

namespace Hilos\Core\Topology;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentRegistry;

/**
 * Computes the HTTP addresses agents answer from registered agent classes.
 *
 * The HTTP twin of {@see AgentCommandRouteRegistry}: an address routes to an agent type,
 * never to a specific multi-instance agent. Entries of the wrong shape are skipped here and
 * refused by {@see TopologyValidator}, which also refuses one address declared by two agents.
 */
final class AgentHttpRouteRegistry
{
    /**
     * Returns agent-answered HTTP addresses declared by agent classes.
     *
     * @param array $agents Agent registry
     * @return array<string, array<string, string>> Agent type keyed by method, then by path
     */
    public static function routes(array $agents): array
    {
        $httpRoutes = [];
        foreach ($agents as $agentType => $registryEntry) {
            $agentClass = AgentRegistry::workerClass($registryEntry);
            if (!is_string($agentType) || $agentClass === null || !is_subclass_of($agentClass, AbstractAgent::class)) {
                continue;
            }

            foreach ($agentClass::AGENT_HTTP_ROUTES as $method => $paths) {
                if (!is_string($method) || $method === '' || !is_array($paths)) {
                    continue;
                }

                foreach ($paths as $path) {
                    if (is_string($path) && $path !== '') {
                        $httpRoutes[$method][$path] = $agentType;
                    }
                }
            }
        }

        return $httpRoutes;
    }
}
