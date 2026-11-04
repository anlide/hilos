<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\SeoWebIntegrity;

use Hilos\AI\Agent\AbstractSuperfanAiAgent;
use Hilos\AI\Agent\SuperfanAiAgentId;

/**
 * AbstractFrontendRenderingAiAgent - Project extension point for frontend rendering checks.
 */
abstract class AbstractFrontendRenderingAiAgent extends AbstractSuperfanAiAgent
{
    public const SuperfanAiAgentId AI_AGENT_ID = SuperfanAiAgentId::FRONTEND_RENDERING;
}
