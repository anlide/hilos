<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;

/**
 * Peer frame the leader sends back to the initiator node when protected mode cannot be entered.
 *
 * The counterpart to {@see PeerProtectedModeReadyDTO}: when a freeze request cannot proceed
 * (e.g. another operation is running, the standing freeze belongs to another agent, or no
 * runtime state is mounted), the leader routes this frame to the initiator node with the
 * operator-facing reason so that the waiting agent fails immediately rather than timing out.
 */
final class PeerProtectedModeRefusedDTO extends PeerDTO
{
    /** @var string Wire message type for the protected-mode refused frame */
    public const string MESSAGE_TYPE = 'peer_protected_mode_refused';

    /** @var string Envelope key carrying the refusal reason */
    public const string FIELD_REASON = 'reason';

    /**
     * @param string $reason Operator-facing refusal reason
     */
    public function __construct(
        public readonly string $reason,
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
     * Serializes the refused frame to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_REASON => $this->reason,
        ];
    }

    /**
     * Restores a refused frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When the reason payload is not a string
     */
    public static function fromArray(array $data): static
    {
        $reason = $data[self::FIELD_REASON] ?? null;
        if (!is_string($reason)) {
            throw new PeerTransportException('Peer protected-mode refused frame carries a non-string reason');
        }

        return new static($reason);
    }
}
