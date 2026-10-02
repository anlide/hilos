<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * Peer frame that asks the leader to close the freeze back out of its verification window.
 *
 * The mirror of {@see PeerProtectedModeVerifyDTO}, but it travels one way only: from the initiator's
 * node to the leader. The leader closes the window with the quiesce round an entry runs (HIL-1128):
 * every node goes back to {@see ProtectedModeRuntime::PHASE_ACTIVATING}, stops the agents the window
 * had brought back and voids every pass it held, and active is written only once all of them have
 * stopped - so the operator can act on what the verifiers found without first opening the system
 * to real users. Carries no payload.
 */
final class PeerProtectedModeRefreezeDTO extends PeerDTO
{
    /** @var string Wire message type for the protected-mode refreeze frame */
    public const string MESSAGE_TYPE = 'peer_protected_mode_refreeze';

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
     * Serializes the refreeze frame to its wire array.
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
     * Restores a refreeze frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     */
    public static function fromArray(array $data): static
    {
        return new static();
    }
}
