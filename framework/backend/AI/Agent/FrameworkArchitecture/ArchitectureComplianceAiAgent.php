<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\FrameworkArchitecture;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * ArchitectureComplianceAiAgent - Framework architecture compliance agent stub.
 */
final class ArchitectureComplianceAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::ARCHITECTURE_COMPLIANCE;

    /**
     * Run one lightweight guardian AI agent tick.
     */
    public function onTick(): void
    {
    }
}
