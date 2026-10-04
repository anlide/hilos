<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;

/**
 * Asks every other master to close sibling tabs of a rotated session (HIL-1306).
 *
 * Slaves hold no browser sockets. Each receiving master closes only the keys it holds,
 * silently skips the others, and does not forward the frame. The sender broadcasts instead
 * of addressing the connection index because a newly opened tab may not be indexed yet.
 */
final class PeerConnectionDropDTO extends PeerDTO
{
    /** @var string Wire message type for a rotated session's sibling drops */
    public const string MESSAGE_TYPE = 'peer_connection_drop';

    /** @var string Payload key: node that spent the rotation ticket */
    public const string FIELD_ORIGIN_NODE_ID = 'originNodeId';

    /** @var string Payload key: sibling connection keys to close */
    public const string FIELD_ACCEPT_KEYS = 'acceptKeys';

    /**
     * @param string $originNodeId Id of the node that spent the rotation ticket
     * @param list<string> $acceptKeys Sibling connection keys to close
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
     * Serializes the connection drop frame to its wire array.
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
     * Restores a connection drop frame from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored frame
     * @throws PeerTransportException When the origin or nonempty accept-key list is malformed
     */
    public static function fromArray(array $data): static
    {
        $originNodeId = $data[self::FIELD_ORIGIN_NODE_ID] ?? null;
        if (!is_string($originNodeId) || $originNodeId === '') {
            throw new PeerTransportException('Peer connection drop is missing the origin node id');
        }

        $acceptKeys = $data[self::FIELD_ACCEPT_KEYS] ?? null;
        if (!is_array($acceptKeys) || !array_is_list($acceptKeys) || $acceptKeys === []) {
            throw new PeerTransportException('Peer connection drop requires a nonempty accept-key list');
        }

        return new static(
            originNodeId: $originNodeId,
            acceptKeys: self::readAcceptKeys($data, self::FIELD_ACCEPT_KEYS, 'drop'),
        );
    }
}
