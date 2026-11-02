<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

/** One roster member or remembered reporter, including a member with no picture yet. */
final class ClusterDaemonNodeView
{
    /**
     * @param string $nodeId Stable node id
     * @param bool $online Current roster verdict
     * @param ?ClusterDaemonNodeSlot $slot Last complete report, if any
     * @param ?string $name Roster name, or null when the node has no roster row
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly bool $online,
        public readonly ?ClusterDaemonNodeSlot $slot,
        public readonly ?string $name = null,
    ) {
    }
}
