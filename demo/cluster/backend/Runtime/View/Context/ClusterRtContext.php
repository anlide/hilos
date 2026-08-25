<?php

declare(strict_types=1);

namespace Demo\Cluster\Runtime\View\Context;

use Demo\Cluster\Runtime\State\Collection\WorkerStatuses as StateWorkerStatuses;
use Demo\Cluster\Runtime\View\Actions\Collection\WorkerStatusesActions;
use Demo\Cluster\Runtime\View\Actions\Item\WorkerStatusActions;
use Demo\Cluster\Runtime\View\Collection\WorkerStatuses;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;

/**
 * ClusterRtContext - runtime context for the headless cluster demo.
 *
 * The demo carries no pages and no WebSocket, so what lives here is only what a node has to
 * hold. Two things do. The framework mounts the {@see StateProtectedModeRuntime} singleton into
 * the project context before configure() ({@see RtContext::mountFeatureRuntime()}), and a
 * project whose createRuntime() returns null leaves Hilos::$rt === null - the row would have
 * nowhere to live, and this demo exists precisely to show the freeze reaching every node. That
 * mounted item is the local writer seam the daemon truth source registers against (see
 * DaemonManager::registerProtectedModeTruthSource()): the leader writes it by its own decision
 * and followers write it in reaction to peer QUIESCE/LIFT frames. Its view representation is
 * declared by the framework, so this context does not register it: writers and readers alike
 * reach the row as Hilos::$rt->hilosProtectedModeRuntime.
 *
 * The worker statuses are this demo's own, and they are what makes cross-node RT observable at
 * all (HIL-589). Each fleet member owns ONE row of the collection, by its own index, so the
 * rows of the fleet are written on every node at once and replicated to every other - the
 * arrangement key-scoped ownership exists for, and the one an acceptance run can watch converge
 * after a link goes down and comes back.
 *
 * @property-read WorkerStatuses $workerStatuses Fleet worker statuses, one row per member
 */
final class ClusterRtContext extends RtContext
{
    public const string workerStatuses = 'workerStatuses';

    public const string workerStatus = 'workerStatus';

    /**
     * Registers the fleet worker statuses and their view representation.
     *
     * @throws StateCollectionNotFoundException When a represented collection key is not registered
     */
    public function configure(): void
    {
        $this->_stateCollections[self::workerStatuses] = StateWorkerStatuses::init();

        $this->setRepresent(
            self::workerStatuses,
            WorkerStatuses::class,
            WorkerStatusesActions::class,
            WorkerStatusActions::class,
        );
    }
}
