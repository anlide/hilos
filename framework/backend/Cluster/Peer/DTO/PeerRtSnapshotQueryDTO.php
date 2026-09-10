<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;

/**
 * Frame a node with an empty copy sends to ask the mesh for the rows of a collection.
 *
 * The second way to come by a copy of an RT collection, beside the owner handing one out. The
 * regular path is owner-driven — {@see PeerRtSnapshotDTO} travels on a handshake and on a change
 * of the ownership signature — and it has a window it cannot cover: while a node's worker fleet
 * is being replaced, the collection is claimed by nobody, so every neighbour answers a rejoining
 * node with silence while holding ten rows of it. This frame is the other direction of the same
 * conversation: the node that holds nothing asks, and any holder may answer with
 * {@see PeerRtReplicaOfferDTO} — the one and only opening through which a non-owner is allowed
 * to hand rows out.
 *
 * It is a question the way {@see PeerRtClaimsQueryDTO} is a question, and unlike that one it
 * names its subject: the asker knows which collections it is missing, and a query without them
 * would ask every holder for its whole runtime. An empty list therefore never travels — a node
 * with nothing to ask about sends no frame at all — and a frame that arrives carrying one is
 * malformed rather than maximal.
 */
final class PeerRtSnapshotQueryDTO extends PeerDTO
{
    /** @var string Wire message type for the RT-snapshot-query frame */
    public const string MESSAGE_TYPE = 'peer_rt_snapshot_query';

    /** @var string Payload key: id of the node asking for the rows */
    public const string FIELD_NODE_ID = 'nodeId';

    /** @var string Payload key: RT collections the asker holds nothing of */
    public const string FIELD_COLLECTION_KEYS = 'collectionKeys';

    /**
     * @param string $nodeId Id of the node asking for the rows
     * @param list<string> $collectionKeys RT collections it holds nothing of, each named once
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly array $collectionKeys,
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
     * Serializes the snapshot query to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_NODE_ID => $this->nodeId,
            self::FIELD_COLLECTION_KEYS => $this->collectionKeys,
        ];
    }

    /**
     * Restores a snapshot query from its wire array.
     *
     * Both fields are refused when absent, and the list is refused when empty as well. The node
     * id is the address every answer is sent to, so a query with nobody behind it could only be
     * dropped. The list is stricter here than the one {@see PeerSourceInterestDTO} reads, where
     * an absent list legitimately says "this node reads nothing": there is no such statement to
     * make here, because a node with nothing to ask for does not ask.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored snapshot query
     * @throws PeerTransportException When the node id is missing or the collection list is missing or empty
     */
    public static function fromArray(array $data): static
    {
        $nodeId = $data[self::FIELD_NODE_ID] ?? null;
        if (!is_string($nodeId) || $nodeId === '') {
            throw new PeerTransportException('Peer RT snapshot query is missing the node id');
        }

        $keysRaw = $data[self::FIELD_COLLECTION_KEYS] ?? null;
        if (!is_array($keysRaw)) {
            throw new PeerTransportException('Peer RT snapshot query is missing the collection keys');
        }

        $collectionKeys = [];
        foreach ($keysRaw as $collectionKey) {
            if (!is_string($collectionKey) || $collectionKey === '') {
                throw new PeerTransportException('Peer RT snapshot query carries a malformed collection key');
            }

            $collectionKeys[] = $collectionKey;
        }

        if ($collectionKeys === []) {
            throw new PeerTransportException('Peer RT snapshot query names no collection');
        }

        return new static(
            nodeId: $nodeId,
            collectionKeys: $collectionKeys,
        );
    }
}
