<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\CodeQuality;

use Hilos\AI\Agent\AbstractSuperfanAiAgent;
use Hilos\AI\Agent\SuperfanAiAgentId;

/**
 * StaticAnalysisAiAgent - Framework static analysis agent stub.
 */
final class StaticAnalysisAiAgent extends AbstractSuperfanAiAgent
{
    public const SuperfanAiAgentId AI_AGENT_ID = SuperfanAiAgentId::STATIC_ANALYSIS;

    /**
     * Run one lightweight superfan AI agent tick.
     */
    public function onTick(): void
    {
    }
}
