<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

/** One original connection's interest in a selected remote node or local agent. */
final class PendingPageSource
{
    public int $pending = 0;

    /**
     * @param int $id Original source-membership generation
     * @param string $key Process-private source address key
     * @param PendingPageConnection $connection Original local connection lifetime
     * @param string $agentId Local serving agent, ignored for a remote-node interest
     * @param ?string $nodeId Selected remote node, absent for local-agent interest
     */
    public function __construct(
        public readonly int $id,
        public readonly string $key,
        public readonly PendingPageConnection $connection,
        public readonly string $agentId,
        public readonly ?string $nodeId,
    ) {
    }
}
