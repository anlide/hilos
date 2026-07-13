<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;

/**
 * Gossip frame announcing a single membership change to peers.
 *
 * Sent to connected peers when the sender's registry gains or changes a node
 * (or marks one offline); the receiver merges the entry and re-announces only
 * when it was a real change, so the gossip converges instead of echoing.
 */
final class PeerAnnounceDTO extends PeerDTO
{
    /** @var string Wire message type for the announce frame */
    public const string MESSAGE_TYPE = 'peer_announce';

    /** @var string Payload key: the announced node entry */
    public const string FIELD_NODE = 'node';

    /**
     * @param PeerNodeEntry $node Announced node entry
     */
    public function __construct(
        public readonly PeerNodeEntry $node,
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
     * Serializes the announcement to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_NODE => $this->node->toArray(),
        ];
    }

    /**
     * Restores an announcement from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored announcement
     * @throws PeerTransportException When the node entry is missing or malformed
     */
    public static function fromArray(array $data): static
    {
        $raw = $data[self::FIELD_NODE] ?? null;
        if (!is_array($raw)) {
            throw new PeerTransportException('Peer announce is missing the node entry');
        }

        return new static(PeerNodeEntry::fromArray($raw));
    }
}
