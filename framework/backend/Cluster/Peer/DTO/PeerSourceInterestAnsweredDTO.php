<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Core\Daemon\RtCopyConfirmation;

/**
 * Frame a node sends back to a node whose announcement of what it reads it has applied: every
 * snapshot it owed the reader for the collections that announcement added is in the stream ahead
 * of this frame.
 *
 * Sent for every announcement received, one that added nothing included, and in its turn behind
 * the writes streamed to the reader before it, so the reader reads it behind the snapshots it
 * answers for. The reader's copy of a collection it started reading waits for this frame from
 * every node the announcement reached ({@see RtCopyConfirmation}); a node that owns nothing of the
 * collection answers all the same, and its answer is the word that there is nothing to wait for.
 */
final class PeerSourceInterestAnsweredDTO extends PeerDTO
{
    /** @var string Wire message type for the answer to an interest frame */
    public const string MESSAGE_TYPE = 'peer_source_interest_answered';

    /** @var string Payload key: id of the node that answers */
    public const string FIELD_NODE_ID = 'nodeId';

    /** @var string Payload key: number of the announcement it answers */
    public const string FIELD_ANNOUNCEMENT = 'announcement';

    /**
     * @param string $nodeId Id of the node that answers
     * @param int $announcement Number of the announcement it answers
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly int $announcement,
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
     * Serializes the answer to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_NODE_ID => $this->nodeId,
            self::FIELD_ANNOUNCEMENT => $this->announcement,
        ];
    }

    /**
     * Restores an answer from its wire array. Both fields are refused when absent: the node id is
     * who is struck off the wait, and the number is which announcement the answer covers.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored answer
     * @throws PeerTransportException When the node id or the announcement number is missing
     */
    public static function fromArray(array $data): static
    {
        $nodeId = $data[self::FIELD_NODE_ID] ?? null;
        if (!is_string($nodeId) || $nodeId === '') {
            throw new PeerTransportException('Peer source interest answer is missing the node id');
        }
        $announcement = $data[self::FIELD_ANNOUNCEMENT] ?? null;
        if (!is_int($announcement) || $announcement < 0) {
            throw new PeerTransportException('Peer source interest answer is missing the announcement number');
        }

        return new static(
            nodeId: $nodeId,
            announcement: $announcement,
        );
    }
}
