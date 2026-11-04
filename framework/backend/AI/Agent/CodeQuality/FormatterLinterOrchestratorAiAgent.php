<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\CodeQuality;

use Hilos\AI\Agent\AbstractSuperfanAiAgent;
use Hilos\AI\Agent\SuperfanAiAgentId;

/**
 * FormatterLinterOrchestratorAiAgent - Framework formatter and linter orchestrator stub.
 */
final class FormatterLinterOrchestratorAiAgent extends AbstractSuperfanAiAgent
{
    public const SuperfanAiAgentId AI_AGENT_ID = SuperfanAiAgentId::FORMATTER_LINTER_ORCHESTRATOR;

    /**
     * Run one lightweight superfan AI agent tick.
     */
    public function onTick(): void
    {
    }
}
