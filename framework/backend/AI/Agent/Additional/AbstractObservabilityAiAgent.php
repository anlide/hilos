<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\Additional;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * AbstractObservabilityAiAgent - Project extension point for observability checks.
 */
abstract class AbstractObservabilityAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::OBSERVABILITY;
}
