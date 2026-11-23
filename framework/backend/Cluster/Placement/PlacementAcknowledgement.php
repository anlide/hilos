<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;


/** One placement or stop request; a query may judge only this exact wait. */
final class PlacementAcknowledgement
{
    public bool $asked = false;

    /** @var ?string Query that can decide this exact wait */
    public ?string $queryId = null;

    /**
     * @param string $nodeId Host the query may judge
     * @param PlacementState $state Placement or stop acknowledgement
     * @param float $deadline Original monotonic query due
     * @param ?PlacementId $placementId Assignment this wait was armed for
     * @param ?PlacementStop $stop Exact stop that the query follows, when it waits on a stop
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly PlacementState $state,
        public readonly float $deadline,
        public readonly ?PlacementId $placementId,
        public readonly ?PlacementStop $stop = null,
    ) {
    }

    /**
     * @param PlacementRecord $record Record to judge with this wait's query
     * @return bool Whether the record still names the same wait
     */
    public function matches(PlacementRecord $record): bool
    {
        if ($this->stop !== null) {
            return $this->stop->matches($record);
        }

        return $this->nodeId === $record->nodeId && $this->state === $record->state
            && ($this->placementId === null ? $record->placementId === null : $this->placementId->equals($record->placementId));
    }
}
