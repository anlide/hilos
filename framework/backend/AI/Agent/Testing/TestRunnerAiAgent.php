<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\Testing;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * TestRunnerAiAgent - Framework test runner agent stub.
 */
final class TestRunnerAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::TEST_RUNNER;

    /**
     * Run one lightweight guardian AI agent tick.
     */
    public function onTick(): void
    {
    }
}
