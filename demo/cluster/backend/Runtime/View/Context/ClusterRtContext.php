<?php

declare(strict_types=1);

namespace Demo\Cluster\Runtime\View\Context;

use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;

/**
 * ClusterRtContext - runtime context for the headless cluster demo.
 *
 * The demo carries no pages and no WebSocket, so what lives here is only what a node has to
 * hold. The framework mounts the {@see StateProtectedModeRuntime} singleton into
 * the project context before configure() ({@see RtContext::mountFeatureRuntime()}), and a
 * project whose createRuntime() returns null leaves Hilos::$rt === null - the row would have
 * nowhere to live, and this demo exists precisely to show the freeze reaching every node. That
 * mounted item is the local writer seam the daemon truth source registers against (see
 * DaemonManager::registerProtectedModeTruthSource()): the leader writes it by its own decision
 * and followers write it in reaction to peer QUIESCE/LIFT frames. Its view representation is
 * declared by the framework, so this context does not register it: writers and readers alike
 * reach the row as Hilos::$rt->hilosProtectedModeRuntime.
 */
final class ClusterRtContext extends RtContext
{
    /**
     * Registers nothing of the demo's own: the context exists for the freeze row.
     */
    public function configure(): void
    {
        // The probe collections - the fleet statuses and the probe notes - are the framework's,
        // and the framework mounts them (HIL-1211).
    }
}
