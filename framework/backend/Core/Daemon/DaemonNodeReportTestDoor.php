<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\Agent\Exception\AgentNotLinkedToWorkerException;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Command\DTO\CommandRequestDTO;

/** Master entrance for the held test command that has this node's Daemon agent report now. */
interface DaemonNodeReportTestDoor
{
    /**
     * @param CommandRequestDTO $request Held console request
     * @throws AgentNotLinkedToWorkerException When an admitted proxy cannot supply its declared worker channel
     * @throws EventLoopException When the owner cannot arm or cancel a native command deadline
     */
    public function requestNodeReport(CommandRequestDTO $request): void;

    /**
     * @param WorkerClient $worker Original worker channel closing before roster cleanup
     * @throws EventLoopException When the owner cannot arm or cancel a native command deadline
     */
    public function nodeReportWorkerGone(WorkerClient $worker): void;
}
