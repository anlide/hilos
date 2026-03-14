<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Workers;

use Demo\Chat\Constants\AgentType;

/**
 * GuardianWorkerTopology - Defines which guardian agent types require monopolistic workers.
 */
final class GuardianWorkerTopology
{
    /**
     * Agent types that must run in monopolistic worker (one process per type).
     *
     * @return list<string> Agent type identifiers
     */
    public static function monopolisticAgentTypes(): array
    {
        return [
            AgentType::GUARDIAN_OPS,
            AgentType::CHAT_SITUATION_GUARDIAN,
        ];
    }
}
