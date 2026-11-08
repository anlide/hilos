<?php

declare(strict_types=1);

namespace Hilos\ProtectedMode;

use Closure;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\Exception\ClusterDisabledException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Server\WorkerServer;
use WeakReference;

/** One concrete process-local fan-out for the protected row, switch and its pending roster. */
final class ProtectedModeSourceBinding
{
    /** @var ?WeakReference<WorkerServer> Optional diagnostic consumer */
    private ?WeakReference $workerServer = null;

    /** @var ?WeakReference<ProtectedModeWatchdog> Optional watchdog consumer */
    private ?WeakReference $watchdog = null;

    /** @var ?WeakReference<RtContext> Current source without retaining its graph */
    private ?WeakReference $source = null;

    /** @var ?WeakReference<ClusterContext> Current switch registration owner */
    private ?WeakReference $cluster = null;

    /** @var ?WeakReference<ProtectedModeSwitch> Current switch without retaining its transport */
    private ?WeakReference $switch = null;

    private ?Closure $wake = null;

    private ?Closure $switchWake = null;

    private ?int $mountRevision = null;

    private ?string $nodeId = null;

    private int $generation = 0;

    private int $revision = 0;

    /** @return int Observed protected source facts, including transitions before the row is applied */
    public function revision(): int
    {
        return $this->revision;
    }

    /** @param WorkerServer $server Current optional diagnostics owner */
    public function registerWorkerServer(WorkerServer $server): void
    {
        $this->workerServer = WeakReference::create($server);
        $server->noteProtectedModeDiagnosticsChanged();
    }

    /** @param WorkerServer $server Exact departing diagnostics owner */
    public function removeWorkerServer(WorkerServer $server): void
    {
        if ($this->workerServer?->get() === $server) {
            $this->workerServer = null;
            $this->detachIfUnused();
        }
    }

    /** @param ProtectedModeWatchdog $watchdog Current optional watchdog owner */
    public function registerWatchdog(ProtectedModeWatchdog $watchdog): void
    {
        $this->watchdog = WeakReference::create($watchdog);
        $watchdog->noteChanged();
    }

    /** @param ProtectedModeWatchdog $watchdog Exact departing watchdog owner */
    public function removeWatchdog(ProtectedModeWatchdog $watchdog): void
    {
        if ($this->watchdog?->get() === $watchdog) {
            $this->watchdog = null;
            $this->detachIfUnused();
        }
    }

    /**
     * Call before waiting and after guarded work that may replace public facade values.
     * Unchanged basis reads only identities/revision; no row scan or polling timer is needed.
     *
     * @throws EnvException When the local diagnostic identity cannot be read
     * @throws ClusterConfigurationException When the node configuration is invalid
     * @throws ClusterDisabledException When the enabled cluster loses its identity
     */
    public function reconcile(): void
    {
        if ($this->workerServer?->get() === null && $this->watchdog?->get() === null) {
            $this->detach();
            return;
        }
        $source = Hilos::$rt;
        $cluster = Hilos::$cluster;
        $switch = $cluster?->protectedMode();
        $revision = $source?->snapshotMountRevision();
        $nodeId = $source === null ? null : ($cluster?->localNodeId() ?? HilosClusterNode::STANDALONE_NODE_ID);
        if ($this->wake !== null
            && ($source === null ? $this->source === null : $this->source?->get() === $source)
            && ($cluster === null ? $this->cluster === null : $this->cluster?->get() === $cluster)
            && ($switch === null ? $this->switch === null : $this->switch?->get() === $switch)
            && $this->mountRevision === $revision && $this->nodeId === $nodeId) {
            return;
        }

        $this->detach();
        $this->source = $source === null ? null : WeakReference::create($source);
        $this->cluster = $cluster === null ? null : WeakReference::create($cluster);
        $this->switch = $switch === null ? null : WeakReference::create($switch);
        $this->mountRevision = $revision;
        $this->nodeId = $nodeId;
        $owner = WeakReference::create($this);
        $generation = $this->generation;
        $this->wake = static function () use ($owner, $generation): void {
            $binding = $owner->get();
            if ($binding !== null && $binding->generation === $generation
                && ($binding->source === null || $binding->source->get() !== null)
                && ($binding->cluster === null || $binding->cluster->get() !== null)
                && $binding->source?->get() === Hilos::$rt && $binding->cluster?->get() === Hilos::$cluster) {
                $binding->noteChanged();
            }
        };
        $source?->setProtectedModeWake($this->wake);
        $cluster?->setProtectedModeWake($this->wake);
        if ($switch instanceof ClusterProtectedMode) {
            $basis = WeakReference::create($switch);
            $this->switchWake = static function () use ($owner, $basis, $generation): void {
                $binding = $owner->get();
                if ($binding !== null && $binding->generation === $generation && $basis->get() !== null
                    && Hilos::$cluster?->protectedMode() === $basis->get()) {
                    $binding->wake?->__invoke();
                }
            };
            $switch->setChangeWake($this->switchWake);
        }
        $this->noteChanged();
    }

    /** Exact removal cannot erase a replacement binding's callback. */
    public function detach(): void
    {
        ++$this->generation;
        if ($this->wake !== null) {
            $this->source?->get()?->removeProtectedModeWake($this->wake);
            $this->cluster?->get()?->removeProtectedModeWake($this->wake);
            $switch = $this->switch?->get();
            if ($switch instanceof ClusterProtectedMode && $this->switchWake !== null) {
                $switch->removeChangeWake($this->switchWake);
            }
        }
        $this->wake = null;
        $this->switchWake = null;
        $this->source = null;
        $this->cluster = null;
        $this->switch = null;
        $this->mountRevision = null;
        $this->nodeId = null;
    }

    private function noteChanged(): void
    {
        ++$this->revision;
        $this->workerServer?->get()?->noteProtectedModeDiagnosticsChanged();
        $this->watchdog?->get()?->noteChanged();
        $switch = Hilos::$cluster?->protectedMode();
        if ($switch instanceof ClusterProtectedMode) {
            $switch->noteSlaveSyncChanged();
        }
    }

    private function detachIfUnused(): void
    {
        if ($this->workerServer?->get() === null && $this->watchdog?->get() === null) {
            $this->detach();
        }
    }
}
