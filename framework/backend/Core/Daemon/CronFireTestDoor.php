<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronFiredDTO;

/** Master entrance for the held test command that fires one cron rule once now, and its worker outcome. */
interface CronFireTestDoor
{
    /** @param CommandRequestDTO $request Held console request */
    public function requestCronFire(CommandRequestDTO $request): void;

    /**
     * @param WorkerClient $worker Connection that delivered the acknowledgement
     * @param WorkerTestCronFiredDTO $ack Parsed worker outcome
     */
    public function acceptCronFired(WorkerClient $worker, WorkerTestCronFiredDTO $ack): void;

    /** @param WorkerClient $worker Connection that closed */
    public function cronFireWorkerGone(WorkerClient $worker): void;
}
