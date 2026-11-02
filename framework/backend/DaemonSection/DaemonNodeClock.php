<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

/** One node's clock relative to the current picture's leader. */
final readonly class DaemonNodeClock
{
    /**
     * @param bool $reference Whether this node is the picture's clock reference
     * @param ?int $offsetMs Peer clock minus leader clock in milliseconds, or null before a measurement
     * @param ?int $measuredAt Leader's epoch milliseconds at completion, or null before a measurement
     * @param ?int $thresholdMs Positive threshold from the same leader frame, or null before that frame
     * @param bool $beyond Whether the completed offset strictly exceeds the leader's threshold
     */
    public function __construct(
        public bool $reference,
        public ?int $offsetMs,
        public ?int $measuredAt,
        public ?int $thresholdMs,
        public bool $beyond,
    ) {
    }

    /**
     * @return array{reference: bool, offsetMs: ?int, measuredAt: ?int, thresholdMs: ?int, beyond: bool} Browser clock verdict
     */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'offsetMs' => $this->offsetMs,
            'measuredAt' => $this->measuredAt,
            'thresholdMs' => $this->thresholdMs,
            'beyond' => $this->beyond,
        ];
    }
}
