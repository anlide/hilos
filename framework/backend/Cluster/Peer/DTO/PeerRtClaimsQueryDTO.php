<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

/**
 * Claim frame a fresh leader broadcasts to ask every node what its agents own of the RT state.
 *
 * Carries no payload: it is the trigger for the leader-change rebuild, and each node answers
 * with a {@see PeerRtClaimsDTO}. A leader broadcasts it once on winning the term, having
 * cleared its (soft-state) map of rights first — the same rebuild-from-the-mesh stance
 * {@see PeerPlacementQueryDTO} takes for placement.
 */
final class PeerRtClaimsQueryDTO extends PeerDTO
{
    /** @var string Wire message type for the RT-claims-query frame */
    public const string MESSAGE_TYPE = 'peer_rt_claims_query';

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
     * Serializes the claims query to its wire array.
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
     * Restores a claims query from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored query
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}
