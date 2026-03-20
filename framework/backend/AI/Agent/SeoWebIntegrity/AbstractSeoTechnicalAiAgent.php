<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\SeoWebIntegrity;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * AbstractSeoTechnicalAiAgent - Project extension point for SEO technical checks.
 */
abstract class AbstractSeoTechnicalAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::SEO_TECHNICAL;
}
