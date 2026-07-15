<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

/**
 * Liveness reply to a {@see PeerPingDTO}, echoing the ping's optional nonce.
 *
 * Its arrival is the proof of life the ping asked for: receiving it (like any inbound
 * frame) refreshes the peer's last-heard clock and staves off the link timeout. The
 * echoed nonce lets a sender correlate the reply when it supplied one; liveness itself
 * does not depend on it.
 */
final class PeerPongDTO extends PeerDTO
{
    /** @var string Wire message type for the pong frame */
    public const string MESSAGE_TYPE = 'peer_pong';

    /** @var string Payload key: echoed correlation nonce */
    public const string FIELD_NONCE = 'nonce';

    /**
     * @param ?string $nonce Correlation nonce echoed from the ping, or null when none was sent
     */
    public function __construct(
        public readonly ?string $nonce = null,
    ) {
    }

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
     * Serializes the pong frame to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_NONCE => $this->nonce,
        ];
    }

    /**
     * Restores a pong frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     */
    public static function fromArray(array $data): static
    {
        $nonce = $data[self::FIELD_NONCE] ?? null;

        return new static($nonce !== null ? (string)$nonce : null);
    }
}
