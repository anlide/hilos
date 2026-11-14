<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Closure;
use Generator;
use Hilos\Cluster\RtSyncMesh;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtSnapshotRead;
use Hilos\Runtime\Snapshot\RtWorkLane;
use Hilos\Runtime\Snapshot\RtWorkScheduler;
use LogicException;
use WeakReference;

/** Builds whole replacement frames privately; no portion of a source is published as its entirety. */
final class RtOwnedSnapshotBuild
{
    /** @var WeakReference<RtSyncMesh> Original addressed transport */
    private readonly WeakReference $mesh;


    /**
     * @param RtSyncMesh $mesh Original transport
     * @param string $nodeId Original recipient
     * @param iterable<?RtSnapshotRead> $sources Whole, row and set scopes, with null charging one preparation/name step
     * @param Closure(): bool $route Exact original transport slot
     * @param RtWorkScheduler $work RT pass of the master FIFO portion this build's steps run in
     */
    public function __construct(
        RtSyncMesh $mesh,
        private readonly string $nodeId,
        private readonly iterable $sources,
        private readonly Closure $route,
        private readonly RtWorkScheduler $work,
    ) {
        $this->mesh = WeakReference::create($mesh);
    }

    /**
     * Each step reads what the snapshot share of the current RT pass allows of a source's cut and
     * yields the step back; once the share is spent, steps make no RT progress until the master
     * opens the next pass, so no second scheduler stands beside the FIFO. A source's whole frame goes out
     * only once its read finished and the source still answers as it did when cut; an explicit
     * empty scope removes nothing, so it sends nothing, because the frame spells "every row" as
     * two empty lists. The frame is unchanged: the rows are materialized into it as they arrive,
     * which still costs each row's fields and bytes; a bounded wire form is not this frame's.
     *
     * @return Generator<int, void> One source transition, portion or complete snapshot send per step
     * @throws InvalidArgumentException When the pass allowance refuses a request
     * @throws InvalidFormatException When a stored row identity is malformed
     * @throws LogicException When a source's version pages closed under the read
     */
    public function delivery(): Generator
    {
        yield;
        foreach ($this->sources as $source) {
            if ($source === null) {
                yield;
                continue;
            }
            if (!$source->isCurrent() || $source->scope->isEmpty() || $this->mesh->get() === null || !($this->route)()) {
                $source->export->cancel();
                yield;
                continue;
            }
            $rows = [];
            while (!$source->export->isFinished()) {
                if ($this->mesh->get() === null || !($this->route)()) {
                    $source->export->cancel();

                    return;
                }
                $portion = $source->export->advance($this->work->budget(RtWorkLane::Snapshot));
                foreach ($portion?->ids ?? [] as $position => $stateId) {
                    $rows[$stateId] = RtRowValues::nativeMap($portion->values[$position]);
                }
                unset($portion);
                yield;
            }
            if ($source->isCurrent() && ($this->route)()) {
                $this->mesh->get()?->sendRtSnapshotToNode($this->nodeId, $source->collectionKey, $rows,
                    $source->scope->rowKeys() ?? [], $source->scope->setKeys() ?? []);
            }
            yield;
        }
    }
}
