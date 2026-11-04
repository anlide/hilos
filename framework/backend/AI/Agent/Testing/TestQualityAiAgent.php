<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\Testing;

use Hilos\AI\Agent\AbstractSuperfanAiAgent;
use Hilos\AI\Agent\SuperfanAiAgentId;

/**
 * TestQualityAiAgent - Framework test quality agent stub.
 */
final class TestQualityAiAgent extends AbstractSuperfanAiAgent
{
    public const SuperfanAiAgentId AI_AGENT_ID = SuperfanAiAgentId::TEST_QUALITY;

    /**
     * Run one lightweight superfan AI agent tick.
     */
    public function onTick(): void
    {
    }
}
