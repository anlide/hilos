<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\FrameworkArchitecture;

use Hilos\AI\Agent\AbstractSuperfanAiAgent;
use Hilos\AI\Agent\SuperfanAiAgentId;

/**
 * FrameworkUsageAiAgent - Framework usage validation agent stub.
 */
final class FrameworkUsageAiAgent extends AbstractSuperfanAiAgent
{
    public const SuperfanAiAgentId AI_AGENT_ID = SuperfanAiAgentId::FRAMEWORK_USAGE;

    /**
     * Run one lightweight superfan AI agent tick.
     */
    public function onTick(): void
    {
    }
}
