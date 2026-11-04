<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\ApplicationLevel;

use Hilos\AI\Agent\AbstractSuperfanAiAgent;
use Hilos\AI\Agent\SuperfanAiAgentId;

/**
 * AbstractPromptAiIntegrationAiAgent - Project extension point for prompt integration checks.
 */
abstract class AbstractPromptAiIntegrationAiAgent extends AbstractSuperfanAiAgent
{
    public const SuperfanAiAgentId AI_AGENT_ID = SuperfanAiAgentId::PROMPT_AI_INTEGRATION;
}
