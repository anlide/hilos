<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Exception;

use Hilos\HilosException;
use Throwable;

/**
 * Exception thrown when no suitable worker is available for agent.
 */
class NoSuitableWorkerException extends HilosException
{
    /**
     * Creates no suitable worker exception.
     *
     * @param string $workerType Worker type (e.g. regular, monopolistic)
     * @param bool $requiresMonopolistic Whether monopolistic worker is required
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $workerType, bool $requiresMonopolistic, ?Throwable $previous = null)
    {
        $message = "No suitable {$workerType} worker available. " .
            "Required: " . ($requiresMonopolistic ? "monopolistic worker without agents" : "regular worker") . ".";
        parent::__construct($message, 0, $previous);
    }
}
