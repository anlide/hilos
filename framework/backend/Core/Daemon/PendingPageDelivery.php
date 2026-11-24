<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Cluster\Placement\PlacementId;

/** One process's delivery obligation to a selected agent; absent after completion or cancellation. */
final class PendingPageDelivery
{
    /** A terminal handoff refusal waits for the selected address to become available again. */
    public bool $awaitsRecovery = false;

    /**
     * @param string $acceptKey Browser connection, local or addressed over the mesh
     * @param string $page Requested page
     * @param string $deliveryId Token minted by the socket master
     * @param array<string, mixed> $params Original subscription params, retained for recovery
     * @param string $agentId Selected serving agent
     * @param ?string $nodeId Selected remote node, or null for this master's worker path
     * @param bool $handoff Whether the worker needs socket admission for this identity-routed subscription
     * @param ?PageHandoffIdentity $identity Expected connection row certified by the request
     * @param ?PlacementId $placementOrder Order stamped on a local send or proven by a relay
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $page,
        public readonly string $deliveryId,
        public readonly array $params,
        public readonly string $agentId,
        public readonly ?string $nodeId,
        public readonly bool $handoff = false,
        public readonly ?PageHandoffIdentity $identity = null,
        public ?PlacementId $placementOrder = null,
    ) {
    }
}
