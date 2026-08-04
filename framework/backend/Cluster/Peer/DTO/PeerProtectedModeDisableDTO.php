<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Peer\PeerServer;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * Peer frame the initiator node sends to the leader to lift the freeze cluster-wide.
 *
 * Sent once the initiator's destructive operation has finished: the initiator node forwards it to
 * the leader with {@see PeerServer::sendToMaster}, and the leader lifts the mode it currently owns
 * on {@see ProtectedModeRuntime} and broadcasts the lift to the followers. The frame carries
 * nothing beyond its type - it is a bare signal, and the leader reads the one thing it needs, the
 * requesting node id, off the link the frame arrived on. The initiator identity that
 * {@see ProtectedModeDisableSignalData} carries to a single-node daemon has no reader here: a
 * cluster authorizes the release by node id.
 */
final class PeerProtectedModeDisableDTO extends PeerDTO
{
    /** @var string Wire message type for the protected-mode disable frame */
    public const string MESSAGE_TYPE = 'peer_protected_mode_disable';

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
     * Serializes the disable frame to its wire array.
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
     * Restores a disable frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload (unused; the frame carries only its type)
     * @return static Restored frame
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}
