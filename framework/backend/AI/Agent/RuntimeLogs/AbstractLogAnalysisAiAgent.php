<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\RuntimeLogs;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * AbstractLogAnalysisAiAgent - Project extension point for log analysis checks.
 */
abstract class AbstractLogAnalysisAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::LOG_ANALYSIS;
}
