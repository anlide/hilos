<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Exception;

use Hilos\HilosException;
use Throwable;

/**
 * Exception thrown when agent daemon factory is not configured
 */
class AgentDaemonFactoryNotConfiguredException extends HilosException
{
    public function __construct(?Throwable $previous = null)
    {
        $message = "Agent daemon factory is not configured. " .
            "Child class must implement getAgentDaemonFactoryClass() method to provide factory for creating agent daemon instances.";
        parent::__construct($message, 0, $previous);
    }
}
