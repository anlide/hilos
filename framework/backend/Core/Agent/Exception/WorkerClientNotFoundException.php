<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Exception;

use Throwable;

/**
 * Exception thrown when worker client is not found for agent.
 */
class WorkerClientNotFoundException extends AgentException
{
    /**
     * Creates exception when worker client cannot be found.
     *
     * @param string $agentId Agent identifier
     * @param int $workerIndex Worker index
     * @param bool $isMonopolistic Whether monopolistic worker was required
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $agentId, int $workerIndex, bool $isMonopolistic, ?Throwable $previous = null)
    {
        $message = "Worker client not found for agent '{$agentId}' (workerIndex={$workerIndex}, isMonopolistic=" . ($isMonopolistic ? 'true' : 'false') . ")";
        parent::__construct($message, 0, $previous);
    }
}
