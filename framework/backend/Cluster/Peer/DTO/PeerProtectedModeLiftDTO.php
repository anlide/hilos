<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * Peer frame the leader broadcasts to every follower to lift the freeze cluster-wide.
 *
 * The cluster-wide half of the release: once the initiator has finished and asked over
 * {@see PeerProtectedModeDisableDTO}, the leader sends this to each follower with
 * {@see PeerServer::broadcastToMasters}. The follower clears the freeze on its local
 * {@see ProtectedModeRuntime} and resumes normal operation. There is
 * only ever one freeze in flight, so the frame carries no payload; it is the lift itself.
 */
final class PeerProtectedModeLiftDTO extends PeerDTO
{
    /** @var string Wire message type for the protected-mode lift frame */
    public const string MESSAGE_TYPE = 'peer_protected_mode_lift';

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
     * Serializes the lift frame to its wire array.
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
     * Restores a lift frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}
