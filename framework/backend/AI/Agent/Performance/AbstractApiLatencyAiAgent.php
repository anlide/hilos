<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\Performance;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * AbstractApiLatencyAiAgent - Project extension point for API latency checks.
 */
abstract class AbstractApiLatencyAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::API_LATENCY;
}
