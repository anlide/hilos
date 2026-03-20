<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\CodeQuality;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * PhpDocComplianceAiAgent - Framework PHPDoc compliance agent stub.
 */
final class PhpDocComplianceAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::PHPDOC_COMPLIANCE;

    /**
     * Run one lightweight guardian AI agent tick.
     */
    public function onTick(): void
    {
    }
}
