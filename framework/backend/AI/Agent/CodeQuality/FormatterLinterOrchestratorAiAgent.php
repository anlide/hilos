<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\CodeQuality;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * FormatterLinterOrchestratorAiAgent - Framework formatter and linter orchestrator stub.
 */
final class FormatterLinterOrchestratorAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::FORMATTER_LINTER_ORCHESTRATOR;

    /**
     * Run one lightweight guardian AI agent tick.
     */
    public function onTick(): void
    {
    }
}
