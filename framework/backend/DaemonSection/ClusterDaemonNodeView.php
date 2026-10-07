<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

/** One roster member or remembered reporter, including a member with no picture yet. */
final class ClusterDaemonNodeView
{
    public function __construct(
        public readonly string $nodeId,
        public readonly bool $online,
        public readonly ?ClusterDaemonNodeSlot $slot,
    ) {
    }
}
