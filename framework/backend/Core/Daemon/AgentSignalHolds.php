<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Closure;
use Generator;
use Hilos\Core\Exception\LogicException;
use Throwable;

/**
 * Private master payload owner. Ready queues and retained scans carry scalar identities only,
 * so a completed envelope can die before receipt accounting while the next hold still waits.
 */
final class AgentSignalHolds
{
    private int $sequence = 0;

    private int $parkedCount = 0;

    /** @var array<int, AgentSignalHold> All pending obligations, including executing releases */
    private array $entries = [];

    /** Original admission order, including parked, released and executing obligations. */
    private SignalHoldIndex $pending;

    /** @var array<int, SignalHoldIndex> Original stop/browser child debt by its captured prefix identity */
    private array $byPrefix = [];

    /** @var array<string, SignalHoldIndex> Pending identities by addressed agent */
    private array $byAgent = [];

    /** @var array<string, SignalHoldIndex> Pending identities by caller correlation */
    private array $byCorrelation = [];

    /** @var array<string, int> Immediately invalidated original identities, pending physical cleanup */
    private array $canceledCorrelations = [];

    /** @var array<string, SignalHoldIndex> Page obligation identities by connection */
    private array $byAcceptKey = [];

    /** @var array<string, array<string, SignalHoldIndex>> Page obligation identities by connection and page */
    private array $byPage = [];

    /** @var array<string, int> Whole-connection invalidations awaiting indexed physical cleanup */
    private array $canceledConnections = [];

    /** @var array<string, array<string, int>> Page invalidations awaiting indexed physical cleanup */
    private array $canceledPages = [];

    /** @var array<int, true> Released FIFO; its internal pointer always names its head */
    private array $released = [];

    private SignalHoldIndex $placement;

    private SignalHoldIndex $resume;

    /** @var array<int, string> Parked placement identities, without payload references */
    private array $placementWaits = [];

    /** @var array<string, int> Number of parked placement obligations per addressed agent */
    private array $placementCounts = [];

    /** @var array<int, true> Parked protected-resume identities */
    private array $resumeWaits = [];

    /** @param ?Closure(AgentSignalHold): void $changed Actual obligation mutation, never a delivery callback */
    public function __construct(private readonly ?Closure $changed = null)
    {
        $this->pending = new SignalHoldIndex();
        $this->placement = new SignalHoldIndex();
        $this->resume = new SignalHoldIndex();
    }

    /**
     * @param ParkedAgentSignal $parked Newly blocked destination
     * @param ?int $stopPrefixId Captured originating stop prefix
     * @param ?int $browserPrefixId Captured originating browser prefix
     * @return AgentSignalHold Exact pending obligation
     */
    public function add(ParkedAgentSignal $parked, ?int $stopPrefixId = null, ?int $browserPrefixId = null): AgentSignalHold
    {
        $hold = new AgentSignalHold(++$this->sequence, $parked);
        $hold->stopPrefixId = $stopPrefixId;
        $hold->browserPrefixId = $browserPrefixId;
        $this->entries[$hold->id] = $hold;
        $this->pending->add($hold->id);
        foreach ([$stopPrefixId, $browserPrefixId] as $prefixId) {
            if ($prefixId !== null) {
                ($this->byPrefix[$prefixId] ??= new SignalHoldIndex())->add($hold->id);
            }
        }
        ($this->byAgent[$hold->agentId] ??= new SignalHoldIndex())->add($hold->id);
        if ($hold->correlationId !== null) {
            ($this->byCorrelation[$hold->correlationId] ??= new SignalHoldIndex())->add($hold->id);
        }
        if ($hold->acceptKey !== null) {
            ($this->byAcceptKey[$hold->acceptKey] ??= new SignalHoldIndex())->add($hold->id);
            ($this->byPage[$hold->acceptKey][$hold->page] ??= new SignalHoldIndex())->add($hold->id);
        }
        $this->parkedCount++;
        $this->notifyChanged($hold);

        return $hold;
    }

