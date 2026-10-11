<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

/** Restart marks belong to their original host, even when a leader owns the restart. */
final class DaemonRestartingAgents
{
    /**
     * @param ClusterDaemonPicture $picture Whole cluster picture
     * @param string $nodeId Node hosting the instance when restart began
     * @return list<DaemonRestartingAgent> Unique marks in natural agent-id order
     */
    public static function forNode(ClusterDaemonPicture $picture, string $nodeId): array
    {
        $agents = [];
        foreach ($picture->nodes() as $node) {
            foreach ($node->slot?->picture->processes?->restartingAgents ?? [] as $agent) {
                if ($agent->nodeId === $nodeId) {
                    $agents[$agent->id] = $agent;
                }
            }
        }
        uksort($agents, strnatcmp(...));

        return array_values($agents);
    }
}
