<?php

declare(strict_types=1);

namespace Hilos\Exception\Worker;

use Hilos\Exception\HilosException;
use Throwable;

/**
 * Exception thrown when agent creation fails
 */
class AgentCreationFailedException extends HilosException
{
    public function __construct(string $agentType, ?string $agentIndex = null, ?Throwable $previous = null)
    {
        $agentId = $agentIndex !== null ? $agentType . ':' . $agentIndex : $agentType;
        $message = "Failed to create agent for agent '{$agentId}'. " .
            "Factory returned null for agent type '{$agentType}'.";
        parent::__construct($message, 0, $previous);
    }
}
