<?php

declare(strict_types=1);

namespace Demo\Cluster\Runtime\View\Context;

use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;

/**
 * ClusterRtContext - runtime context for the headless cluster demo.
 *
 * The cluster demo carries no pages, no WebSocket and no runtime state of its own, so
 * this context registers nothing. It exists because the framework mounts the
 * {@see StateProtectedModeRuntime} singleton into the project context after configure()
 * ({@see RtContext::mountFrameworkState()}), and a project whose createRuntime() returns
 * null leaves Hilos::$rt === null - the row would have nowhere to live, and this demo
 * exists precisely to show the freeze reaching every node.
 *
 * That mounted item is the local writer seam the daemon truth source registers against
 * (see DaemonManager::registerProtectedModeTruthSource()): the leader writes it by its
 * own decision and followers write it in reaction to peer QUIESCE/LIFT frames, so the
 * row reaches each node's workers over RT sync. Its view representation is declared by
 * the framework too, so this demo still registers nothing: writers and readers alike
 * reach the row as Hilos::$rt->hilosProtectedModeRuntime.
 */
final class ClusterRtContext extends RtContext
{
    /**
     * Registers no project runtime state: this demo owns none.
     */
    public function configure(): void
    {
    }
}
