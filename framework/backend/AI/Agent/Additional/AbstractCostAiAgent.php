<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\Additional;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * AbstractCostAiAgent - Project extension point for execution cost checks.
 */
abstract class AbstractCostAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::COST;
}
