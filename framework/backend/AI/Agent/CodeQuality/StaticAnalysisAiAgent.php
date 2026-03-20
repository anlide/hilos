<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\CodeQuality;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * StaticAnalysisAiAgent - Framework static analysis agent stub.
 */
final class StaticAnalysisAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::STATIC_ANALYSIS;

    /**
     * Run one lightweight guardian AI agent tick.
     */
    public function onTick(): void
    {
    }
}
