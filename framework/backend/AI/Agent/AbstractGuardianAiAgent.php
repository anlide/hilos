<?php

declare(strict_types=1);

namespace Hilos\AI\Agent;

/**
 * AbstractGuardianAiAgent - Shared metadata access for guardian AI agents.
 */
abstract class AbstractGuardianAiAgent extends AbstractAiAgent
{
    /**
     * Get stable guardian agent identifier from child class metadata.
     */
    public function getId(): GuardianAiAgentId
    {
        /** @var GuardianAiAgentId $agentId */
        $agentId = static::AI_AGENT_ID;

        return $agentId;
    }

    /**
     * Get guardian category from enum metadata.
     */
    public function getCategory(): GuardianAiAgentCategory
    {
        return $this->getId()->category();
    }
}