    /**
     * @param int $id Owner-local hold identity
     * @return ?AgentSignalHold Physical obligation, absent after settlement; canceled work must not execute
     */
    public function get(int $id): ?AgentSignalHold
    {
        return $this->entries[$id] ?? null;
    }

    /**
     * @param AgentSignalHold $hold Existing pending obligation
     * @return bool Whether its caller canceled before this original identity settled
     */
    public function isCanceled(AgentSignalHold $hold): bool
    {
        return ($hold->correlationId !== null && $hold->id <= ($this->canceledCorrelations[$hold->correlationId] ?? 0))
            || ($hold->acceptKey !== null && ($hold->id <= ($this->canceledConnections[$hold->acceptKey] ?? 0)
                || $hold->id <= ($this->canceledPages[$hold->acceptKey][$hold->page] ?? 0)));
    }

    /**
     * Invalidates the original cohort before returning its scalar cleanup cursor. Refusers
     * and released work must check the predicate even while an earlier effect owns the FIFO.
     *
     * @param string $correlationId Caller that stopped waiting
     * @return Generator<int, void> One original pending identity settled per step
     */
    public function cancelCorrelation(string $correlationId): Generator
    {
        $cutoff = $this->sequence;
        $this->canceledCorrelations[$correlationId] = $cutoff;
        return $this->walkCorrelationCancellation(
            ($this->byCorrelation[$correlationId] ?? new SignalHoldIndex())->candidates($cutoff), $correlationId, $cutoff,
        );
    }

    /**
     * Invalidates only already admitted obligations; later navigation may use the same key and page.
     * Physical settlement visits the selected scalar index, without copying its cohort.
     *
     * @param string $acceptKey Connection ending its original obligations
     * @param ?string $page Page being left, absent for the whole connection
     * @param ?Closure(AgentSignalHold): void $settling Transfer existing prefix debt before payload release
     * @return Generator<int, void> One original physical settlement per step
     */
    public function cancelConnection(string $acceptKey, ?string $page = null, ?Closure $settling = null): Generator
    {
        $cutoff = $this->sequence;
        if ($page === null) {
            $this->canceledConnections[$acceptKey] = $cutoff;
            $index = $this->byAcceptKey[$acceptKey] ?? new SignalHoldIndex();
        } else {
            $this->canceledPages[$acceptKey][$page] = $cutoff;
            $index = $this->byPage[$acceptKey][$page] ?? new SignalHoldIndex();
        }
        return $this->connectionCancellation($index->candidates($cutoff), $acceptKey, $page, $cutoff, $settling);
    }

    /** @return int Stored parked destinations, including canceled debt not yet physically settled */
    public function getParkedCount(): int
    {
        return $this->parkedCount;
    }

    /** @return int Last admitted obligation, without scanning or retaining its payload */
    public function getWatermark(): int
    {
        return $this->sequence;
    }

    /** @return bool Whether a released destination is ready for admission */
    public function hasReleased(): bool
    {
        return $this->released !== [];
    }

    /** @return ?int Head released identity without consuming it */
    public function firstReleasedId(): ?int
    {
        return key($this->released);
    }

    /**
     * @param int $id Pending blocked destination
     * @throws Throwable When an already canceled cursor fails during physical settlement
     */
    public function release(int $id): void
    {
        $hold = $this->entries[$id] ?? null;
        if ($hold === null || !$hold->isParked()) {
            return;
        }
        if ($this->isCanceled($hold)) {
            $this->settle($id);
            return;
        }
        $this->parkedCount--;
        $hold->released = true;
        $this->released[$id] = true;
        $this->notifyChanged($hold);
    }

