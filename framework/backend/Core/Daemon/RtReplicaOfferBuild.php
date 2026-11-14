<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Closure;
use Generator;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\RtSyncMesh;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtSnapshotRead;
use Hilos\Runtime\Snapshot\RtWorkLane;
use Hilos\Runtime\Snapshot\RtWorkScheduler;
use Hilos\TruthSource\RtSourceReports;
use Hilos\TruthSource\RtReplicaOriginMap;
use LogicException;
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
     * @param RtWorkScheduler $work RT pass of the master FIFO portion this build's steps run in
     * @param ?Closure(): bool $route Route captured when the query entered the FIFO, absent for immediate capture
     */
    public function __construct(
        RtSyncMesh $mesh,
        private readonly string $nodeId,
        private readonly RtSourceReports $claims,
        RtReplicaOriginMap $origins,
        private readonly iterable $sources,
        private readonly RtWorkScheduler $work,
        ?Closure $route = null,
    ) {
        $this->mesh = WeakReference::create($mesh);
        $this->origins = WeakReference::create($origins);
        $this->originCutoff = $origins->admissionCutoff();
        $this->route = $route ?? ($mesh instanceof PeerServer ? $mesh->nodeRouteGuard($nodeId) : static fn(): bool => true);
    }

    /**
     * Each step reads what the snapshot share of the current RT pass allows of a source's cut - a
     * spent share makes no RT progress until the master opens the next pass - and classifies
     * each row it read by the claims and origins captured up front.
     * Only whole origin groups reach the unchanged peer send door, and only for a source that still
     * answers as it did when cut. The frame is unchanged: the rows are materialized into it as they
     * arrive, which still costs each row's fields and bytes; no 8MiB source cap is assumed.
     *
     * @return Generator<int, void> One source transition, portion or whole origin send per step
     * @throws InvalidArgumentException When the pass allowance refuses a request
     * @throws InvalidFormatException When a stored row identity is malformed
     * @throws LogicException When a source's version pages closed under the read
     */
    public function delivery(): Generator
    {
        yield;
        $plan = $this->claims->plan();
        yield from $plan->build();
        foreach ($this->sources as $source) {
            if ($this->mesh->get() === null || !($this->route)()) {
                $source?->export->cancel();

                return;
            }
            if ($source === null) {
                yield;
                continue;
            }
            if (!$source->isCurrent()) {
                $source->export->cancel();
                yield;
                continue;
            }
            $ownRows = [];
            $replicaRows = [];
            $ownSets = $plan->setClaims[$source->collectionKey] ?? [];
            yield;
            while (!$source->export->isFinished()) {
                if ($this->mesh->get() === null || !($this->route)()) {
                    $source->export->cancel();

                    return;
                }
                $portion = $source->export->advance($this->work->budget(RtWorkLane::Snapshot));
                foreach ($portion?->ids ?? [] as $position => $stateId) {
                    $row = RtRowValues::nativeMap($portion->values[$position]);
                    $setKey = RtSnapshot::setKeyOfRow($source->collectionKey, $row);
                    if (isset($plan->wholeClaims[$source->collectionKey])
                        || isset($plan->rowClaims[$source->collectionKey][$stateId])
                        || ($setKey !== null && isset($ownSets[$setKey]))) {
                        $ownRows[$stateId] = $row;
                    } else {
                        $origin = $this->origins->get()?->nodeOfRow($source->collectionKey, $stateId, $this->originCutoff);
                        if ($origin !== null) {
                            $replicaRows[$origin][$stateId] = $row;
                        }
                    }
                }
                unset($portion, $row);
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
