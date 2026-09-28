<?php

declare(strict_types=1);

namespace Hilos\Database;

/**
 * The schema rollout claim as the database holds it right now ({@see MigrationClaim::current()}).
 */
final readonly class MigrationClaimRow
{
    /**
     * @param string $holder Name of the process holding the claim
     * @param string $claimedAt When it took the claim, by the database server's clock; printed, never compared
     */
    public function __construct(
        public string $holder,
        public string $claimedAt,
    ) {
    }
}
