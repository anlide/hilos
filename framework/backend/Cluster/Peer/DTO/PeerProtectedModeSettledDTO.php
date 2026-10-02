<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Peer\PeerServer;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * Peer frame the leader sends every follower master once every node of the round has quiesced.
 *
 * The close of a quiesce round, sent with {@see PeerServer::broadcastToMasters} at the end of every
 * one of them - the first entry, a repeat from the verification window and the close back from it -
 * and before the ready. The follower writes {@see ProtectedModeRuntime::PHASE_ACTIVE} on its row,
 * which is all active says there: every node has stopped its roster (HIL-1128). Which leader sent it
 * is the frame's sender, so it carries no payload.
 */
final class PeerProtectedModeSettledDTO extends PeerDTO
{
    /** @var string Wire message type for the protected-mode settled frame */
    public const string MESSAGE_TYPE = 'peer_protected_mode_settled';

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
     * Serializes the settled frame to its wire array.
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
     * Restores a settled frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}
