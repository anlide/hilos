<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\Testing;

use Hilos\AI\Agent\AbstractSuperfanAiAgent;
use Hilos\AI\Agent\SuperfanAiAgentId;

/**
 * TestRunnerAiAgent - Framework test runner agent stub.
 */
final class TestRunnerAiAgent extends AbstractSuperfanAiAgent
{
    public const SuperfanAiAgentId AI_AGENT_ID = SuperfanAiAgentId::TEST_RUNNER;

    /**
     * Run one lightweight superfan AI agent tick.
     */
    public function onTick(): void
    {
    }
}
