<?php

declare(strict_types=1);

namespace Hilos\Cluster\Clock;

/** Best exchange of a completed clock measurement series. */
final readonly class ClusterClockReading
{
    /**
     * @param string $nodeId Measured peer
     * @param int $offsetMs Peer clock minus leader clock, in milliseconds
     * @param int $roundTripMs Round trip of the best exchange, rounded up to milliseconds
     * @param int $measuredAt Leader's epoch milliseconds at completion
     */
    public function __construct(
        public string $nodeId,
        public int $offsetMs,
        public int $roundTripMs,
        public int $measuredAt,
    ) {
    }
}
