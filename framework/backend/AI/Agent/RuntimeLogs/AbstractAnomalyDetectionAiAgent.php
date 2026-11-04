<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\RuntimeLogs;

use Hilos\AI\Agent\AbstractSuperfanAiAgent;
use Hilos\AI\Agent\SuperfanAiAgentId;

/**
 * AbstractAnomalyDetectionAiAgent - Project extension point for anomaly detection checks.
 */
abstract class AbstractAnomalyDetectionAiAgent extends AbstractSuperfanAiAgent
{
    public const SuperfanAiAgentId AI_AGENT_ID = SuperfanAiAgentId::ANOMALY_DETECTION;
}
