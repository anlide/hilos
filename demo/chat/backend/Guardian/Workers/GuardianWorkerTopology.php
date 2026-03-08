<?php

declare(strict_types=1);

namespace Demo\Chat\Guardian\Workers;

use Demo\Chat\Constants\AgentType;

final class GuardianWorkerTopology
{
    /**
     * @return list<string>
     */
    public static function monopolisticAgentTypes(): array
    {
        return [
            AgentType::GUARDIAN_OPS,
            AgentType::CHAT_SITUATION_GUARDIAN,
        ];
    }
}
