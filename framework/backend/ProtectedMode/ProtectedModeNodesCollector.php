<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Hilos\Cluster\Peer\DTO\PeerProtectedModeNodeReportDTO;

/** Pure projection of membership and the master's current-link transport inbox. */
final class ProtectedModeNodesCollector
{
    public const string REPORT_CURRENT = 'current';
    public const string REPORT_MISSING = 'missing';
    public const string REPORT_SILENT = 'silent';

    /**
     * Arrays here are infrastructure snapshots, not a second membership register.
     * Only the recognized leader's complete report in the current term describes a round.
     * A fresh report with no RT phase still leaves the node's phase fact missing.
     *
     * @param list<string> $nodeIds Existing membership, including offline and slave nodes
     * @param list<string> $linkedNodeIds Reachable nodes, including the local process
     * @param array<string, PeerProtectedModeNodeReportDTO> $reports Reports from current links plus local self
     * @param ?string $leaderNodeId Recognized current leader, null when unknown
     * @param ?int $term Current consensus term, null without inspection
     * @return list<array<string, string|bool|null>> Six-field RT projection ordered by node id
     */
    public static function project(array $nodeIds, array $linkedNodeIds, array $reports, ?string $leaderNodeId, ?int $term): array
    {
        $leader = $leaderNodeId !== null && $term !== null && in_array($leaderNodeId, $linkedNodeIds, true)
            ? ($reports[$leaderNodeId] ?? null)
            : null;
        $round = $leader !== null && $leader->term === $term && $leader->quiesceNodeIds !== null && $leader->pendingNodeIds !== null
            ? $leader
            : null;
        $nodeIds = array_values(array_unique([...$nodeIds, ...($round?->quiesceNodeIds ?? [])]));
        sort($nodeIds, SORT_STRING);

        $nodes = [];
        foreach ($nodeIds as $nodeId) {
            $linked = in_array($nodeId, $linkedNodeIds, true);
            $report = $linked ? ($reports[$nodeId] ?? null) : null;
            $nodes[] = [
                'nodeId' => $nodeId,
                'phase' => $report?->phase,
                'quiesced' => $round !== null && in_array($nodeId, $round->quiesceNodeIds, true)
                    ? !in_array($nodeId, $round->pendingNodeIds, true)
                    : null,
                'isLeader' => $term !== null && $leaderNodeId === $nodeId,
                'isInitiator' => $round !== null && $round->initiatorNodeId === $nodeId,
                'reportState' => !$linked ? self::REPORT_SILENT
                    : ($report?->phase === null ? self::REPORT_MISSING : self::REPORT_CURRENT),
            ];
        }

        return $nodes;
    }
}
