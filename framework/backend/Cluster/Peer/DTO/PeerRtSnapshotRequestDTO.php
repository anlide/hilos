<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;

/**
 * Frame a node sends to the node at the other end of a link to ask for one more snapshot of an
 * RT collection: its copy of that node's rows lost its base.
 *
 * The link's stream refused one of the linked node's operations of the collection, and the row
 * it named is held until a snapshot of the collection is published here; or a snapshot of it was
 * canceled before its publication. The asked node answers by cutting a snapshot of each of its
 * areas of the collection into the stream of the same link, exactly as it hands them over when
 * the link comes up - behind every write it streamed there before. Nothing is answered here
 * besides that snapshot: a node that owns nothing of the collection cuts none.
 *
 * Asked once per link and collection until a snapshot of the collection arrives on the link,
 * and never by a timer: the next refusal or cancellation is the next reason to ask.
 */
final class PeerRtSnapshotRequestDTO extends PeerDTO
{
    /** @var string Wire message type for the RT-snapshot-request frame */
    public const string MESSAGE_TYPE = 'peer_rt_snapshot_request';

    /** @var string Payload key: id of the node asking for the snapshot */
    public const string FIELD_NODE_ID = 'nodeId';

    /** @var string Payload key: RT collection the snapshot is asked of */
    public const string FIELD_COLLECTION_KEY = 'collectionKey';

    /**
     * @param string $nodeId Id of the node asking for the snapshot
     * @param string $collectionKey RT collection the snapshot is asked of
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly string $collectionKey,
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
     * Serializes the snapshot request to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_NODE_ID => $this->nodeId,
            self::FIELD_COLLECTION_KEY => $this->collectionKey,
        ];
    }

    /**
     * Restores a snapshot request from its wire array. Both fields are refused when absent: the
     * node id is the stream the snapshot is cut into, and the collection is all the request asks.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored snapshot request
     * @throws PeerTransportException When the node id or the collection key is missing
     */
    public static function fromArray(array $data): static
    {
        $nodeId = $data[self::FIELD_NODE_ID] ?? null;
        if (!is_string($nodeId) || $nodeId === '') {
            throw new PeerTransportException('Peer RT snapshot request is missing the node id');
        }

        $collectionKey = $data[self::FIELD_COLLECTION_KEY] ?? null;
        if (!is_string($collectionKey) || $collectionKey === '') {
            throw new PeerTransportException('Peer RT snapshot request is missing the collection key');
        }

        return new static(
            nodeId: $nodeId,
            collectionKey: $collectionKey,
        );
    }
}
