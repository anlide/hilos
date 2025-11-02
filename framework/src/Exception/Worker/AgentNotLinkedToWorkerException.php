<?php

declare(strict_types=1);

namespace Hilos\Exception\Worker;

use Throwable;

/**
 * Exception thrown when agent daemon is not linked to worker client
 */
class AgentNotLinkedToWorkerException extends \Exception
{
    public function __construct(string $agentId, ?Throwable $previous = null)
    {
        $message = "Agent daemon '{$agentId}' is not linked to worker client. " .
                   "Agent must be started and linked to worker before sending signals.";
        parent::__construct($message, 0, $previous);
    }
}

