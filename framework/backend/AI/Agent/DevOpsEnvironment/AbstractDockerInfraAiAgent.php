<?php

declare(strict_types=1);

namespace Hilos\AI\Agent\DevOpsEnvironment;

use Hilos\AI\Agent\AbstractSuperfanAiAgent;
use Hilos\AI\Agent\SuperfanAiAgentId;

/**
 * AbstractDockerInfraAiAgent - Project extension point for docker and infra checks.
 */
abstract class AbstractDockerInfraAiAgent extends AbstractSuperfanAiAgent
{
    public const SuperfanAiAgentId AI_AGENT_ID = SuperfanAiAgentId::DOCKER_INFRA;
}
