<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\Testing;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * TestQualityAiAgent - Framework test quality agent stub.
 */
final class TestQualityAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::TEST_QUALITY;

    /**
     * Run one lightweight guardian AI agent tick.
     */
    public function onTick(): void
    {
    }
}
