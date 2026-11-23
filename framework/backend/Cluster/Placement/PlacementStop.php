<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;

/** One leader-side stop obligation, retained through acknowledgement and rejoin. */
final class PlacementStop
{
    public bool $sent = false;

    /** @var ?string Query sent in the same outbound turn as this stop */
    public ?string $queryId = null;

    /**
     * @param string $nodeId Host ordered to stop
     * @param PeerStopAgentDTO $order Exact command issued to the host
     */
    public function __construct(public readonly string $nodeId, public readonly PeerStopAgentDTO $order)
    {
    }

    /**
     * @param ?PlacementRecord $record Registry record still awaiting this stop
     * @return bool Whether the stop belongs to this assignment and host
     */
    public function matches(?PlacementRecord $record): bool
    {
        $id = $this->order->placementId;

        return $record?->nodeId === $this->nodeId
            && ($id === null ? $record->placementId === null : $id->equals($record->placementId));
    }
}
