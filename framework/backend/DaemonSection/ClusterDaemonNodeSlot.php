<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

/** The last complete report from one node and the collector's arrival time. */
final class ClusterDaemonNodeSlot
{
    public function __construct(
        public readonly string $nodeId,
        public readonly NodeDaemonPicture $picture,
        public readonly int $receivedAt,
    ) {
    }
}
