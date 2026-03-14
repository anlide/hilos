<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Exception;

use Hilos\HilosException;
use Throwable;

/**
 * Exception thrown when agent daemon creation fails.
 */
class AgentDaemonCreationFailedException extends HilosException
{
    /**
     * Creates exception for failed agent daemon creation.
     *
     * @param string $agentType Agent type identifier
     * @param ?string $agentIndex Agent index (optional, e.g. bot id)
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $agentType, ?string $agentIndex = null, ?Throwable $previous = null)
    {
        $agentId = $agentIndex !== null ? $agentType . ':' . $agentIndex : $agentType;
        $message = "Failed to create agent daemon for agent '{$agentId}'. " .
            "Factory returned null for agent type '{$agentType}'.";
        parent::__construct($message, 0, $previous);
    }
}
