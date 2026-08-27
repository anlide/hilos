<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer\DTO;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\TruthSource\RtClusterClaimRegistry;

/**
 * Claim frame a node sends the leader listing everything its agents own of the RT state.
 *
 * The reply to a {@see PeerRtClaimsQueryDTO}, and the frame a node also sends unasked whenever
 * what it owns changes: the leader folds it into its cluster-wide map of rights
 * ({@see RtClusterClaimRegistry}) and answers only when two nodes claim the same rows, so
 * silence means the claim stands. Mirrors {@see PeerPlacementReportDTO}'s role for placement.
 *
 * The report is the node's WHOLE ownership, never a delta, for the reason the roster and the
 * placement report are: a lost frame corrects itself with the next one instead of leaving the
 * leader holding a right that was dropped long ago.
 *
 * The node names itself in the payload rather than being read off the link, on the same terms
 * as the RT sync frames: what a node calls itself is what the rest of the mesh keys its map
 * by, and the leader folds its own report in through this same frame, where there is no link
 * to read.
 */
final class PeerRtClaimsDTO extends PeerDTO
{
    /** @var string Wire message type for the RT-claims frame */
    public const string MESSAGE_TYPE = 'peer_rt_claims';

    /** @var string Payload key: the node the claims belong to */
    public const string FIELD_NODE_ID = 'nodeId';

    /** @var string Payload key: the list of per-agent claim entries */
    public const string FIELD_CLAIMS = 'claims';

    /**
     * @param string $nodeId Node whose agents hold the claims
     * @param list<PeerRtClaimEntry> $claims What each agent of that node owns
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly array $claims,
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
     * Serializes the claims report to its wire array.
     *
     * @return array<string, mixed> Frame payload
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_NODE_ID => $this->nodeId,
            self::FIELD_CLAIMS => array_map(
                static fn(PeerRtClaimEntry $entry): array => $entry->toArray(),
                $this->claims,
            ),
        ];
    }

    /**
     * Restores a claims report from its wire array.
     *
     * A node owning nothing reports an empty list rather than staying silent, and that is the
     * frame that releases the rights it held a moment ago; so an absent list is a malformed
     * frame, while an empty one is a meaningful report.
     *
     * @param array<string, mixed> $data Frame payload
     * @return static Restored report
     * @throws PeerTransportException When the frame names no node or carries a malformed entry
     */
    public static function fromArray(array $data): static
    {
        $nodeIdValue = $data[self::FIELD_NODE_ID] ?? null;
        $nodeId = is_string($nodeIdValue) ? trim($nodeIdValue) : null;
        if ($nodeId === null || $nodeId === '') {
            throw new PeerTransportException('Peer RT claims frame is missing the node id');
        }

        $raw = $data[self::FIELD_CLAIMS] ?? null;
        if (!is_array($raw)) {
            throw new PeerTransportException("Peer RT claims frame from '{$nodeId}' is missing the claim list");
        }

        $claims = [];
        foreach ($raw as $entry) {
            if (is_array($entry)) {
                $claims[] = PeerRtClaimEntry::fromArray($entry);
            }
        }

        return new static($nodeId, $claims);
    }
}
