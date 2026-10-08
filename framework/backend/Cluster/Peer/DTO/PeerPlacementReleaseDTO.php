<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

/**
 * Placement frame a node that stops leading broadcasts to every linked node.
 *
 * Carries no payload: the link names the sender, and a node that answered to it fences its
 * placed work unless a leader takes it over (HIL-1287).
 */
final class PeerPlacementReleaseDTO extends PeerDTO
{
    /** @var string Wire message type for the placement-release frame */
    public const string MESSAGE_TYPE = 'peer_placement_release';

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
     * Serializes the placement release to its wire array.
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
     * Restores a placement release from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored release
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}
