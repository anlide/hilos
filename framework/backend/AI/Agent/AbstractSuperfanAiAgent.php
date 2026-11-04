<?php

declare(strict_types=1);

namespace Hilos\AI\Agent;

/**
 * AbstractSuperfanAiAgent - Shared metadata access for superfan AI agents.
 */
abstract class AbstractSuperfanAiAgent extends AbstractAiAgent
{
    /**
     * Get stable superfan agent identifier from child class metadata.
     */
    public function getId(): SuperfanAiAgentId
    {
        /** @var SuperfanAiAgentId $agentId */
        $agentId = static::AI_AGENT_ID;

        return $agentId;
    }

    /**
     * Get superfan category from enum metadata.
     */
    public function getCategory(): SuperfanAiAgentCategory
    {
        return $this->getId()->category();
    }
}
