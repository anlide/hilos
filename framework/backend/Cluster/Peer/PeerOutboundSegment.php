<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer;

/** Process-private serialized FIFO span; no raw DTO or replicated state is retained. */
final class PeerOutboundSegment
{
    /**
     * @param string $bytes Serialized bytes not yet appended to the native buffer
     * @param int $endPosition Absolute FIFO end, including bytes still staged
     * @param bool $budgeted Whether the existing two-node upload grants cover this span
     */
    public function __construct(
        public string $bytes,
        public readonly int $endPosition,
        public readonly bool $budgeted,
    ) {
    }
}
