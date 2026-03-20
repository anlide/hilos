<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\Additional;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * AbstractFeatureFlagAiAgent - Project extension point for feature flag checks.
 */
abstract class AbstractFeatureFlagAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::FEATURE_FLAG;
}
