<?php

declare(strict_types=1);

namespace Hilos\Core\Topology;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/**
 * Computes agent-owned client-action route maps from registered agent classes.
 *
 * Mirrors {@see AgentSignalRouteRegistry} for the client-action seam: an agent
 * declares its own WebSocket actions in AGENT_ACTIONS (action name => payload
 * DTO class), the page-independent counterpart to Page::ACTIONS.
 */
final class AgentActionRouteRegistry
{
    /**
     * Returns agent-owned client-action owner agent types keyed by action name.
     *
     * Malformed AGENT_ACTIONS entries are skipped here and reported by topology validation.
     *
     * @param array $agents Agent registry
     * @return array<string, string> Agent type keyed by action name
     */
    public static function routes(array $agents): array
    {
        $actionRoutes = [];
        foreach ($agents as $agentType => $registryEntry) {
            $agentClass = AgentRegistry::workerClass($registryEntry);
            if (!is_string($agentType) || $agentClass === null || !is_subclass_of($agentClass, AbstractAgent::class)) {
                continue;
            }

            foreach ($agentClass::AGENT_ACTIONS as $action => $_dtoClass) {
                if (!is_string($action) || $action === '') {
                    continue;
                }

                $actionRoutes[$action] = $agentType;
            }
        }

        return $actionRoutes;
    }

    /**
     * Returns agent-owned client-action payload DTO classes keyed by action name.
     *
     * Malformed AGENT_ACTIONS entries are skipped here and reported by topology validation.
     *
     * @param array $agents Agent registry
     * @return array<string, class-string<ActionPayloadDTO>> DTO class keyed by action name
     */
    public static function dtoRoutes(array $agents): array
    {
        $dtoRoutes = [];
        foreach ($agents as $agentType => $registryEntry) {
            $agentClass = AgentRegistry::workerClass($registryEntry);
            if (!is_string($agentType) || $agentClass === null || !is_subclass_of($agentClass, AbstractAgent::class)) {
                continue;
            }

            foreach ($agentClass::AGENT_ACTIONS as $action => $dtoClass) {
                if (!is_string($action) || $action === '' || !is_string($dtoClass)) {
                    continue;
                }

                $dtoRoutes[$action] = $dtoClass;
            }
        }

        return $dtoRoutes;
    }
}
