<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\ApplicationLevel;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * AbstractPromptAiIntegrationAiAgent - Project extension point for prompt integration checks.
 */
abstract class AbstractPromptAiIntegrationAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::PROMPT_AI_INTEGRATION;
}
