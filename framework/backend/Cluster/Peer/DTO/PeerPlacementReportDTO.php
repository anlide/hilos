<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Placement\PlacementSnapshot;

/**
 * Placement frame a node sends the leader listing every agent it currently hosts.
 *
 * The reply to a {@see PeerPlacementQueryDTO}: on a leadership change the fresh leader
 * broadcasts a query and each node answers with this frame, letting the leader rebuild
 * its placement view from the live mesh rather than from persisted state. A node also
 * sends it unprompted on every new link, which is the rejoin path. Mirrors
 * {@see PeerRosterDTO}'s role for membership.
 *
 * The frame is a COMPLETE snapshot of the agents the sending node hosts at the moment it
 * is sent, and an empty list is a statement rather than a lack of one: an agent the frame
 * does NOT name is an agent the sender does NOT host, and the leader re-places whatever it
 * still tracked there (HIL-719). A future sender that reports only a part of what it hosts
 * would therefore have the rest taken away from it, silently — the whole set or nothing.
 * The required placementSnapshot key separately carries the last confirmed complete registry,
 * or null if none is known. Its records are never a substitute for what the sender actually hosts;
 * an authoritative empty snapshot is distinct from null. Donor import is not implemented yet.
 */
final class PeerPlacementReportDTO extends PeerDTO
{
    /** @var string Wire message type for the placement-report frame */
    public const string MESSAGE_TYPE = 'peer_placement_report';

    /** @var string Payload key: the list of hosted agent entries */
    public const string FIELD_AGENTS = 'agents';

    /** @var string Payload key: last complete registry received, or null when none was received */
    public const string FIELD_PLACEMENT_SNAPSHOT = 'placementSnapshot';

    /**
     * @param list<PeerPlacedAgentEntry> $agents Agents this node currently hosts
     * @param ?PlacementSnapshot $placementSnapshot Retained complete registry, independent of the hosted set
     */
    public function __construct(
        public readonly array $agents,
        public readonly ?PlacementSnapshot $placementSnapshot = null,
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
            self::FIELD_PLACEMENT_SNAPSHOT => $this->placementSnapshot?->toArray(),
        ];
    }

    /**
     * Restores a placement report from its wire array.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored report
     * @throws PeerTransportException When a hosted entry or snapshot is malformed, or the snapshot key is absent
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

        if (!array_key_exists(self::FIELD_PLACEMENT_SNAPSHOT, $data)) {
            throw new PeerTransportException('Peer placement report is missing the placement snapshot key');
        }
        $rawSnapshot = $data[self::FIELD_PLACEMENT_SNAPSHOT];
        if ($rawSnapshot !== null && !is_array($rawSnapshot)) {
            throw new PeerTransportException('Peer placement report carries a malformed placement snapshot');
        }

        return new static($agents, $rawSnapshot === null ? null : PlacementSnapshot::fromArray($rawSnapshot));
    }
}
