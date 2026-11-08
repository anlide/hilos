<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Closure;
use Generator;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\RtSyncMesh;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\RtSnapshotRead;
use Hilos\TruthSource\RtSourceReports;
use Hilos\TruthSource\RtReplicaOriginMap;
use WeakReference;

/** One process-private query answer; only whole origin groups reach the unchanged peer send door. */
final class RtReplicaOfferBuild
{
    /** @var WeakReference<RtSyncMesh> Original mesh, never extended by an unfinished answer */
    private readonly WeakReference $mesh;

    /** @var WeakReference<RtReplicaOriginMap> Canonical origin ledger with exact admission generations */
    private readonly WeakReference $origins;

    private readonly int $originCutoff;

    /** @var Closure(): bool Original peer transport, or the standalone mesh adapter */
    private readonly Closure $route;

    /**
     * The source iterator creates one reader per visit, after the immutable claim basis is folded.
     * No SQL or shared runtime state: all progress belongs to this master's existing effect FIFO.
     *
     * @param RtSyncMesh $mesh Original transport
     * @param string $nodeId Original asker
     * @param RtSourceReports $claims Immutable claim values at admission
     * @param RtReplicaOriginMap $origins Canonical row origins
     * @param iterable<?RtSnapshotRead> $sources Source visits in request order, including duplicates and excluded names
     * @param ?Closure(): bool $route Route captured when the query entered the FIFO, absent for immediate capture
     */
    public function __construct(
        RtSyncMesh $mesh,
        private readonly string $nodeId,
        private readonly RtSourceReports $claims,
        RtReplicaOriginMap $origins,
        private readonly iterable $sources,
        ?Closure $route = null,
    ) {
        $this->mesh = WeakReference::create($mesh);
        $this->origins = WeakReference::create($origins);
        $this->originCutoff = $origins->admissionCutoff();
        $this->route = $route ?? ($mesh instanceof PeerServer ? $mesh->nodeRouteGuard($nodeId) : static fn(): bool => true);
    }

    /**
     * Each row visit serializes that row once and classifies the very same payload. Insertion
     * positions and origin admission are separate: an old position moving here is not original.
     * Packing one complete origin frame still costs all its bytes; no 8MiB source cap is assumed.
     *
     * @return Generator<int, void> One source transition, original row or whole origin send per step
     */
    public function delivery(): Generator
    {
        yield;
        $plan = $this->claims->plan();
        yield from $plan->build();
        foreach ($this->sources as $source) {
            if ($this->mesh->get() === null || !($this->route)()) {
                return;
            }
            if ($source === null) {
                yield;
                continue;
            }
            if (!$source->isCurrent()) {
                yield;
                continue;
            }
            $ownRows = [];
            $replicaRows = [];
            $ownSets = $plan->setClaims[$source->collectionKey] ?? [];
            yield;
            foreach ($source->rows as $stateId => $row) {
                if ($this->mesh->get() === null || !($this->route)()) {
                    return;
                }
                if ($row !== null) {
                    $setKey = RtSnapshot::setKeyOfRow($source->collectionKey, $row);
                    if (isset($plan->wholeClaims[$source->collectionKey]) || isset($plan->rowClaims[$source->collectionKey][$stateId])
                        || ($setKey !== null && isset($ownSets[$setKey]))) {
                        $ownRows[$stateId] = $row;
                    } else {
                        $origin = $this->origins->get()?->nodeOfRow($source->collectionKey, $stateId, $this->originCutoff);
                        if ($origin !== null) {
                            $replicaRows[$origin][$stateId] = $row;
                        }
                    }
                }
                yield;
            }
            if (!$source->isCurrent()) {
                yield;
                continue;
            }
            if ($ownRows !== [] && ($this->route)()) {
                $this->mesh->get()?->sendRtReplicaOfferToNode($this->nodeId, null, $source->collectionKey, $ownRows);
            }
            yield;
            foreach ($replicaRows as $origin => $rows) {
                if (!($this->route)()) {
                    return;
                }
                // PHP turns digit-like origin keys into integers; the wire still names a string.
                $this->mesh->get()?->sendRtReplicaOfferToNode($this->nodeId, (string)$origin, $source->collectionKey, $rows);
                yield;
            }
        }
    }
}
