<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

/** One placement or stop request; a query may judge only this exact wait. */
final class PlacementAcknowledgement
{
    public bool $asked = false;

    /** @var ?int Last report entrance observed before this exact question was sent */
    public ?int $askedAfterReport = null;

    /**
     * @param string $nodeId Host the query may judge
     * @param PlacementState $state Placement or stop acknowledgement
     * @param float $deadline Original monotonic query due
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly PlacementState $state,
        public readonly float $deadline,
    ) {
    }
}
