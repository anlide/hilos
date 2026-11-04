<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\Performance;

use Hilos\AI\Agent\AbstractSuperfanAiAgent;
use Hilos\AI\Agent\SuperfanAiAgentId;

/**
 * AbstractDbPerformanceAiAgent - Project extension point for database performance checks.
 */
abstract class AbstractDbPerformanceAiAgent extends AbstractSuperfanAiAgent
{
    public const SuperfanAiAgentId AI_AGENT_ID = SuperfanAiAgentId::DB_PERFORMANCE;
}
