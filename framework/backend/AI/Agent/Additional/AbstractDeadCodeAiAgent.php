<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\Additional;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * AbstractDeadCodeAiAgent - Project extension point for dead code checks.
 */
abstract class AbstractDeadCodeAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::DEAD_CODE;
}
