<?php

declare(strict_types=1);

namespace Hilos\Auth\Throttle;

use Hilos\Auth\Throttle\DTO\ThrottleVerdictSignalData;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Exception\LogicException;
use Hilos\Hilos;

/** Resolves each parking agent's answer from the active project topology, without retained state. */
final class ThrottleVerdictRoute
{
    /**
     * @param string $agentType Agent whose dispatcher parked the action
     * @return string Its declared, typed verdict signal
     * @throws LogicException When the parking agent has no valid verdict route
     */
    public static function signalFor(string $agentType): string
    {
        $app = Hilos::appClass();
        $entry = $app::AGENTS[$agentType] ?? null;
        $agentClass = AgentRegistry::workerClass($entry);
        if ($agentClass === null || !is_subclass_of($agentClass, AbstractAgent::class)) {
            throw new LogicException("Throttle verdict parking agent AGENTS[{$agentType}] is not registered");
        }

        $signal = $agentClass::THROTTLE_VERDICT_SIGNAL;
        if ($signal === '') {
            throw new LogicException("AGENTS[{$agentType}] class {$agentClass} has an empty THROTTLE_VERDICT_SIGNAL");
        }

        if (($app::getAgentSignalRoutes()[$signal] ?? null) !== $agentType) {
            throw new LogicException("AGENTS[{$agentType}] class {$agentClass} does not own verdict route {$signal}");
        }

        if (($app::getAgentSignalDtoRoutes()[$signal] ?? null) !== ThrottleVerdictSignalData::class) {
            throw new LogicException("AGENTS[{$agentType}] verdict {$signal} must carry " . ThrottleVerdictSignalData::class);
        }

        if (
            AgentRegistry::requiresIndex($entry)
            && ($app::getAgentSignalIndexFields()[$signal] ?? null) !== ThrottleVerdictSignalData::agentIndex
        ) {
            throw new LogicException("AGENTS[{$agentType}] verdict {$signal} must name index field " . ThrottleVerdictSignalData::agentIndex);
        }

        return $signal;
    }
}
