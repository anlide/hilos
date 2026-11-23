<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

/** The source master's replay obligation, shared by the original holds until they settle. */
final class PlacementRecovery
{
    public bool $canceled = false;

    /** @var PlacementId Most recent order this node knew locally before its hosted record was removed */
    private PlacementId $lastLocalOrder;

    /** @param PlacementId $origin Assignment the source master was authorized to replay */
    public function __construct(public readonly PlacementId $origin)
    {
        $this->lastLocalOrder = $origin;
    }

    /** @param PlacementId $order Exact local order that the replay ran under */
    public function noteLocalOrder(PlacementId $order): void
    {
        if ($this->lastLocalOrder->precedes($order)) {
            $this->lastLocalOrder = $order;
        }
    }

    /**
     * @param PlacementId $order Terminal order named by the complete view
     * @return bool Whether that verdict applies to the last known local order
     */
    public function isAnsweredBy(PlacementId $order): bool
    {
        return $this->lastLocalOrder->equals($order);
    }

    /**
     * @param PlacementId $order Order from the current leader's accepted complete view
     * @return bool Whether the view has moved beyond the source's last known local order
     */
    public function precedesRemoteOrder(PlacementId $order): bool
    {
        return $this->lastLocalOrder->precedes($order);
    }
}