    /**
     * Moving into a cursor does not remove the obligation from its agent's blocker index.
     *
     * @param int $id Released identity
     * @param SignalDispatch $dispatch Its one-agent cursor
     * @throws LogicException When the identity is absent or is not released
     */
    public function begin(int $id, SignalDispatch $dispatch): void
    {
        $hold = $this->entries[$id] ?? null;
        if ($hold === null || !$hold->released || $this->isCanceled($hold)) {
            throw new LogicException('Agent signal hold is not released');
        }
        unset($this->released[$id]);
        $hold->released = false;
        $hold->dispatch = $dispatch;
        $dispatch->heldId = $id;
        $dispatch->stopPrefixId = $hold->stopPrefixId;
        $dispatch->browserPrefixId = $hold->browserPrefixId;
        $hold->parked = null;
        $this->notifyChanged($hold);
    }

    /**
     * @param int $id Existing obligation being held again
     * @param ParkedAgentSignal $parked Replacement wait, preserving the original pending identity
     * @throws LogicException When the hold is absent or names another agent
     * @throws Throwable When an already canceled cursor fails during physical settlement
     */
    public function park(int $id, ParkedAgentSignal $parked): void
    {
        $hold = $this->entries[$id] ?? null;
        if ($hold === null || $hold->agentId !== $parked->agentId) {
            throw new LogicException('Agent signal hold cannot change its addressee');
        }
        if ($this->isCanceled($hold)) {
            $this->settle($id);
            return;
        }
        if ($hold->isParked() && $hold->parked === $parked) {
            return;
        }
        if (!$hold->isParked()) {
            $this->parkedCount++;
        }
        unset($this->released[$id]);
        $hold->parked = $parked;
        $hold->released = false;
        $hold->dispatch = null;
        $this->notifyChanged($hold);
    }

