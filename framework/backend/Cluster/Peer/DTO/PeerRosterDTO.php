<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;

/**
 * Gossip frame carrying a node's full known membership.
 *
 * Sent right after the handshake so a freshly-connected peer is bootstrapped
 * with everything the sender knows; the receiver merges each entry.
 */
final class PeerRosterDTO extends PeerDTO
{
    /** @var string Wire message type for the roster frame */
    public const string MESSAGE_TYPE = 'peer_roster';

    /** @var string Payload key: the list of node entries */
    public const string FIELD_NODES = 'nodes';

    /**
     * @param list<PeerNodeEntry> $nodes Known node entries
     */
    public function __construct(
        public readonly array $nodes,
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
     * Serializes the roster to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_NODES => array_map(static fn(PeerNodeEntry $node): array => $node->toArray(), $this->nodes),
        ];
    }

    /**
     * Restores a roster from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored roster
     * @throws PeerTransportException When a node entry is malformed
     */
    public static function fromArray(array $data): static
    {
        $raw = $data[self::FIELD_NODES] ?? [];
        $nodes = [];
        if (is_array($raw)) {
            foreach ($raw as $entry) {
                if (is_array($entry)) {
                    $nodes[] = PeerNodeEntry::fromArray($entry);
                }
            }
        }

        return new static($nodes);
    }
}
