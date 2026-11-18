<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Worker\DTO\WorkerTestAccountErasureProbedDTO;

/** Local master entrance for the worker's bounded erasure probe. */
interface AccountErasureProbeTestDoor
{
    /**
     * @param CommandRequestDTO $request Held console request
     * @throws EventLoopException When the original command deadline cannot be armed or canceled
     */
    public function requestAccountErasureProbe(CommandRequestDTO $request): void;

    /**
     * @param WorkerClient $worker Actual connection delivering the outcome
     * @param WorkerTestAccountErasureProbedDTO $ack Parsed worker outcome
     * @throws EventLoopException When the next live deadline cannot be armed or canceled
     */
    public function acceptAccountErasureProbed(WorkerClient $worker, WorkerTestAccountErasureProbedDTO $ack): void;

    /**
     * @param WorkerClient $worker Original connection that closed
     * @throws EventLoopException When the next live deadline cannot be armed or canceled
     */
    public function accountErasureProbeWorkerGone(WorkerClient $worker): void;
}
