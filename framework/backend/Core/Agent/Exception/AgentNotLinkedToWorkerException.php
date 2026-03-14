<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Exception;

use Hilos\HilosException;
use Throwable;

/**
 * Exception thrown when agent daemon is not linked to worker client.
 */
class AgentNotLinkedToWorkerException extends HilosException
{
    /**
     * Creates exception for unlinked agent daemon.
     *
     * @param string $agentId Agent identifier
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $agentId, ?Throwable $previous = null)
    {
        $message = "Agent daemon '{$agentId}' is not linked to worker client. " .
            "Agent must be started and linked to worker before sending signals.";
        parent::__construct($message, 0, $previous);
    }
}
