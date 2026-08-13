<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * Consensus frame a candidate broadcasts to the master set to solicit votes.
 *
 * Carries the candidate's election term and its node id. A recipient grants the
 * vote when the term is newer than its own, or equal and it has not yet voted for
 * anyone else this term. There is no log-recency check — this raft-like consensus
 * keeps leader-election and anti-split-brain only, without a replicated log.
 */
final class PeerRequestVoteDTO extends PeerDTO
{
    /** @var string Wire message type for the request-vote frame */
    public const string MESSAGE_TYPE = 'peer_request_vote';

    /** @var string Payload key: candidate election term */
    public const string FIELD_TERM = 'term';

    /** @var string Payload key: candidate node id */
    public const string FIELD_CANDIDATE_ID = 'candidateId';

    /**
     * @param int $term Candidate election term
     * @param string $candidateId Candidate node id requesting the vote
     */
    public function __construct(
        public readonly int $term,
        public readonly string $candidateId,
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
     * Serializes the request-vote frame to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_TERM => $this->term,
            self::FIELD_CANDIDATE_ID => $this->candidateId,
        ];
    }

    /**
     * Restores a request-vote frame from its wire array.
     *
     * The term is required rather than defaulted: it is what the vote is decided
     * by, and a missing one read as 0 would solicit votes for an election nobody
     * is holding. The refusal is re-thrown as the exception {@see PeerLink} drops
     * the link on, and a blank id stays a check of its own.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When the term or the candidate id is missing
     */
    public static function fromArray(array $data): static
    {
        try {
            $term = self::requireInt($data, self::FIELD_TERM);
            $candidateId = trim(self::requireString($data, self::FIELD_CANDIDATE_ID));
        } catch (InvalidFormatException $exception) {
            throw new PeerTransportException(
                'Peer request-vote frame is malformed: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }

        if ($candidateId === '') {
            throw new PeerTransportException('Peer request-vote frame is missing the candidate id');
        }

        return new static(
            term: $term,
            candidateId: $candidateId,
        );
    }
}
