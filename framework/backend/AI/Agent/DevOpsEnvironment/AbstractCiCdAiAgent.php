<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\DevOpsEnvironment;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * AbstractCiCdAiAgent - Project extension point for CI and CD checks.
 */
abstract class AbstractCiCdAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::CI_CD;
}
