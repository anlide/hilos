<?php

declare(strict_types=1);

namespace Hilos\Core\Topology;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Computes agent-owned signal route maps from registered agent classes.
 */
final class AgentSignalRouteRegistry
{
    /**
     * Returns agent-owned signal routes declared by agent classes.
     *
     * @param array $agents Agent registry
     * @return array<string, string> Agent type keyed by signal name
     */
    public static function routes(array $agents): array
    {
        $signalRoutes = [];
        foreach ($agents as $agentType => $registryEntry) {
            $agentClass = AgentRegistry::workerClass($registryEntry);
            if (!is_string($agentType) || $agentClass === null || !is_subclass_of($agentClass, AbstractAgent::class)) {
                continue;
            }

            foreach ($agentClass::AGENT_SIGNALS as $key => $value) {
                if (is_int($key) && is_string($value) && $value !== '') {
                    $signalRoutes[$value] = $agentType;
                    continue;
                }

                if (is_string($key) && $key !== '' && is_string($value) && $value !== '') {
                    $signalRoutes[$key] = $agentType;
                    continue;
                }

                if (is_string($key) && $key !== '' && is_array($value)) {
                    $signalRoutes[$key] = $agentType;
                }
            }
        }

        return $signalRoutes;
    }

    /**
     * Returns agent-owned signal inner payload DTO classes.
     *
     * @param array $agents Agent registry
     * @return array<string, class-string<SignalDataInterface>> DTO class keyed by signal name
     */
    public static function dtoRoutes(array $agents): array
    {
        $dtoRoutes = [];
        foreach ($agents as $agentType => $registryEntry) {
            $agentClass = AgentRegistry::workerClass($registryEntry);
            if (!is_string($agentType) || $agentClass === null || !is_subclass_of($agentClass, AbstractAgent::class)) {
                continue;
            }

            foreach ($agentClass::AGENT_SIGNALS as $key => $value) {
                if (is_string($key) && $key !== '' && is_string($value) && $value !== '') {
                    $dtoRoutes[$key] = $value;
                    continue;
                }

                if (!is_string($key) || $key === '' || !is_array($value)) {
                    continue;
                }

                $dtoClass = $value[AgentSignalConfigKey::DTO] ?? null;
                if (is_string($dtoClass) && $dtoClass !== '') {
                    $dtoRoutes[$key] = $dtoClass;
                }
            }
        }

        return $dtoRoutes;
    }

    /**
     * Returns index field names for indexed agent signal routes.
     *
     * @param array $agents Agent registry
     * @return array<string, string> Payload field name keyed by signal name
     */
    public static function indexFields(array $agents): array
    {
        $indexFields = [];
        foreach ($agents as $agentType => $registryEntry) {
            $agentClass = AgentRegistry::workerClass($registryEntry);
            if (!is_string($agentType) || $agentClass === null || !is_subclass_of($agentClass, AbstractAgent::class)) {
                continue;
            }

            foreach ($agentClass::AGENT_SIGNALS as $key => $value) {
                if (!is_string($key) || $key === '' || !is_array($value)) {
                    continue;
                }

                $indexField = $value[AgentSignalConfigKey::INDEX_FIELD] ?? null;
                if (is_string($indexField) && $indexField !== '') {
                    $indexFields[$key] = $indexField;
                }
            }
        }

        return $indexFields;
    }

    /**
     * Returns node field names for node-addressed agent signal routes.
     *
     * @param array $agents Agent registry
     * @return array<string, string> Payload field name keyed by signal name
     */
    public static function nodeFields(array $agents): array
    {
        $nodeFields = [];
        foreach ($agents as $agentType => $registryEntry) {
            $agentClass = AgentRegistry::workerClass($registryEntry);
            if (!is_string($agentType) || $agentClass === null || !is_subclass_of($agentClass, AbstractAgent::class)) {
                continue;
            }

            foreach ($agentClass::AGENT_SIGNALS as $key => $value) {
                if (!is_string($key) || $key === '' || !is_array($value)) {
                    continue;
                }

                $nodeField = $value[AgentSignalConfigKey::NODE_FIELD] ?? null;
                if (is_string($nodeField) && $nodeField !== '') {
                    $nodeFields[$key] = $nodeField;
                }
            }
        }

        return $nodeFields;
    }
}
