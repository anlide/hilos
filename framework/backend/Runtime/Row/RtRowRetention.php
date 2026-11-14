<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

/** Physical pages count once; logical lease bytes may overlap between holders. */
final readonly class RtRowRetention
{
    /**
     * @param int $physicalBytes Allocated page and payload bytes
     * @param int $pages Allocated bounded pages
     * @param int $retiredBytes Logical bytes held by retirement, including queued child edges
     * @param int $queuedBytes Logical bytes of released roots whose retirement has not begun
     * @param int $pendingReleases References awaiting retirement
     * @param array<string, int> $heldBytes Logical bytes by the seven holder roles
     * @param array<string, int> $leases Root leases by the seven holder roles
     */
    public function __construct(
        public int $physicalBytes,
        public int $pages,
        public int $retiredBytes,
        public int $queuedBytes,
        public int $pendingReleases,
        private array $heldBytes,
        private array $leases,
    ) {
    }

    /**
     * @param RtRowRole $role Holder role
     * @return int Logical bytes still held by that role, including queued releases
     */
    public function bytes(RtRowRole $role): int
    {
        return ($this->heldBytes[$role->name] ?? 0) + ($role === RtRowRole::Retired ? $this->retiredBytes : 0);
    }

    /**
     * @param RtRowRole $role Holder role
     * @return int Root leases whose reference has not actually been removed
     */
    public function leases(RtRowRole $role): int
    {
        return $this->leases[$role->name] ?? 0;
    }
}
