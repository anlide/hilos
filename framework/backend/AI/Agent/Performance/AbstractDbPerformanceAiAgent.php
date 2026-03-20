<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\Performance;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * AbstractDbPerformanceAiAgent - Project extension point for database performance checks.
 */
abstract class AbstractDbPerformanceAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::DB_PERFORMANCE;
}
