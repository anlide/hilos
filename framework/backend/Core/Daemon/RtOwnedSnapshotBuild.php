<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Closure;
use Generator;
use Hilos\Cluster\RtSyncMesh;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\RtSnapshotRead;
use WeakReference;

/** Builds whole replacement frames privately; no portion of a source is published as its entirety. */
final class RtOwnedSnapshotBuild
{
    /** @var WeakReference<RtSyncMesh> Original addressed transport */
    private readonly WeakReference $mesh;

    /**
     * @param RtSyncMesh $mesh Original transport
     * @param string $nodeId Original recipient
     * @param iterable<?RtSnapshotRead> $sources Whole, key and set scopes, with null charging one preparation/name step
     * @param Closure(): bool $route Exact original transport slot
     */
    public function __construct(
        RtSyncMesh $mesh,
        private readonly string $nodeId,
        private readonly iterable $sources,
        private readonly Closure $route,
    ) {
        $this->mesh = WeakReference::create($mesh);
    }

    /**
     * One source row or scope key is visited per step. Each visit costs its input's fields/bytes;
     * the final unchanged DTO still packs the whole source's fields and bytes atomically.
     *
     * @return Generator<int, void> One source transition, original row or complete snapshot send per step
     */
    public function delivery(): Generator
    {
        yield;
        foreach ($this->sources as $source) {
            if ($source === null) {
                yield;
                continue;
            }
            if (!$source->isCurrent() || $this->mesh->get() === null || !($this->route)()) {
                yield;
                continue;
            }
            $rows = [];
            $keys = $source->scopeKeys === null ? null : [];
            $sets = $source->scopeSetKeys === null ? null : [];
            yield;
            foreach ($source->scopeKeys ?? [] as $stateId) {
                $keys[$stateId] = true;
                yield;
            }
            foreach ($source->scopeSetKeys ?? [] as $setKey) {
                $sets[$setKey] = true;
                yield;
            }
            if (!$source->isCurrent()) {
                yield;
                continue;
            }
            foreach ($source->rows as $stateId => $row) {
                if ($this->mesh->get() === null || !($this->route)()) {
                    return;
                }
                if ($row !== null) {
                    $setKey = $sets === null ? null : RtSnapshot::setKeyOfRow($source->collectionKey, $row);
                    if (($keys === null || isset($keys[$stateId]))
                        && ($sets === null || ($setKey !== null && isset($sets[$setKey])))) {
                        $rows[$stateId] = $row;
                    }
                }
                yield;
            }
            if ($source->isCurrent() && ($this->route)()) {
                $this->mesh->get()?->sendRtSnapshotToNode($this->nodeId, $source->collectionKey, $rows,
                    $source->scopeKeys ?? [], $source->scopeSetKeys ?? []);
            }
            yield;
        }
    }
}
