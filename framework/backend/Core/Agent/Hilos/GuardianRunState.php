<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

/**
 * In-memory state of one guardian agent run: status, optional completion deadline, and run generation.
 */
final class GuardianRunState
{
    /**
     * @param GuardianRunStatus $status Current run status
     * @param ?float $completeAt Monotonic completion deadline, or null when the run is not pending
     * @param int $generation Run generation counter, bumped each time a run is started or stopped
     */
    public function __construct(
        public readonly GuardianRunStatus $status,
        public readonly ?float $completeAt,
        public readonly int $generation,
    ) {
    }
}
