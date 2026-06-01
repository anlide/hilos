<?php

declare(strict_types=1);

namespace Hilos\Core\Router\Destination;

/**
 * AgentDestination - Routes a signal to an agent type, optionally a specific indexed instance.
 */
final class AgentDestination implements Destination
{
    /**
     * @param string $agentType Target agent type
     * @param ?string $agentIndex Agent instance index, or null for unindexed agents
     */
    public function __construct(
        public readonly string $agentType,
        public readonly ?string $agentIndex = null,
    ) {
    }
}
