<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\DevOpsEnvironment;

use Hilos\AI\Agent\AbstractGuardianAiAgent;
use Hilos\AI\Agent\GuardianAiAgentId;

/**
 * AbstractDockerInfraAiAgent - Project extension point for docker and infra checks.
 */
abstract class AbstractDockerInfraAiAgent extends AbstractGuardianAiAgent
{
    public const GuardianAiAgentId AI_AGENT_ID = GuardianAiAgentId::DOCKER_INFRA;
}
