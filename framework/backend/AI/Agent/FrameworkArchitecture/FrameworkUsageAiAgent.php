<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\FrameworkArchitecture;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * FrameworkUsageAiAgent - Framework usage validation agent stub.
 */
final class FrameworkUsageAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::FRAMEWORK_USAGE;

    /**
     * Run one lightweight guardian AI agent tick.
     */
    public function onTick(): void
    {
    }
}
