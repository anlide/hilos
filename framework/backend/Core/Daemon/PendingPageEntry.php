<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

/** Scalar pending-attempt metadata; only the manager's original ledger owns params and payloads. */
final class PendingPageEntry
{
    public int $sourceId = 0;

    public int $sourceConnectionId = 0;

    /**
     * @param int $id Original admission position, stable when the same token is addressed again
     * @param string $acceptKey Original connection key
     * @param string $deliveryId Exact socket-minted attempt token
     * @param string $page Original page
     * @param PendingPageConnection $connection Original local connection lifetime
     * @param PendingPageBucket $bucket Original local page lifetime
     * @param string $agentId Current selected serving agent
     * @param ?string $nodeId Current selected remote node, absent for local delivery
     */
    public function __construct(
        public readonly int $id,
        public readonly string $acceptKey,
        public readonly string $deliveryId,
        public readonly string $page,
        public readonly PendingPageConnection $connection,
        public readonly PendingPageBucket $bucket,
        public string $agentId,
        public ?string $nodeId,
    ) {
    }
}
