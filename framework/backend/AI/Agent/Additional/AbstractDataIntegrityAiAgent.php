<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\Additional;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * AbstractDataIntegrityAiAgent - Project extension point for data integrity checks.
 */
abstract class AbstractDataIntegrityAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::DATA_INTEGRITY;
}
