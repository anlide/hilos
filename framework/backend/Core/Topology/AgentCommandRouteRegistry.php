<?php

declare(strict_types=1);

namespace Hilos\Core\Topology;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentCommandConfigKey;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Computes agent-owned CLI command route maps from registered agent classes.
 *
 * Mirror of {@see AgentSignalRouteRegistry} minus index fields: commands route
 * to an agent type, never to a specific multi-instance agent.
 */
final class AgentCommandRouteRegistry
{
    /**
     * Returns agent-owned command routes declared by agent classes.
     *
     * @param array $agents Agent registry
     * @return array<string, string> Agent type keyed by command name
     */
    public static function routes(array $agents): array
    {
        $commandRoutes = [];
        foreach ($agents as $agentType => $registryEntry) {
            $agentClass = AgentRegistry::workerClass($registryEntry);
            if (!is_string($agentType) || $agentClass === null || !is_subclass_of($agentClass, AbstractAgent::class)) {
                continue;
            }

            foreach ($agentClass::AGENT_COMMANDS as $key => $value) {
                if (is_int($key) && is_string($value) && $value !== '') {
                    $commandRoutes[$value] = $agentType;
                    continue;
                }

                if (is_string($key) && $key !== '' && is_string($value) && $value !== '') {
                    $commandRoutes[$key] = $agentType;
                    continue;
                }

                if (is_string($key) && $key !== '' && is_array($value)) {
                    $commandRoutes[$key] = $agentType;
                }
            }
        }

        return $commandRoutes;
    }

    /**
     * Returns agent-owned command inner payload DTO classes.
     *
     * @param array $agents Agent registry
     * @return array<string, class-string<SignalDataInterface>> DTO class keyed by command name
     */
    public static function dtoRoutes(array $agents): array
    {
        $dtoRoutes = [];
        foreach ($agents as $agentType => $registryEntry) {
            $agentClass = AgentRegistry::workerClass($registryEntry);
            if (!is_string($agentType) || $agentClass === null || !is_subclass_of($agentClass, AbstractAgent::class)) {
                continue;
            }

            foreach ($agentClass::AGENT_COMMANDS as $key => $value) {
                if (is_string($key) && $key !== '' && is_string($value) && $value !== '') {
                    $dtoRoutes[$key] = $value;
                    continue;
                }

                if (!is_string($key) || $key === '' || !is_array($value)) {
                    continue;
                }

                $dtoClass = $value[AgentCommandConfigKey::DTO] ?? null;
                if (is_string($dtoClass) && $dtoClass !== '') {
                    $dtoRoutes[$key] = $dtoClass;
                }
            }
        }

        return $dtoRoutes;
    }
}
