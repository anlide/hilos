<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Cluster\NodeRole;

/** One node's complete Daemon picture, as measured by its own agent. */
final class NodeDaemonPicture
{
    /**
     * @param string $nodeId Origin node
     * @param NodeRole $role Node's declared role
     * @param int $sampledAt Measurement time
     * @param ?DaemonProcessRoster $processes Master's last roster, if received
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly NodeRole $role,
        public readonly int $sampledAt,
        public readonly ?DaemonProcessRoster $processes = null,
    ) {
    }

    /**
     * @param int $sampledAt New measurement time
     * @return self The same content measured at another instant
     */
    public function sampledAt(int $sampledAt): self
    {
        return new self($this->nodeId, $this->role, $sampledAt, $this->processes);
    }

    /**
     * @param self $other Picture to compare
     * @return bool Whether the content, apart from its measurement time, matches
     */
    public function sameContent(self $other): bool
    {
        return $this->nodeId === $other->nodeId && $this->role === $other->role && $this->processes == $other->processes;
    }
}
