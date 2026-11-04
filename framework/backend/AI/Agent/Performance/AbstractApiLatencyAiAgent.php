<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\Performance;

use Hilos\AI\Agent\AbstractSuperfanAiAgent;
use Hilos\AI\Agent\SuperfanAiAgentId;

/**
 * AbstractApiLatencyAiAgent - Project extension point for API latency checks.
 */
abstract class AbstractApiLatencyAiAgent extends AbstractSuperfanAiAgent
{
    public const SuperfanAiAgentId AI_AGENT_ID = SuperfanAiAgentId::API_LATENCY;
}
