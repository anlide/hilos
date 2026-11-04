<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\SeoWebIntegrity;

use Hilos\AI\Agent\AbstractSuperfanAiAgent;
use Hilos\AI\Agent\SuperfanAiAgentId;

/**
 * AbstractSeoTechnicalAiAgent - Project extension point for SEO technical checks.
 */
abstract class AbstractSeoTechnicalAiAgent extends AbstractSuperfanAiAgent
{
    public const SuperfanAiAgentId AI_AGENT_ID = SuperfanAiAgentId::SEO_TECHNICAL;
}
