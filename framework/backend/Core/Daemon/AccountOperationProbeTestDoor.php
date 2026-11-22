<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Worker\DTO\WorkerTestAccountOperationProbedDTO;

/** Local master entrance for the worker's bounded account operation probe. */
interface AccountOperationProbeTestDoor
{
    /**
     * @param CommandRequestDTO $request Held console request
     * @throws EventLoopException When the original command deadline cannot be armed or canceled
     */
    public function requestAccountOperationProbe(CommandRequestDTO $request): void;

    /**
     * @param WorkerClient $worker Actual connection delivering the outcome
     * @param WorkerTestAccountOperationProbedDTO $ack Parsed worker outcome
     * @throws EventLoopException When the next live deadline cannot be armed or canceled
     */
    public function acceptAccountOperationProbed(WorkerClient $worker, WorkerTestAccountOperationProbedDTO $ack): void;

    /**
     * @param WorkerClient $worker Original connection that closed
     * @throws EventLoopException When the next live deadline cannot be armed or canceled
     */
    public function accountOperationProbeWorkerGone(WorkerClient $worker): void;
}
