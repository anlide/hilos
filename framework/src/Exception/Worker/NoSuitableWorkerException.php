<?php

declare(strict_types=1);

namespace Hilos\Exception\Worker;

use Throwable;

/**
 * Exception thrown when no suitable worker is available for agent
 */
class NoSuitableWorkerException extends \Exception
{
    public function __construct(string $workerType, bool $requiresMonopolistic, ?Throwable $previous = null)
    {
        $message = "No suitable {$workerType} worker available. " .
            "Required: " . ($requiresMonopolistic ? "monopolistic worker without agents" : "regular worker") . ".";
        parent::__construct($message, 0, $previous);
    }
}
