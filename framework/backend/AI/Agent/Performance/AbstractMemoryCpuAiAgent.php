<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\Performance;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * AbstractMemoryCpuAiAgent - Project extension point for memory and CPU checks.
 */
abstract class AbstractMemoryCpuAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::MEMORY_CPU;
}
