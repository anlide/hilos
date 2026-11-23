<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementQueryDTO;

/** Retains the real query IDs even when a test clears its outgoing frame history. */
trait PlacementQueryFixture
{
    /** @var array<string, PeerPlacementQueryDTO> Last addressed live question per peer */
    private array $targetedQueries = [];

    private ?PeerPlacementQueryDTO $broadcastQuery = null;

    /** @var array<string, true> Peers that answered the current broadcast */
    private array $answeredBroadcast = [];

    /**
     * @param PeerDTO $frame Frame observed on the test mesh
     * @param ?string $nodeId Destination of an addressed query
     */
    private function captureFixtureQuery(PeerDTO $frame, ?string $nodeId = null): void
    {
        if ($frame instanceof PeerPlacementQueryDTO) {
            if ($nodeId === null) {
                $this->broadcastQuery = $frame;
                $this->answeredBroadcast = [];
            } else {
                $this->targetedQueries[$nodeId] = $frame;
            }
        }
    }

    /**
     * @param string $nodeId Intended respondent
     * @return ?PeerPlacementQueryDTO Most recent query still awaiting this peer
     */
    public function takeFixtureQuery(string $nodeId): ?PeerPlacementQueryDTO
    {
        $query = $this->targetedQueries[$nodeId] ?? null;
        unset($this->targetedQueries[$nodeId]);
        if ($query !== null || isset($this->answeredBroadcast[$nodeId])) {
            return $query;
        }

        $this->answeredBroadcast[$nodeId] = true;
        return $this->broadcastQuery;
    }
}
