<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\Testing;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * TestCoverageAiAgent - Framework test coverage agent stub.
 */
final class TestCoverageAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::TEST_COVERAGE;

    /**
     * Run one lightweight guardian AI agent tick.
     */
    public function onTick(): void
    {
    }
}
