<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;

/**
 * Addressed frame handing one node's whole set of browser connections to a peer.
 *
 * The connection index is what lets a node answer a browser attached elsewhere, and a node
 * that has just linked knows nothing about the sockets the other one already holds. So the
 * first thing a link carries is the whole set, exactly as {@see PeerRtSnapshotDTO} carries a
 * whole RT collection: what arrives REPLACES everything the receiver held for this node — a
 * key the snapshot does not carry is a connection that is gone.
 *
 * Addressed rather than broadcast for the same reason: only the node on the other end of the
 * new link is behind, and the rest have been kept current by {@see PeerConnectionsDeltaDTO}.
 */
final class PeerConnectionsSnapshotDTO extends PeerDTO
{
    /** @var string Wire message type for the connection-snapshot frame */
    public const string MESSAGE_TYPE = 'peer_connections_snapshot';

    /** @var string Payload key: id of the node the connections belong to */
    public const string FIELD_NODE_ID = 'nodeId';

    /** @var string Payload key: every accept key that node holds right now */
    public const string FIELD_ACCEPT_KEYS = 'acceptKeys';

    /**
     * @param string $nodeId Id of the node the connections belong to
     * @param list<string> $acceptKeys Every accept key that node holds right now
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly array $acceptKeys,
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
     * Serializes the connection-snapshot frame to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_NODE_ID => $this->nodeId,
            self::FIELD_ACCEPT_KEYS => $this->acceptKeys,
        ];
    }

    /**
     * Restores a connection-snapshot frame from its wire array.
     *
     * An empty list is a legitimate snapshot rather than a missing field: it says the node
     * holds no browser connection at all, and the receiver is meant to end up holding nothing
     * for it either — which is precisely what a node whose last client just left announces.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored connection-snapshot frame
     * @throws PeerTransportException When the node id is missing or the accept keys are not a list of keys
     */
    public static function fromArray(array $data): static
    {
        $nodeId = $data[self::FIELD_NODE_ID] ?? null;
        if (!is_string($nodeId) || $nodeId === '') {
            throw new PeerTransportException('Peer connections snapshot is missing the node id');
        }

        return new static(
            nodeId: $nodeId,
            acceptKeys: self::readAcceptKeys($data, self::FIELD_ACCEPT_KEYS, 'snapshot'),
        );
    }
}
