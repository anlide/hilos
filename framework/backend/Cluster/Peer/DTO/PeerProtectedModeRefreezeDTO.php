<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * Peer frame that closes the freeze back out of its verification window.
 *
 * The mirror of {@see PeerProtectedModeVerifyDTO} and, like it, one frame for both directions.
 * Each receiving node writes {@see ProtectedModeRuntime::PHASE_ACTIVE} again, stops the agents the
 * window had brought back and voids every pass it held, so the operator can act on what the
 * verifiers found without first opening the system to real users. Carries no payload.
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
