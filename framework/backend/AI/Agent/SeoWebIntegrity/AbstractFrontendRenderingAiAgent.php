<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\SeoWebIntegrity;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * AbstractFrontendRenderingAiAgent - Project extension point for frontend rendering checks.
 */
abstract class AbstractFrontendRenderingAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::FRONTEND_RENDERING;
}
