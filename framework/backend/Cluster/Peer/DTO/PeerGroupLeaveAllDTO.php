<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;

/**
 * Asks every other node to drop every group membership of connections whose person changed (HIL-1284).
 *
 * A membership is written on the master of the node whose agent admitted the join, not the node
 * holding the socket, so the sender cannot tell which nodes hold one and broadcasts to all of
 * them - slaves included. Each receiving node clears its own registry and every one of its
 * workers' mirrors, silently skips keys it does not know, and does not forward the frame.
 */
final class PeerGroupLeaveAllDTO extends PeerDTO
{
    /** @var string Wire message type for a cross-node group leave-all */
    public const string MESSAGE_TYPE = 'peer_group_leave_all';

    /** @var string Payload key: node whose worker announced the change of person */
    public const string FIELD_ORIGIN_NODE_ID = 'originNodeId';

    /** @var string Payload key: connections leaving every group */
    public const string FIELD_ACCEPT_KEYS = 'acceptKeys';

    /**
     * @param string $originNodeId Id of the announcing node
     * @param list<string> $acceptKeys Connections leaving every group
     */
    public function __construct(
        public readonly string $originNodeId,
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
     * Serializes the leave-all frame to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_ORIGIN_NODE_ID => $this->originNodeId,
            self::FIELD_ACCEPT_KEYS => $this->acceptKeys,
        ];
    }

    /**
     * Restores a leave-all frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When the origin or nonempty accept-key list is malformed
     */
    public static function fromArray(array $data): static
    {
        $originNodeId = $data[self::FIELD_ORIGIN_NODE_ID] ?? null;
        if (!is_string($originNodeId) || $originNodeId === '') {
            throw new PeerTransportException('Peer group leave-all is missing the origin node id');
        }

        $acceptKeys = $data[self::FIELD_ACCEPT_KEYS] ?? null;
        if (!is_array($acceptKeys) || !array_is_list($acceptKeys) || $acceptKeys === []) {
            throw new PeerTransportException('Peer group leave-all requires a nonempty accept-key list');
        }

        return new static(
            originNodeId: $originNodeId,
            acceptKeys: self::readAcceptKeys($data, self::FIELD_ACCEPT_KEYS, 'group leave-all'),
        );
    }
}
