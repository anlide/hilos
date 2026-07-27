<?php

declare(strict_types=1);

namespace Demo\Cluster\Runtime\View\Context;

use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;

/**
 * ClusterRtContext - runtime context for the headless cluster demo.
 *
 * The cluster demo carries no pages or WebSocket, so this context exists solely to
 * mount the framework-owned {@see StateProtectedModeRuntime} singleton on every node.
 * That single item is the local writer seam the daemon truth source registers against
 * (see DaemonManager::registerProtectedModeTruthSource()): the leader writes it by its
 * own decision and followers write it in reaction to peer QUIESCE/LIFT frames, so the
 * row reaches each node's workers over RT sync.
 *
 * The item is mounted flat (no view representation), mirroring how the chat demo mounts
 * BackupRuntime: the writer-owner reads it through Hilos::$rt->getStateItem() and writes
 * its fields plus sync(); no page reads it through a view, so View/Actions are not needed.
 * The context has no state collections, which the base context permits.
 */
final class ClusterRtContext extends RtContext
{
    /**
     * Registers the protected mode runtime singleton.
     */
    public function configure(): void
    {
        $this->_stateItems[StateProtectedModeRuntime::RT_ITEM] = StateProtectedModeRuntime::create();
    }
}
