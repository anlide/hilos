<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronFiredDTO;
use Hilos\Socket\Worker\DTO\WorkerTestCronScheduleResultDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Random\RandomException;

/** Master entrance for held test cron firing and schedule mutations, and their worker outcomes. */
interface CronFireTestDoor
{
    /**
     * @param CommandRequestDTO $request Held console request
     * @throws EventLoopException When the owner cannot arm or cancel a native command deadline
     */
    public function requestCronFire(CommandRequestDTO $request): void;

    /**
     * @param CommandRequestDTO $request Held test-only schedule pause or cancellation
     * @throws RandomException When secure pause entropy cannot be obtained
     * @throws InvalidFormatException When a worker request cannot represent the selector
     * @throws EventLoopException When the owner cannot arm or cancel a native command deadline
     */
    public function requestCronSchedule(CommandRequestDTO $request): void;

    /**
     * @param WorkerClient $worker Connection that delivered the acknowledgement
     * @param WorkerTestCronScheduleResultDTO $ack Immediate mutation outcome
     * @throws EventLoopException When the owner cannot arm or cancel a native command deadline
     */
    public function acceptCronScheduleResult(WorkerClient $worker, WorkerTestCronScheduleResultDTO $ack): void;

    /**
     * @param WorkerClient $worker Connection that delivered the acknowledgement
     * @param WorkerTestCronFiredDTO $ack Parsed worker outcome
     * @throws EventLoopException When the owner cannot arm or cancel a native command deadline
     */
    public function acceptCronFired(WorkerClient $worker, WorkerTestCronFiredDTO $ack): void;

    /**
     * @param WorkerClient $worker Connection that closed
     * @throws EventLoopException When the owner cannot arm or cancel a native command deadline
     */
    public function cronFireWorkerGone(WorkerClient $worker): void;
}
