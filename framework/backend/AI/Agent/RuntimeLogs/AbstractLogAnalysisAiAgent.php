<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\RuntimeLogs;

use Hilos\AI\Agent\AbstractSuperfanAiAgent;
use Hilos\AI\Agent\SuperfanAiAgentId;

/**
 * AbstractLogAnalysisAiAgent - Project extension point for log analysis checks.
 */
abstract class AbstractLogAnalysisAiAgent extends AbstractSuperfanAiAgent
{
    public const SuperfanAiAgentId AI_AGENT_ID = SuperfanAiAgentId::LOG_ANALYSIS;
}
