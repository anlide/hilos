<?php

declare(strict_types=1);

namespace Hilos\Exception\Worker;

use Throwable;

/**
 * Exception thrown when worker client is not found for agent
 */
class WorkerClientNotFoundException extends \Exception
{
    public function __construct(string $agentId, int $workerIndex, bool $isMonopolistic, ?Throwable $previous = null)
    {
        $message = "Worker client not found for agent '{$agentId}' (workerIndex={$workerIndex}, isMonopolistic=" . ($isMonopolistic ? 'true' : 'false') . ")";
        parent::__construct($message, 0, $previous);
    }
}
