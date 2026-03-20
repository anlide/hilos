<?php

declare(strict_types=1);

namespace Hilos\AI\Agent;

/**
 * AiAgentInterface - Base contract for AI agent families.
 */
interface AiAgentInterface
{
    /**
     * Get stable agent identifier.
     */
    public function getId(): AiAgentIdInterface;

    /**
     * Get agent category.
     */
    public function getCategory(): AiAgentCategoryInterface;

    /**
     * Run one lightweight AI agent tick.
     */
    public function onTick(): void;
}
