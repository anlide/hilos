<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;

/**
 * Placement frame the leader hands every node so it can tell where an agent runs.
 *
 * The mirror image of {@see PeerPlacementReportDTO}: that one travels node → leader and says
 * "here is what I host", this one travels leader → node and says "here is what everybody
 * hosts". It exists because placement is leader-owned soft state, which left every other node
 * answering "I do not know where that agent is" — and therefore delivering a signal for it
 * locally, into nothing.
 *
 * A full list rather than a delta, on the same grounds the roster is one: placements number in
 * the dozens, a full list is self-correcting after any missed frame, and there is no ordering
 * to get wrong. The wire groups agents BY NODE, which is what lets the entries stay ordinary
 * {@see PeerPlacedAgentEntry} objects meaning exactly what they mean in a report — the node is
 * one level up, as it is there too (where it is the sender).
 *
 * {@see $leaderNodeId} names the owner of this view, so a node can tell whose picture it is
 * holding and a stale one can be logged with the term it came from.
 */
final class PeerPlacementViewDTO extends PeerDTO
{
    /** @var string Wire message type for the placement-view frame */
    public const string MESSAGE_TYPE = 'peer_placement_view';

    /** @var string Payload key: id of the leader that owns this view */
    public const string FIELD_LEADER_NODE_ID = 'leaderNodeId';

    /** @var string Payload key: hosted agent entries, keyed by the node hosting them */
    public const string FIELD_AGENTS = 'agents';

    /**
     * @param string $leaderNodeId Id of the leader that owns this view
     * @param array<string|int, list<PeerPlacedAgentEntry>> $agents Agents each node hosts, by node id
     */
    public function __construct(
        public readonly string $leaderNodeId,
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
     * Serializes the placement view to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        $agents = [];
        foreach ($this->agents as $nodeId => $entries) {
            $agents[$nodeId] = array_map(
                static fn(PeerPlacedAgentEntry $entry): array => $entry->toArray(),
                $entries,
            );
        }

        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_LEADER_NODE_ID => $this->leaderNodeId,
            self::FIELD_AGENTS => $agents,
        ];
    }

    /**
     * Restores a placement view from its wire array.
     *
     * Strict where the report is lenient, and the difference is what each frame does to the
     * receiver: a report is folded into a view the leader arbitrates, so one unreadable entry
     * costs one agent, while this frame REPLACES a node's whole picture of the cluster. Read
     * loosely, a blank node id would index every agent under it as unreachable — so a
     * malformed view is refused whole and the receiver keeps the picture it had.
     *
     * An int node id is not malformed but the same id, since PHP has no string array key that
     * reads as a decimal integer: a cluster whose nodes are named "1", "2", "3" groups under
     * int keys here and gets them back as ints from {@see json_decode()}. Refusing those would
     * reject every view such a cluster ever sends, and reject it silently — which is exactly
     * the "a non-leader knows where nothing runs" defect this frame exists to end.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored view
     * @throws PeerTransportException When the leader id, a node id, or an entry is malformed
     */
    public static function fromArray(array $data): static
    {
        $leaderNodeId = $data[self::FIELD_LEADER_NODE_ID] ?? null;
        if (!is_string($leaderNodeId) || $leaderNodeId === '') {
            throw new PeerTransportException('Peer placement view is missing the leader node id');
        }

        $raw = $data[self::FIELD_AGENTS] ?? null;
        if (!is_array($raw)) {
            throw new PeerTransportException("Peer placement view is missing the 'agents' map");
        }

        $agents = [];
        foreach ($raw as $nodeId => $entries) {
            $nodeId = is_int($nodeId) ? (string)$nodeId : $nodeId;
            if (!is_string($nodeId) || $nodeId === '' || !is_array($entries)) {
                throw new PeerTransportException('Peer placement view carries a malformed node entry');
            }

            $agents[$nodeId] = [];
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    throw new PeerTransportException('Peer placement view carries a malformed agent entry');
                }

                $agents[$nodeId][] = PeerPlacedAgentEntry::fromArray($entry);
            }
        }

        return new static($leaderNodeId, $agents);
    }
}
