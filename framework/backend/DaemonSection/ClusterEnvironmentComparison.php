<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Core\Exception\InvalidArgumentException;

/** Value-free comparison of the current cluster environment picture. */
final readonly class ClusterEnvironmentComparison
{
    /**
     * @param list<string> $nodes All node ids in picture order
     * @param list<string> $answering Nodes with an online environment report
     * @param list<ClusterEnvironmentKeyComparison> $diverged Keys whose type, label, or declaration differs
     * @param list<ClusterEnvironmentKeyComparison> $sourcesDiffer Equal values reached through different sources
     * @param list<string> $perNode Keys allowed to differ by node
     */
    public function __construct(
        public array $nodes,
        public array $answering,
        public array $diverged,
        public array $sourcesDiffer,
        public array $perNode,
    ) {
    }

    /**
     * @param list<ClusterDaemonNodeView> $nodes Current roster projection in node-id order
     * @return self Comparison of answering reports; silence remains unknown
     * @throws InvalidArgumentException When a generated cell contradicts its state
     */
    public static function of(array $nodes): self
    {
        $nodeIds = [];
        $answering = [];
        $answeringById = [];
        $fingerprintsByNode = [];
        $keys = [];
        foreach ($nodes as $node) {
            $nodeIds[] = $node->nodeId;
            $environment = $node->online ? $node->slot?->picture->environment : null;
            if ($environment === null) {
                continue;
            }
            $answering[] = $node->nodeId;
            $answeringById[$node->nodeId] = true;
            foreach ($environment->fingerprints as $fingerprint) {
                $keys[$fingerprint->key] = true;
                $fingerprintsByNode[$node->nodeId][$fingerprint->key] = $fingerprint;
            }
        }

        $diverged = [];
        $sourcesDiffer = [];
        $perNode = [];
        foreach (array_keys($keys) as $key) {
            $cells = [];
            $known = [];
            $undeclared = false;
            $keyIsPerNode = false;
            foreach ($nodes as $node) {
                if (!isset($answeringById[$node->nodeId])) {
                    $cells[] = new ClusterEnvironmentCell($node->nodeId, ClusterEnvironmentCellState::Unknown, null);
                    continue;
                }
                $fingerprint = $fingerprintsByNode[$node->nodeId][$key] ?? null;
                if ($fingerprint === null) {
                    $undeclared = true;
                    $cells[] = new ClusterEnvironmentCell($node->nodeId, ClusterEnvironmentCellState::Undeclared, null);
                    continue;
                }
                $known[] = $fingerprint;
                $keyIsPerNode = $keyIsPerNode || $fingerprint->perNode;
                $cells[] = new ClusterEnvironmentCell($node->nodeId, ClusterEnvironmentCellState::Known, $fingerprint);
            }
            if ($keyIsPerNode) {
                $perNode[] = $key;
                continue;
            }
            if (count($answering) < 2) {
                continue;
            }
            $comparison = new ClusterEnvironmentKeyComparison($key, $cells);
            $first = $known[0];
            foreach ($known as $fingerprint) {
                if ($fingerprint->type !== $first->type || $fingerprint->digest !== $first->digest) {
                    $undeclared = true;
                    break;
                }
            }
            if ($undeclared) {
                $diverged[] = $comparison;
                continue;
            }
            foreach ($known as $fingerprint) {
                if ($fingerprint->source !== $first->source) {
                    $sourcesDiffer[] = $comparison;
                    break;
                }
            }
        }

        return new self($nodeIds, $answering, $diverged, $sourcesDiffer, $perNode);
    }
}
