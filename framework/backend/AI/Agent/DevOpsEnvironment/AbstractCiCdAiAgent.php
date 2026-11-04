<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\DevOpsEnvironment;

use Hilos\AI\Agent\AbstractSuperfanAiAgent;
use Hilos\AI\Agent\SuperfanAiAgentId;

/**
 * AbstractCiCdAiAgent - Project extension point for CI and CD checks.
 */
abstract class AbstractCiCdAiAgent extends AbstractSuperfanAiAgent
{
    public const SuperfanAiAgentId AI_AGENT_ID = SuperfanAiAgentId::CI_CD;
}
