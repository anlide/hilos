<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;

/**
 * Consensus frame a leader broadcasts to the master set to assert its term.
 *
 * One-way: a leader sends it on an interval and expects no reply — liveness of the
 * master set is read from the membership registry, not from acknowledgements. A
 * recipient seeing a term at least as new as its own accepts the sender as leader
 * and refreshes its election timer; a newer term makes it step down and adopt it.
 */
final class PeerHeartbeatDTO extends PeerDTO
{
    /** @var string Wire message type for the heartbeat frame */
    public const string MESSAGE_TYPE = 'peer_heartbeat';

    /** @var string Payload key: leader term */
    public const string FIELD_TERM = 'term';

    /** @var string Payload key: leader node id */
    public const string FIELD_LEADER_ID = 'leaderId';

    /**
     * @param int $term Leader current term
     * @param string $leaderId Leader node id
     */
    public function __construct(
        public readonly int $term,
        public readonly string $leaderId,
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
     * Serializes the heartbeat frame to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_TERM => $this->term,
            self::FIELD_LEADER_ID => $this->leaderId,
        ];
    }

    /**
     * Restores a heartbeat frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When the leader id is missing
     */
    public static function fromArray(array $data): static
    {
        $leaderId = trim((string)($data[self::FIELD_LEADER_ID] ?? ''));
        if ($leaderId === '') {
            throw new PeerTransportException('Peer heartbeat frame is missing the leader id');
        }

        return new static(
            term: (int)($data[self::FIELD_TERM] ?? 0),
            leaderId: $leaderId,
        );
    }
}
