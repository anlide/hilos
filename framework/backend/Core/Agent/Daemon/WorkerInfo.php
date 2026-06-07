<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Daemon;

/**
 * Worker placement for an agent daemon: the worker index and whether the worker is monopolistic.
 */
final class WorkerInfo
{
    /**
     * @param int $workerIndex Worker index
     * @param bool $isMonopolistic True when the hosting worker is monopolistic
     */
    public function __construct(
        public readonly int $workerIndex,
        public readonly bool $isMonopolistic,
    ) {
    }
}
