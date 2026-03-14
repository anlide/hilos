<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Exception;

use Throwable;

/**
 * Exception thrown when agent creation fails.
 */
class AgentCreationFailedException extends AgentException
{
    /**
     * Creates exception with agent type, optional index and previous exception.
     *
     * @param string $agentType Agent type identifier
     * @param ?string $agentIndex Agent index or null
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $agentType, ?string $agentIndex = null, ?Throwable $previous = null)
    {
        $agentId = $agentIndex !== null ? $agentType . ':' . $agentIndex : $agentType;
        $message = "Failed to create agent for agent '{$agentId}'. " .
            "Factory returned null for agent type '{$agentType}'.";
        parent::__construct($message, 0, $previous);
    }
}
