<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

/**
 * Placement frame a fresh leader broadcasts to ask every node which agents it hosts.
 *
 * Carries no payload: it is the trigger for the leader-change rebuild, and each node
 * answers with a {@see PeerPlacementReportDTO}. A leader broadcasts it once on winning
 * the term, having cleared its (soft-state) placement view first.
 */
final class PeerPlacementQueryDTO extends PeerDTO
{
    /** @var string Wire message type for the placement-query frame */
    public const string MESSAGE_TYPE = 'peer_placement_query';

    /**
     * Returns the wire message type of this frame.
     *
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * Serializes the placement query to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
        ];
    }

    /**
     * Restores a placement query from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored query
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}
