<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\ApplicationLevel;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * AbstractBusinessLogicAiAgent - Project extension point for business logic checks.
 */
abstract class AbstractBusinessLogicAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::BUSINESS_LOGIC;
}
