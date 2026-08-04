<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * Peer frame a follower sends back to the leader once it has quiesced.
 *
 * The reply to {@see PeerProtectedModeQuiesceDTO}: the follower has stopped its agents (bar the
 * initiator) and written the freeze onto {@see ProtectedModeRuntime}
 * locally, and now reports readiness with {@see PeerServer::sendToMaster}. The leader activates
 * the mode once every follower has answered. Which follower reported is the frame's sender, so the
 * report itself carries no payload — the frame is the readiness.
 */
final class PeerProtectedModeQuiescedDTO extends PeerDTO
{
    /** @var string Wire message type for the protected-mode quiesced frame */
    public const string MESSAGE_TYPE = 'peer_protected_mode_quiesced';

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
     * Serializes the quiesced frame to its wire array.
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
     * Restores a quiesced frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}
