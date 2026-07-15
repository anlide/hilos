<?php

declare(strict_types=1);

namespace Hilos\Cluster;

use Hilos\Core\Daemon\DaemonManager;

/**
 * Seam that receives cluster membership transitions as they happen.
 *
 * The peer transport owns the membership side effects (it merges peers into the
 * master registry and gossips the change), while the daemon wants to react to
 * nodes joining and leaving without polling the registry every tick. This
 * interface lets {@see ClusterContext} forward a transition to whoever registered
 * as the observer — the {@see DaemonManager} does so at start,
 * exposing project-overridable {@see DaemonManager::onNodeJoined}
 * / {@see DaemonManager::onNodeLeft} hooks — keeping the
 * registry a pure data structure with no observer wired inside it.
 */
interface MembershipObserver
{
    /**
     * @param ClusterNode $node Node that joined (or came back online)
     */
    public function onNodeJoined(ClusterNode $node): void;

    /**
     * @param ClusterNode $node Node that left (went offline)
     */
    public function onNodeLeft(ClusterNode $node): void;
}
