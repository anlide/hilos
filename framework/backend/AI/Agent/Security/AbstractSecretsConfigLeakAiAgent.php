<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\Security;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * AbstractSecretsConfigLeakAiAgent - Project extension point for secrets and config leak checks.
 */
abstract class AbstractSecretsConfigLeakAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::SECRETS_CONFIG_LEAK;
}
