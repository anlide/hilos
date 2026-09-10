<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;

/**
 * Addressed frame answering a {@see PeerRtSnapshotQueryDTO} with rows the asker does not hold.
 *
 * The holder's half of the ask-for-a-copy exchange, and deliberately not a flag on
 * {@see PeerRtSnapshotDTO}. That frame is applied by replacement within a scope, and the rule is
 * sound only because its sender owns what it sends: a row the snapshot leaves out is a row that
 * no longer exists. The sender of an offer owns nothing and says something else — "here are
 * copies, take what you are missing" — so the receiver tops its collection up row by row and
 * removes nothing. That is why the offer carries no scope: a scope is this protocol's language
 * for deletion, and there is nothing here to delete.
 *
 * Topping up rather than replacing is also what makes several answers to one query harmless.
 * Four holders reply with the same copies, the first fills the gap, and the rest land on rows
 * that are already there. It equally covers the window between the question and the answer, in
 * which the asker's own freshly started workers may have written rows of their own.
 *
 * The node named as the origin is the node that WROTE the rows, not the one that forwarded
 * them. Replica staleness on a lost link is marked by that name, so naming the holder would
 * freeze live rows while their owner is up, and leave a dead owner's rows hot. A holder
 * therefore splits its answer by origin and sends one frame per group; a row whose origin it
 * does not know travels in no frame at all.
 */
final class PeerRtReplicaOfferDTO extends PeerDTO
{
    /** @var string Wire message type for the RT-replica-offer frame */
    public const string MESSAGE_TYPE = 'peer_rt_replica_offer';

    /** @var string Payload key: id of the node that wrote these rows */
    public const string FIELD_ORIGIN_NODE_ID = 'originNodeId';

    /** @var string Payload key: RT collection the rows belong to */
    public const string FIELD_COLLECTION_KEY = 'collectionKey';

    /** @var string Payload key: the offered rows, keyed by state id */
    public const string FIELD_ROWS = 'rows';

    /**
     * @param string $originNodeId Id of the node that wrote these rows
     * @param string $collectionKey RT collection the rows belong to
     * @param array<string, array<string, mixed>> $rows Rows by state id, as the holder keeps them
     */
    public function __construct(
        public readonly string $originNodeId,
        public readonly string $collectionKey,
        public readonly array $rows,
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
     * Serializes the replica offer to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_ORIGIN_NODE_ID => $this->originNodeId,
            self::FIELD_COLLECTION_KEY => $this->collectionKey,
            self::FIELD_ROWS => $this->rows,
        ];
    }

    /**
     * Restores a replica offer from its wire array.
     *
     * State ids are read back as strings because JSON gives digit-like keys back as integers,
     * the way {@see PeerRtSnapshotDTO} reads them. Unlike a snapshot, an offer with no rows is
     * not a statement a holder ever has to make — silence says the same thing and costs a hop
     * less — so an empty row map is refused rather than applied as a no-op.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored replica offer
     * @throws PeerTransportException When an id is missing, or the rows are absent, empty or not a row map
     */
    public static function fromArray(array $data): static
    {
        $originNodeId = $data[self::FIELD_ORIGIN_NODE_ID] ?? null;
        if (!is_string($originNodeId) || $originNodeId === '') {
            throw new PeerTransportException('Peer RT replica offer is missing the origin node id');
        }

        $collectionKey = $data[self::FIELD_COLLECTION_KEY] ?? null;
        if (!is_string($collectionKey) || $collectionKey === '') {
            throw new PeerTransportException('Peer RT replica offer is missing the collection key');
        }

        $rowsRaw = $data[self::FIELD_ROWS] ?? null;
        if (!is_array($rowsRaw) || $rowsRaw === []) {
            throw new PeerTransportException('Peer RT replica offer is missing the offered rows');
        }

        $rows = [];
        foreach ($rowsRaw as $stateId => $row) {
            if (!is_array($row)) {
                throw new PeerTransportException("Peer RT replica offer carries a malformed row '{$stateId}'");
            }

            $rows[(string)$stateId] = $row;
        }

        return new static(
            originNodeId: $originNodeId,
            collectionKey: $collectionKey,
            rows: $rows,
        );
    }
}
