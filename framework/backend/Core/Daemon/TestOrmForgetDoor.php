<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Worker\DTO\WorkerTestOrmForgottenDTO;

/** Master entrance for a held test command and its connection-bound outcome. */
interface TestOrmForgetDoor
{
    /** @param CommandRequestDTO $request Held console request */
    public function requestOrmForget(CommandRequestDTO $request): void;

    /**
     * @param WorkerClient $worker Connection that delivered the acknowledgement
     * @param WorkerTestOrmForgottenDTO $ack Parsed worker outcome
     */
    public function acceptOrmForgotten(WorkerClient $worker, WorkerTestOrmForgottenDTO $ack): void;

    /** @param WorkerClient $worker Connection that closed */
    public function ormForgetWorkerGone(WorkerClient $worker): void;
}
