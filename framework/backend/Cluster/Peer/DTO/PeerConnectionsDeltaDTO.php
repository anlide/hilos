<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;

/**
 * Broadcast frame announcing which browser connections one node has gained and lost.
 *
 * The steady-state twin of {@see PeerConnectionsSnapshotDTO}: the snapshot bootstraps a fresh
 * link, this keeps every peer current afterwards. It goes to everyone because any node may be
 * the one an agent answers a browser from, and it carries both directions in one frame
 * because it is built from a diff of the announcing node's socket set — opening and closing
 * within the same tick is one fact, not two.
 *
 * A key can appear only in one of the two lists of a given frame; a key that opened and closed
 * between two announcements never travels at all, and never needed to.
 */
final class PeerConnectionsDeltaDTO extends PeerDTO
{
    /** @var string Wire message type for the connection-delta frame */
    public const string MESSAGE_TYPE = 'peer_connections_delta';

    /** @var string Payload key: id of the node the connections belong to */
    public const string FIELD_NODE_ID = 'nodeId';

    /** @var string Payload key: accept keys that node has gained since its last announcement */
    public const string FIELD_OPENED = 'opened';

    /** @var string Payload key: accept keys that node has lost since its last announcement */
    public const string FIELD_CLOSED = 'closed';

    /**
     * @param string $nodeId Id of the node the connections belong to
     * @param list<string> $opened Accept keys that node has gained since its last announcement
     * @param list<string> $closed Accept keys that node has lost since its last announcement
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly array $opened,
        public readonly array $closed,
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
     * Serializes the connection-delta frame to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_NODE_ID => $this->nodeId,
            self::FIELD_OPENED => $this->opened,
            self::FIELD_CLOSED => $this->closed,
        ];
    }

    /**
     * Restores a connection-delta frame from its wire array.
     *
     * Both lists are required even when one of them is empty: a delta with a field missing is
     * indistinguishable from one that says "nothing closed", and reading it as the latter is
     * how a stale key would be left pointing at a node that no longer has it.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored connection-delta frame
     * @throws PeerTransportException When the node id is missing or either list is not a list of accept keys
     */
    public static function fromArray(array $data): static
    {
        $nodeId = $data[self::FIELD_NODE_ID] ?? null;
        if (!is_string($nodeId) || $nodeId === '') {
            throw new PeerTransportException('Peer connections delta is missing the node id');
        }

        return new static(
            nodeId: $nodeId,
            opened: self::readAcceptKeys($data, self::FIELD_OPENED, 'delta'),
            closed: self::readAcceptKeys($data, self::FIELD_CLOSED, 'delta'),
        );
    }
}
