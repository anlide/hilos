<?php

declare(strict_types=1);

namespace Hilos\Exception\Worker;

use Throwable;

/**
 * Exception thrown when agent does not exist after startAgent() call
 */
class AgentNotFoundException extends \Exception
{
    public function __construct(string $agentId, ?Throwable $previous = null)
    {
        $message = "Agent '{$agentId}' does not exist after startAgent() call";
        parent::__construct($message, 0, $previous);
    }
}