    /**
     * @param int $id Obligation delivered, refused or canceled
     * @throws Throwable First disposal or change-hook failure, after the original indexes have settled
     */
    public function settle(int $id): void
    {
        $hold = $this->entries[$id] ?? null;
        if ($hold === null) {
            return;
        }
        if ($hold->isParked()) {
            $this->parkedCount--;
        }
        $failure = null;
        try {
            $hold->cancel();
        } catch (Throwable $disposalFailure) {
            $failure = $disposalFailure;
        }
        unset($this->entries[$id], $this->released[$id]);
        $this->pending->remove($id);
        foreach ([$hold->stopPrefixId, $hold->browserPrefixId] as $prefixId) {
            if ($prefixId !== null && isset($this->byPrefix[$prefixId])) {
                $this->byPrefix[$prefixId]->remove($id);
                if ($this->byPrefix[$prefixId]->isEmpty()) {
                    unset($this->byPrefix[$prefixId]);
                }
            }
        }
        $this->byAgent[$hold->agentId]->remove($id);
        if ($this->byAgent[$hold->agentId]->isEmpty()) {
            unset($this->byAgent[$hold->agentId]);
        }
        if ($hold->correlationId !== null) {
            $this->byCorrelation[$hold->correlationId]->remove($id);
            if ($this->byCorrelation[$hold->correlationId]->isEmpty()) {
                unset($this->byCorrelation[$hold->correlationId]);
            }
        }
        if ($hold->acceptKey !== null) {
            $this->byAcceptKey[$hold->acceptKey]->remove($id);
            if ($this->byAcceptKey[$hold->acceptKey]->isEmpty()) {
                unset($this->byAcceptKey[$hold->acceptKey]);
            }
            $this->byPage[$hold->acceptKey][$hold->page]->remove($id);
            if ($this->byPage[$hold->acceptKey][$hold->page]->isEmpty()) {
                unset($this->byPage[$hold->acceptKey][$hold->page]);
                if ($this->byPage[$hold->acceptKey] === []) {
                    unset($this->byPage[$hold->acceptKey]);
                }
            }
        }
        try {
            $this->notifyChanged($hold);
        } catch (Throwable $changeFailure) {
            $failure ??= $changeFailure;
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * @param string $agentId Addressed agent
     * @param ?int $watermark Last predecessor, absent for the whole original cohort
     * @return Generator<int, int> Captured scalar identities, excluding future holds
     */
    public function agentCandidates(string $agentId, ?int $watermark = null): Generator
    {
        return ($this->byAgent[$agentId] ?? new SignalHoldIndex())->candidates($watermark);
    }

    /** @return Generator<int, int> Finite original unresolved placement obligations */
    public function placementCandidates(): Generator
    {
        return $this->placement->candidates();
    }

    /** @return Generator<int, int> Finite original protected-resume obligations */
    public function resumeCandidates(): Generator
    {
        return $this->resume->candidates();
    }

    /**
     * @param string $agentId Address whose retry remains relevant
     * @return bool Whether at least one parked obligation still needs placement
     */
    public function awaitsPlacement(string $agentId): bool
    {
        return isset($this->placementCounts[$agentId]);
    }

    /**
     * @param string $correlationId Abandoned caller
     * @return Generator<int, int> Its original pending identities
     */
    public function correlationCandidates(string $correlationId): Generator
    {
        return ($this->byCorrelation[$correlationId] ?? new SignalHoldIndex())->candidates();
    }

    /**
     * @param string $acceptKey Original page connection
     * @return Generator<int, int> Its original pending identities
     */
    public function connectionCandidates(string $acceptKey): Generator
    {
        return ($this->byAcceptKey[$acceptKey] ?? new SignalHoldIndex())->candidates();
    }

    /**
     * Both queries follow scalar indexes; unrelated later admissions are never visited.
     * A child admitted during validation changes its prefix's revision and requires a fresh
     * attempt before completion. Cursors retain no payload or copied membership array.
     *
     * @param int $watermark Original hold boundary captured by the prefix
     * @param int $prefixId Original stop or independent browser prefix
     * @return Generator<int, int> Pending original identities followed by their later original children
     */
    public function prefixCandidates(int $watermark, int $prefixId): Generator
    {
        yield from $this->pending->candidates($watermark);
        foreach (($this->byPrefix[$prefixId] ?? new SignalHoldIndex())->candidates() as $id) {
            if ($id > $watermark) {
                yield $id;
            }
        }
    }

    /**
     * Captures a finite watermark without copying payload entries. The live iteration is by
     * reference only to avoid PHP's COW foreach snapshot; it yields scalar ids, never row refs.
     * All membership changes stay in this owner and mutate the same array in place.
     *
     * @return Generator<int, int> Original pending identities still present when visited
     */
    public function candidates(): Generator
    {
        return $this->walkEntries($this->sequence);
    }

    /**
     * Diagnostic metadata view; retained stop validation visits scalar obligations instead.
     *
     * @return list<string> Agents with any blocked, released or active obligation
     */
    public function pendingAgentIds(): array
    {
        return array_keys($this->byAgent);
    }

    /**
     * Final owner departure cancels every retained lifetime without reporting another release.
     *
     * @throws Throwable First finalizer failure, after every independent hold and index has cleared
     */
    public function discard(): void
    {
        $failure = null;
        foreach ($this->entries as $hold) {
            try {
                $hold->cancel();
            } catch (Throwable $disposalFailure) {
                $failure ??= $disposalFailure;
            }
        }
        $this->entries = [];
        $this->pending = new SignalHoldIndex();
        $this->byPrefix = [];
        $this->byAgent = [];
        $this->byCorrelation = [];
        $this->canceledCorrelations = [];
        $this->byAcceptKey = [];
        $this->byPage = [];
        $this->canceledConnections = [];
        $this->canceledPages = [];
        $this->released = [];
        $this->placement = new SignalHoldIndex();
        $this->resume = new SignalHoldIndex();
        $this->placementWaits = [];
        $this->placementCounts = [];
        $this->resumeWaits = [];
        $this->parkedCount = 0;
        if ($failure !== null) {
            throw $failure;
        }
    }

    /** @param AgentSignalHold $hold Obligation after an actual state transition */
    private function notifyChanged(AgentSignalHold $hold): void
    {
        $placement = $hold->isParked() && $hold->parked->awaitingPlacement;
        if ($placement && !isset($this->placementWaits[$hold->id])) {
            $this->placementWaits[$hold->id] = $hold->agentId;
            $this->placementCounts[$hold->agentId] = ($this->placementCounts[$hold->agentId] ?? 0) + 1;
            $this->placement->add($hold->id);
        } elseif (!$placement && isset($this->placementWaits[$hold->id])) {
            unset($this->placementWaits[$hold->id]);
            if (--$this->placementCounts[$hold->agentId] === 0) {
                unset($this->placementCounts[$hold->agentId]);
            }
            $this->placement->remove($hold->id);
        }
        $resume = $hold->isParked() && $hold->parked->awaitingResume;
        if ($resume && !isset($this->resumeWaits[$hold->id])) {
            $this->resumeWaits[$hold->id] = true;
            $this->resume->add($hold->id);
        } elseif (!$resume && isset($this->resumeWaits[$hold->id])) {
            unset($this->resumeWaits[$hold->id]);
            $this->resume->remove($hold->id);
        }
        ($this->changed)?->__invoke($hold);
    }

    /**
     * @param int $watermark Last identity admitted before capture
     * @return Generator<int, int> Live scalar identities in original admission order
     */
    private function walkEntries(int $watermark): Generator
    {
        foreach ($this->entries as $id => &$hold) {
            unset($hold);
            if ($id > $watermark) {
                break;
            }
            yield $id;
        }
    }

    /**
     * @param Generator<int, int> $identities Original scalar cohort, excluding later holds
     * @param string $correlationId Original caller
     * @param int $cutoff Original invalidation generation
     * @return Generator<int, void> Physical settlement without retaining payload locals across yields
     * @throws Throwable First disposal failure after all original obligations and the cutoff have settled
     */
    private function walkCorrelationCancellation(Generator $identities, string $correlationId, int $cutoff): Generator
    {
        yield;
        $failure = null;
        foreach ($identities as $id) {
            try {
                $this->settle($id);
            } catch (Throwable $disposalFailure) {
                $failure ??= $disposalFailure;
            }
            yield;
        }
        if (($this->canceledCorrelations[$correlationId] ?? null) === $cutoff) {
            unset($this->canceledCorrelations[$correlationId]);
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * @param Generator<int, int> $identities Original connection or page cohort
     * @param string $acceptKey Original connection key
     * @param ?string $page Original page, absent for a whole connection
     * @param int $cutoff Exact invalidation generation
     * @param ?Closure(AgentSignalHold): void $settling Prefix transfer before settlement
     * @return Generator<int, void> Indexed cleanup, retaining no payload across a yield
     * @throws Throwable First disposal or transfer failure after all original obligations and the cutoff have settled
     */
    private function connectionCancellation(Generator $identities, string $acceptKey, ?string $page,
        int $cutoff, ?Closure $settling): Generator
    {
        yield;
        $failure = null;
        foreach ($identities as $id) {
            $hold = $this->entries[$id] ?? null;
            if ($hold !== null) {
                try {
                    $settling?->__invoke($hold);
                } catch (Throwable $transferFailure) {
                    $failure ??= $transferFailure;
                }
                unset($hold);
                try {
                    $this->settle($id);
                } catch (Throwable $disposalFailure) {
                    $failure ??= $disposalFailure;
                }
            }
            yield;
        }
        if ($page === null) {
            if (($this->canceledConnections[$acceptKey] ?? null) === $cutoff) {
                unset($this->canceledConnections[$acceptKey]);
            }
        } elseif (($this->canceledPages[$acceptKey][$page] ?? null) === $cutoff) {
            unset($this->canceledPages[$acceptKey][$page]);
            if ($this->canceledPages[$acceptKey] === []) {
                unset($this->canceledPages[$acceptKey]);
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }
}
