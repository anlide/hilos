<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\FrameworkArchitecture;

use Hilos\AI\Agent\AbstractSuperfanAiAgent;
use Hilos\AI\Agent\SuperfanAiAgentId;

/**
 * ArchitectureComplianceAiAgent - Framework architecture compliance agent stub.
 */
final class ArchitectureComplianceAiAgent extends AbstractSuperfanAiAgent
{
    public const SuperfanAiAgentId AI_AGENT_ID = SuperfanAiAgentId::ARCHITECTURE_COMPLIANCE;

    /**
     * Run one lightweight superfan AI agent tick.
     */
    public function onTick(): void
    {
    }
}
