<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Peer\PeerServer;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * Peer frame that moves the freeze into its verification window.
 *
 * Unlike the enable/quiesce pair, one frame serves both directions: an initiator that does not
 * lead sends it to the leader with {@see PeerServer::sendToMaster}, and the leader sends the same
 * frame on to every follower with {@see PeerServer::broadcastToMasters}. The receiving node knows
 * which half it is playing without being told - it either leads the freeze the sender initiated,
 * or it is frozen by the sender - so a second name would carry no information the node does not
 * already hold. Each node then writes {@see ProtectedModeRuntime::PHASE_VERIFYING} locally, which
 * is what lets a verifier land on any node. There is only ever one freeze in flight, so the frame
 * carries no payload; it is the move itself.
 */
final class PeerProtectedModeVerifyDTO extends PeerDTO
{
    /** @var string Wire message type for the protected-mode verify frame */
    public const string MESSAGE_TYPE = 'peer_protected_mode_verify';

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
     * Serializes the verify frame to its wire array.
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
     * Restores a verify frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}
