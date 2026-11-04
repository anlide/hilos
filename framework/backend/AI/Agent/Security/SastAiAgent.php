<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\Security;

use Hilos\AI\Agent\AbstractSuperfanAiAgent;
use Hilos\AI\Agent\SuperfanAiAgentId;

/**
 * SastAiAgent - Framework static security agent stub.
 */
final class SastAiAgent extends AbstractSuperfanAiAgent
{
    public const SuperfanAiAgentId AI_AGENT_ID = SuperfanAiAgentId::SAST;

    /**
     * Run one lightweight superfan AI agent tick.
     */
    public function onTick(): void
    {
    }
}
