<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

/** One process's delivery obligation to a selected agent; absent after completion or cancellation. */
final readonly class PendingPageDelivery
{
    /**
     * @param string $acceptKey Browser connection, local or addressed over the mesh
     * @param string $page Requested page
     * @param string $deliveryId Token minted by the socket master
     * @param array<string, mixed> $params Original subscription params, retained for recovery
     * @param string $agentId Selected serving agent
     * @param ?string $nodeId Selected remote node, or null for this master's worker path
     */
    public function __construct(
        public string $acceptKey,
        public string $page,
        public string $deliveryId,
        public array $params,
        public string $agentId,
        public ?string $nodeId,
    ) {
    }
}
