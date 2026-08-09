<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;

/**
 * Consensus frame a voter sends back to a candidate in reply to a request-vote.
 *
 * Carries the voter's current term, whether it granted its vote, and its node id
 * so the candidate can count distinct grants toward a majority. A reply whose term
 * is newer than the candidate's makes the candidate step down.
 */
final class PeerVoteReplyDTO extends PeerDTO
{
    /** @var string Wire message type for the vote-reply frame */
    public const string MESSAGE_TYPE = 'peer_vote_reply';

    /** @var string Payload key: voter current term */
    public const string FIELD_TERM = 'term';

    /** @var string Payload key: whether the vote was granted */
    public const string FIELD_VOTE_GRANTED = 'voteGranted';

    /** @var string Payload key: voter node id */
    public const string FIELD_VOTER_ID = 'voterId';

    /**
     * @param int $term Voter current term
     * @param bool $voteGranted Whether the voter granted its vote
     * @param string $voterId Voter node id
     */
    public function __construct(
        public readonly int $term,
        public readonly bool $voteGranted,
        public readonly string $voterId,
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
     * Serializes the vote-reply frame to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_TERM => $this->term,
            self::FIELD_VOTE_GRANTED => $this->voteGranted,
            self::FIELD_VOTER_ID => $this->voterId,
        ];
    }

    /**
     * Restores a vote-reply frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When the voter id is missing
     */
    public static function fromArray(array $data): static
    {
        $voterIdValue = $data[self::FIELD_VOTER_ID] ?? null;
        $voterId = is_string($voterIdValue) ? trim($voterIdValue) : null;
        if ($voterId === null || $voterId === '') {
            throw new PeerTransportException('Peer vote-reply frame is missing the voter id');
        }

        return new static(
            term: (int)($data[self::FIELD_TERM] ?? 0),
            voteGranted: (bool)($data[self::FIELD_VOTE_GRANTED] ?? false),
            voterId: $voterId,
        );
    }
}
