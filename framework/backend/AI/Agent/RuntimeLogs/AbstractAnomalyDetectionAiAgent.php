<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\RuntimeLogs;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * AbstractAnomalyDetectionAiAgent - Project extension point for anomaly detection checks.
 */
abstract class AbstractAnomalyDetectionAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::ANOMALY_DETECTION;
}
