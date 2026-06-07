<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Exception;

use Throwable;

/**
 * Exception thrown when a worker reports an agent that the daemon manager never registered.
 */
class AgentDaemonNotRegisteredException extends AgentException
{
    /**
     * @param string $agentId Agent identifier the worker reported
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $agentId, ?Throwable $previous = null)
    {
        parent::__construct(
            "Agent daemon '{$agentId}' is not registered in the daemon manager.",
            0,
            $previous,
        );
    }
}
