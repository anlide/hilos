<?php

declare(strict_types=1);

namespace Hilos\Exception\Worker;

use Throwable;

/**
 * Exception thrown when agent daemon factory is not configured
 */
class AgentDaemonFactoryNotConfiguredException extends \Exception
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "Agent daemon factory is not configured. " .
            "Child class must implement getAgentDaemonFactoryClass() method to provide factory for creating agent daemon instances.";
        parent::__construct($message, 0, $previous);
    }
}
