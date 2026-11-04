<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\Additional;

use Hilos\AI\Agent\AbstractSuperfanAiAgent;
use Hilos\AI\Agent\SuperfanAiAgentId;

/**
 * AbstractFeatureFlagAiAgent - Project extension point for feature flag checks.
 */
abstract class AbstractFeatureFlagAiAgent extends AbstractSuperfanAiAgent
{
    public const SuperfanAiAgentId AI_AGENT_ID = SuperfanAiAgentId::FEATURE_FLAG;
}
