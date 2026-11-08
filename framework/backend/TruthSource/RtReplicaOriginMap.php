<?php

declare(strict_types=1);

namespace Hilos\TruthSource;

use Hilos\Cluster\RtSyncSink;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Runtime\RtStaleness;
use WeakReference;

/**
 * Which node each replicated RT row came from, as far as the daemon master knows.
 *
 * The mirror image of {@see RtNodeSourceMap}: that one answers "what do WE own", this one
 * answers "what arrived FROM WHOM". They are kept apart because they are filled from opposite
 * directions — ownership from the workers' reports about their own agents, origin from the
 * frames the peer transport delivers — and asked in opposite situations.
 *
 * What it exists for is a question a node cannot otherwise answer: when a link drops, which of
 * the rows this node holds have just stopped being kept up to date. A replica is written into
 * the same collection as everything else, so after the write it is indistinguishable from a row
 * this node produced itself; only the frame knew where it came from, and the frame is gone by
 * the time the link closes. Written down here, the answer survives it ({@see RtStaleness}).
 *
 * Keyed by row and not by collection, because with ownership by keys (HIL-589) one collection
 * has several remote owners at once: a fleet member writes the rows it named and its neighbours
 * write theirs. Losing one of them must freeze its rows and leave the others alone.
 *
 * Rows written locally are simply absent, and that is the whole of what "fresh" means here: this
 * node holds them itself, so no link can stop them being current.
 */
final class RtReplicaOriginMap
{
    /** @var array<string, array<string, string>> [collectionKey => [stateId => originNodeId]] */
    private array $byCollection = [];

    /** @var array<string, array<string, int>> Exact origin admission of each current row */
    private array $rowAdmissions = [];

    private int $admissionSequence = 0;

    /** @var array<string, int> Original outer collection positions, retired when the last row leaves */
    private array $collectionPositions = [];

    /** @var array<string, array<string, int>> Original row positions, retained when the row changes origin */
    private array $rowPositions = [];

    /** @var array<string, array<int, string>> Only this node's collections, keyed by original outer position */
    private array $collectionsByNode = [];

    /** @var array<string, array<string, array<int, string>>> Only this node's rows, keyed by original row position */
    private array $rowsByNode = [];

    /** @var array<string, true> Origins that received an older collection position after a newer one */
    private array $collectionsNeedingSort = [];

    /** @var array<string, array<string, true>> Origins that received an older row position after a newer one */
    private array $rowsNeedingSort = [];

    private int $collectionSequence = 0;

    private int $rowSequence = 0;

    /** @var array<string, WeakReference<RtReplicaConfirmation>> Only retained receipts keep these ordering tokens alive */
    private array $confirmations = [];

    /**
     * @param string $nodeId Origin confirming rows now
     * @param RtNodeSourceMap $sources Current ownership source
     * @return RtReplicaConfirmation Shared receipt interval, ended by the next loss of this origin
     */
    public function captureConfirmation(string $nodeId, RtNodeSourceMap $sources): RtReplicaConfirmation
    {
        $confirmation = ($this->confirmations[$nodeId] ?? null)?->get();
        if ($confirmation === null || !$confirmation->isCurrent($this, $sources)) {
            $confirmation = new RtReplicaConfirmation($this, $sources);
            $this->confirmations[$nodeId] = WeakReference::create($confirmation);
        }
        return $confirmation;
    }

    /**
     * @param string $nodeId Origin whose last link closed
     * @param float $at Receiver's loss moment, also applicable to rows not yet applied
     */
    public function loseConfirmations(string $nodeId, float $at): void
    {
        $confirmation = ($this->confirmations[$nodeId] ?? null)?->get();
        if ($confirmation !== null) {
            $confirmation->lostAt = $at;
        }
        unset($this->confirmations[$nodeId]);
    }

    /**
     * Records which node the named rows of a collection arrived from.
     *
     * Additive per row rather than replacing the collection: the other rows of it may belong to
     * another node entirely, and a frame speaks only for what it carries. A row arriving from a
     * different node than last time simply moves — the newest frame is the one that says who
     * keeps it up to date now.
     *
     * @param string $originNodeId Node the rows were written on
     * @param string $collectionKey RT collection they belong to
     * @param list<string> $stateIds Rows that arrived
     */
    public function note(string $originNodeId, string $collectionKey, array $stateIds): void
    {
        foreach ($stateIds as $stateId) {
            $previous = $this->byCollection[$collectionKey][$stateId] ?? null;
            if ($previous === $originNodeId) {
                continue;
            }
            if (!isset($this->collectionPositions[$collectionKey])) {
                $position = ++$this->collectionSequence;
                $this->collectionPositions[$collectionKey] = $position;
            }
            if ($previous === null) {
                $position = ++$this->rowSequence;
                $this->rowPositions[$collectionKey][$stateId] = $position;
            } else {
                $position = $this->rowPositions[$collectionKey][$stateId];
                $this->removeNodeRow($previous, $collectionKey, $position);
            }
            $this->byCollection[$collectionKey][$stateId] = $originNodeId;
            $this->rowAdmissions[$collectionKey][$stateId] = ++$this->admissionSequence;
            if (!isset($this->rowsByNode[$originNodeId][$collectionKey])) {
                $collectionPosition = $this->collectionPositions[$collectionKey];
                $last = array_key_last($this->collectionsByNode[$originNodeId] ?? []);
                if ($last !== null && $collectionPosition < $last) {
                    $this->collectionsNeedingSort[$originNodeId] = true;
                }
                $this->collectionsByNode[$originNodeId][$collectionPosition] = $collectionKey;
            }
            $last = array_key_last($this->rowsByNode[$originNodeId][$collectionKey] ?? []);
            if ($last !== null && $position < $last) {
                $this->rowsNeedingSort[$originNodeId][$collectionKey] = true;
            }
            $this->rowsByNode[$originNodeId][$collectionKey][$position] = $stateId;
        }
    }

