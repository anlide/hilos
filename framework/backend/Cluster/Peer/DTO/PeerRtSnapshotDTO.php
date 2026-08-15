<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;

/**
 * Addressed frame handing one whole RT collection to a node that just joined the mesh.
 *
 * A node that comes up has no history to apply deltas to, so the owner of a collection offers
 * it whole. Unlike {@see PeerRtSyncDTO} this frame is addressed: only the joining node is
 * behind on this collection, and telling the rest would replace copies that are already
 * current. What arrives replaces the receiver's copy — a row the snapshot does not carry is a
 * row that no longer exists, because the owner's copy is the whole truth about it.
 *
 * The rows travel as the collection's own serialized rows, keyed by state id, so the receiver
 * builds them with the very reader a per-row create uses.
 */
final class PeerRtSnapshotDTO extends PeerDTO
{
    /** @var string Wire message type for the RT snapshot frame */
    public const string MESSAGE_TYPE = 'peer_rt_snapshot';

    /** @var string Payload key: id of the node that owns the collection */
    public const string FIELD_ORIGIN_NODE_ID = 'originNodeId';

    /** @var string Payload key: RT collection being handed over */
    public const string FIELD_COLLECTION_KEY = 'collectionKey';

    /** @var string Payload key: the collection's rows, keyed by state id */
    public const string FIELD_ROWS = 'rows';

    /**
     * @param string $originNodeId Id of the node that owns the collection
     * @param string $collectionKey RT collection being handed over
     * @param array<string, array<string, mixed>> $rows Rows by state id, as the owner holds them
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
     * Serializes the snapshot frame to its wire array.
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
     * Restores a snapshot frame from its wire array.
     *
     * An empty collection is a legitimate snapshot rather than a missing field: it says the
     * owner holds nothing under this key, and the receiver is meant to end up holding nothing
     * either. State ids are read back as strings because JSON gives digit-like keys back as
     * integers.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored snapshot frame
     * @throws PeerTransportException When an id is missing or the rows are not a row map
     */
    public static function fromArray(array $data): static
    {
        $originNodeId = $data[self::FIELD_ORIGIN_NODE_ID] ?? null;
        if (!is_string($originNodeId) || $originNodeId === '') {
            throw new PeerTransportException('Peer RT snapshot is missing the origin node id');
        }

        $collectionKey = $data[self::FIELD_COLLECTION_KEY] ?? null;
        if (!is_string($collectionKey) || $collectionKey === '') {
            throw new PeerTransportException('Peer RT snapshot is missing the collection key');
        }

        $rowsRaw = $data[self::FIELD_ROWS] ?? null;
        if (!is_array($rowsRaw)) {
            throw new PeerTransportException('Peer RT snapshot is missing the collection rows');
        }

        $rows = [];
        foreach ($rowsRaw as $stateId => $row) {
            if (!is_array($row)) {
                throw new PeerTransportException("Peer RT snapshot carries a malformed row '{$stateId}'");
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
