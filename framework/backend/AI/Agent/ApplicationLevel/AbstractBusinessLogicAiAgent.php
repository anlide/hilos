<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\ApplicationLevel;

use Hilos\AI\Agent\AbstractSuperfanAiAgent;
use Hilos\AI\Agent\SuperfanAiAgentId;

/**
 * AbstractBusinessLogicAiAgent - Project extension point for business logic checks.
 */
abstract class AbstractBusinessLogicAiAgent extends AbstractSuperfanAiAgent
{
    public const SuperfanAiAgentId AI_AGENT_ID = SuperfanAiAgentId::BUSINESS_LOGIC;
}