    /**
     * Drops what was known about one row, because it no longer exists here.
     *
     * @param string $collectionKey RT collection the row belonged to
     * @param string $stateId Row that is gone
     */
    public function forget(string $collectionKey, string $stateId): void
    {
        $nodeId = $this->byCollection[$collectionKey][$stateId] ?? null;
        if ($nodeId === null) {
            return;
        }
        $position = $this->rowPositions[$collectionKey][$stateId];
        $this->removeNodeRow($nodeId, $collectionKey, $position);
        unset($this->byCollection[$collectionKey][$stateId], $this->rowPositions[$collectionKey][$stateId],
            $this->rowAdmissions[$collectionKey][$stateId]);
        if ($this->byCollection[$collectionKey] === []) {
            unset($this->byCollection[$collectionKey], $this->rowPositions[$collectionKey],
                $this->collectionPositions[$collectionKey], $this->rowAdmissions[$collectionKey]);
        }
    }

    /**
     * Every row this node holds a replica of that one node wrote.
     *
     * The question asked the moment a link to that node closes: those rows are the ones whose
     * source has just become unreachable ({@see DaemonManager::noteNodeUnreachable()}), and the
     * transport knows only the node id ({@see RtSyncSink}).
     * Only the requested origin's index is visited. Original collection and row positions are
     * sorted once after an older position moves here; later reads reuse that order.
     *
     * @param string $nodeId Node to ask about
     * @return array<string, list<string>> Rows of that node, keyed by RT collection; collections
     *     it wrote nothing in are absent
     */
    public function rowsOfNode(string $nodeId): array
    {
        $rowsByCollection = [];
        if (!isset($this->collectionsByNode[$nodeId])) {
            return $rowsByCollection;
        }
        if (isset($this->collectionsNeedingSort[$nodeId])) {
            ksort($this->collectionsByNode[$nodeId]);
            unset($this->collectionsNeedingSort[$nodeId]);
        }
        foreach ($this->collectionsByNode[$nodeId] as $collectionKey) {
            if (isset($this->rowsNeedingSort[$nodeId][$collectionKey])) {
                ksort($this->rowsByNode[$nodeId][$collectionKey]);
                unset($this->rowsNeedingSort[$nodeId][$collectionKey]);
                if ($this->rowsNeedingSort[$nodeId] === []) {
                    unset($this->rowsNeedingSort[$nodeId]);
                }
            }
            $rowsByCollection[$collectionKey] = array_values($this->rowsByNode[$nodeId][$collectionKey]);
        }

        return $rowsByCollection;
    }

    /**
     * Which node one row came from.
     *
     * @param string $collectionKey RT collection to look in
     * @param string $stateId Row to ask about
     * @param ?int $admittedThrough Finite original origin cohort; absent for the current origin
     * @return ?string Current original origin, absent for local rows or a later origin incarnation
     */
    public function nodeOfRow(string $collectionKey, string $stateId, ?int $admittedThrough = null): ?string
    {
        $admission = $this->rowAdmissions[$collectionKey][$stateId] ?? null;
        if ($admittedThrough !== null && ($admission === null || $admission > $admittedThrough)) {
            return null;
        }
        return $this->byCollection[$collectionKey][$stateId] ?? null;
    }

    /**
     * Captured independently of insertion positions: an older row may move here after capture.
     * Repeated facts from the same origin keep admission; moving away and back never does.
     *
     * @return int Last origin admission, without walking or copying the reverse index
     */
    public function admissionCutoff(): int
    {
        return $this->admissionSequence;
    }

    /**
     * @param string $nodeId Previous origin losing this row
     * @param string $collectionKey Collection retaining its original outer position
     * @param int $position Original row position being removed from this origin
     */
    private function removeNodeRow(string $nodeId, string $collectionKey, int $position): void
    {
        unset($this->rowsByNode[$nodeId][$collectionKey][$position]);
        if ($this->rowsByNode[$nodeId][$collectionKey] !== []) {
            return;
        }
        unset($this->rowsByNode[$nodeId][$collectionKey], $this->rowsNeedingSort[$nodeId][$collectionKey],
            $this->collectionsByNode[$nodeId][$this->collectionPositions[$collectionKey]]);
        if ($this->rowsByNode[$nodeId] === []) {
            unset($this->rowsByNode[$nodeId], $this->collectionsByNode[$nodeId],
                $this->rowsNeedingSort[$nodeId], $this->collectionsNeedingSort[$nodeId]);
        } elseif (($this->rowsNeedingSort[$nodeId] ?? null) === []) {
            unset($this->rowsNeedingSort[$nodeId]);
        }
    }
}
