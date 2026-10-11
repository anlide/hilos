<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\Exception\InvalidFormatException;

/** One process's identity, CPU counter, and resident memory from a single stat row. */
final readonly class ProcessSample
{
    /**
     * @param int $startTicks Kernel start tick of this process
     * @param int $cpuTicks Sum of user and system CPU ticks
     * @param ?int $residentBytes Resident bytes, or null when unknown
     * @throws InvalidFormatException When a counter or resident byte count is negative
     */
    public function __construct(
        public int $startTicks,
        public int $cpuTicks,
        public ?int $residentBytes,
    ) {
        if ($startTicks < 0 || $cpuTicks < 0 || ($residentBytes !== null && $residentBytes < 0)) {
            throw new InvalidFormatException('Process sample values must be nonnegative.');
        }
    }
}
