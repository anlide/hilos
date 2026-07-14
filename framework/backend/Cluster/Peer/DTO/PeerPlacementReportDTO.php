<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

/**
 * Placement frame a node sends the leader listing every agent it currently hosts.
 *
 * The reply to a {@see PeerPlacementQueryDTO}: on a leadership change the fresh leader
 * broadcasts a query and each node answers with this frame, letting the leader rebuild
 * its placement view from the live mesh rather than from persisted state. Mirrors
 * {@see PeerRosterDTO}'s role for membership.
 */
final class PeerPlacementReportDTO extends PeerDTO
{
    /** @var string Wire message type for the placement-report frame */
    public const string MESSAGE_TYPE = 'peer_placement_report';

    /** @var string Payload key: the list of hosted agent entries */
    public const string FIELD_AGENTS = 'agents';

    /**
     * @param list<PeerPlacedAgentEntry> $agents Agents this node currently hosts
     */
    public function __construct(
        public readonly array $agents,
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
     * Serializes the placement report to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_AGENTS => array_map(
                static fn(PeerPlacedAgentEntry $entry): array => $entry->toArray(),
                $this->agents,
            ),
        ];
    }

    /**
     * Restores a placement report from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored report
     * @throws \Hilos\Cluster\Exception\PeerTransportException When a hosted agent entry is malformed
     */
    public static function fromArray(array $data): static
    {
        $raw = $data[self::FIELD_AGENTS] ?? [];
        $agents = [];
        if (is_array($raw)) {
            foreach ($raw as $entry) {
                if (is_array($entry)) {
                    $agents[] = PeerPlacedAgentEntry::fromArray($entry);
                }
            }
        }

        return new static($agents);
    }
}
