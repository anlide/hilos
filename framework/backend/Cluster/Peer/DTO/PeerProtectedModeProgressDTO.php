<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Peer\PeerServer;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * Peer frame that reports the operation behind a freeze still moving.
 *
 * Travels one way only - from the node running the operation to the leader, with
 * {@see PeerServer::sendToMaster} - and is never broadcast onward, unlike the verification frames:
 * the mark exists to be read by the watchdog, and the watchdog runs on the leader alone. The leader
 * stamps {@see ProtectedModeRuntime::$progressAt} from its OWN clock when this lands, which is why
 * the frame carries no timestamp: a value read off the wire would let a node with a skewed clock
 * decide how long another node's freeze may stay silent. There is only ever one freeze in flight,
 * so it carries no identifier either; the frame is the fact.
 */
final class PeerProtectedModeProgressDTO extends PeerDTO
{
    /** @var string Wire message type for the protected-mode progress frame */
    public const string MESSAGE_TYPE = 'peer_protected_mode_progress';

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
     * Serializes the progress frame to its wire array.
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
     * Restores a progress frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}
