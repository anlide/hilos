<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Socket\Command\DTO\CommandRequestDTO;

/** Master entrance for the held test command that has this node's Daemon agent report now. */
interface DaemonNodeReportTestDoor
{
    /** @param CommandRequestDTO $request Held console request */
    public function requestNodeReport(CommandRequestDTO $request): void;
}
