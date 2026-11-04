<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\Security;

use Hilos\AI\Agent\AbstractSuperfanAiAgent;
use Hilos\AI\Agent\SuperfanAiAgentId;

/**
 * AbstractSecretsConfigLeakAiAgent - Project extension point for secrets and config leak checks.
 */
abstract class AbstractSecretsConfigLeakAiAgent extends AbstractSuperfanAiAgent
{
    public const SuperfanAiAgentId AI_AGENT_ID = SuperfanAiAgentId::SECRETS_CONFIG_LEAK;
}
