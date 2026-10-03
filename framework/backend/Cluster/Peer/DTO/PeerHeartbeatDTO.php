<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * Consensus frame a leader broadcasts to the master set to assert its term.
 *
 * One-way: a leader sends it on an interval and expects no reply — liveness of the
 * master set is read from the membership registry, not from acknowledgements. A
 * recipient seeing a term at least as new as its own accepts the sender as leader
 * and refreshes its election timer; a newer term makes it step down and adopt it.
 * The leader also reports whether its WebSocket is open, so follower masters can
 * open their own browser entry after the leader is ready (HIL-1304).
 */
final class PeerHeartbeatDTO extends PeerDTO
{
    /** @var string Wire message type for the heartbeat frame */
    public const string MESSAGE_TYPE = 'peer_heartbeat';

    /** @var string Payload key: leader term */
    public const string FIELD_TERM = 'term';

    /** @var string Payload key: leader node id */
    public const string FIELD_LEADER_ID = 'leaderId';

    /** @var string Payload key: whether the leader accepts browsers */
    public const string FIELD_WEB_SOCKET_OPEN = 'webSocketOpen';

    /**
     * @param int $term Leader current term
     * @param string $leaderId Leader node id
     * @param bool $webSocketOpen Whether the leader's WebSocket is open
     */
    public function __construct(
        public readonly int $term,
        public readonly string $leaderId,
        public readonly bool $webSocketOpen,
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
            self::FIELD_WEB_SOCKET_OPEN => $this->webSocketOpen,
        ];
    }

    /**
     * Restores a heartbeat frame from its wire array.
     *
     * The term is required rather than defaulted: term 0 is the term before any
     * election, so reading a missing one as 0 would assert a leader of a term no
     * node is in. The refusal is re-thrown as the exception {@see PeerLink} drops
     * the link on, and a blank id stays a check of its own.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When a required heartbeat field is missing or malformed
     */
    public static function fromArray(array $data): static
    {
        try {
            $term = self::requireInt($data, self::FIELD_TERM);
            $leaderId = trim(self::requireString($data, self::FIELD_LEADER_ID));
            $webSocketOpen = self::requireBool($data, self::FIELD_WEB_SOCKET_OPEN);
        } catch (InvalidFormatException $exception) {
            throw new PeerTransportException(
                'Peer heartbeat frame is malformed: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }

        if ($leaderId === '') {
            throw new PeerTransportException('Peer heartbeat frame is missing the leader id');
        }

        return new static(
            term: $term,
            leaderId: $leaderId,
            webSocketOpen: $webSocketOpen,
        );
    }
}
