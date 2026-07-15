<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

/**
 * Liveness frame a link sends when it has heard nothing from the peer for the keepalive
 * interval, to prove the connection is still alive before the timeout closes it.
 *
 * The recipient answers with a {@see PeerPongDTO} echoing the optional nonce. The nonce
 * is not required for liveness — any inbound frame refreshes the peer's last-heard clock —
 * so it travels only when a sender wants to correlate a specific pong. A busy link never
 * emits a ping, so this frame is silent on an established, chatty connection.
 */
final class PeerPingDTO extends PeerDTO
{
    /** @var string Wire message type for the ping frame */
    public const string MESSAGE_TYPE = 'peer_ping';

    /** @var string Payload key: correlation nonce */
    public const string FIELD_NONCE = 'nonce';

    /**
     * @param ?string $nonce Optional correlation nonce echoed back in the pong
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
     * Serializes the ping frame to its wire array.
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
     * Restores a ping frame from its wire array.
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
