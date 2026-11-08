<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Closure;
use Generator;

/** One master's parked and ready identity metadata, with exact finite cancellation cohorts. */
final class IdentitySignalIndex
{
    private int $watermark = 0;

    private SignalHoldIndex $parked;

    /** @var array<int, IdentitySignalEntry> Metadata only; no signal or payload references */
    private array $entries = [];

    /** @var array<int, int> Ready sequence to its original parked identity */
    private array $released = [];

    /** @var array<string, SignalHoldIndex> Original obligations by connection */
    private array $byConnection = [];

    /** @var array<string, array<string, SignalHoldIndex>> Original obligations by connection and page */
    private array $byPage = [];

    /** @var array<string, int> Invalidated original connection cutoffs until physical settlement */
    private array $canceledConnections = [];

    /** @var array<string, array<string, int>> Invalidated original page cutoffs until physical settlement */
    private array $canceledPages = [];

    /** @param Closure(IdentitySignalEntry): void $settled Drop the manager's original payload and ready-prefix debt */
    public function __construct(private readonly Closure $settled)
    {
        $this->parked = new SignalHoldIndex();
    }

    /**
     * @param int $id Original monotonic parked identity
     * @param string $acceptKey Browser connection that owns the request
     * @param string $page Page being requested
     */
    public function add(int $id, string $acceptKey, string $page): void
    {
        $this->watermark = $id;
        $this->entries[$id] = new IdentitySignalEntry($id, $acceptKey, $page);
        $this->parked->add($id);
        ($this->byConnection[$acceptKey] ??= new SignalHoldIndex())->add($id);
        ($this->byPage[$acceptKey][$page] ??= new SignalHoldIndex())->add($id);
    }

    /**
     * @param int $id Original parked identity
     * @return ?IdentitySignalEntry Original metadata, absent after physical settlement
     */
    public function get(int $id): ?IdentitySignalEntry
    {
        return $this->entries[$id] ?? null;
    }

    /**
     * @param int $releaseId Ready sequence
     * @return ?IdentitySignalEntry Original obligation now queued for admission
     */
    public function releasedEntry(int $releaseId): ?IdentitySignalEntry
    {
        $id = $this->released[$releaseId] ?? null;
        return $id === null ? null : $this->get($id);
    }

    /**
     * @param IdentitySignalEntry $entry Existing original obligation
     * @return bool Whether its exact original lifetime was invalidated
     */
    public function isCanceled(IdentitySignalEntry $entry): bool
    {
        return $entry->id <= ($this->canceledConnections[$entry->acceptKey] ?? 0)
            || $entry->id <= ($this->canceledPages[$entry->acceptKey][$entry->page] ?? 0);
    }

    /**
     * @param int $id Original parked identity
     * @param int $releaseId New ready sequence, retaining original connection/page cancellation membership
     */
    public function release(int $id, int $releaseId): void
    {
        $entry = $this->entries[$id] ?? null;
        if ($entry === null) {
            return;
        }
        $this->parked->remove($id);
        $entry->releaseId = $releaseId;
        $this->released[$releaseId] = $id;
        if ($this->isCanceled($entry)) {
            $this->settle($id);
        }
    }

    /** @param int $id Original obligation delivered, canceled or otherwise settled */
    public function settle(int $id): void
    {
        $entry = $this->entries[$id] ?? null;
        if ($entry === null) {
            return;
        }
        unset($this->entries[$id]);
        $this->parked->remove($id);
        if ($entry->releaseId !== null) {
            unset($this->released[$entry->releaseId]);
        }
        $this->byConnection[$entry->acceptKey]->remove($id);
        if ($this->byConnection[$entry->acceptKey]->isEmpty()) {
            unset($this->byConnection[$entry->acceptKey]);
        }
        $this->byPage[$entry->acceptKey][$entry->page]->remove($id);
        if ($this->byPage[$entry->acceptKey][$entry->page]->isEmpty()) {
            unset($this->byPage[$entry->acceptKey][$entry->page]);
            if ($this->byPage[$entry->acceptKey] === []) {
                unset($this->byPage[$entry->acceptKey]);
            }
        }
        ($this->settled)($entry);
    }

    /** @return ?int Oldest still-parked obligation, whose original monotonic deadline is earliest */
    public function firstParkedId(): ?int
    {
        return $this->parked->successor(null);
    }

    /**
     * @param string $acceptKey Connection whose identity copy changed
     * @return Generator<int, int> Finite dependent cohort, excluding later navigation
     */
    public function connectionCandidates(string $acceptKey): Generator
    {
        return ($this->byConnection[$acceptKey] ?? new SignalHoldIndex())->candidates();
    }

    /** @return Generator<int, int> Finite original parked cohort for a deadline or context replacement */
    public function parkedCandidates(): Generator
    {
        return $this->parked->candidates();
    }

    /**
     * @param string $acceptKey Original connection ending its wait
     * @param ?string $page Original page, absent for the whole connection
     * @return Generator<int, void> Portioned physical release after immediate invalidation
     */
    public function cancelConnection(string $acceptKey, ?string $page = null): Generator
    {
        $cutoff = $this->watermark;
        if ($page === null) {
            $this->canceledConnections[$acceptKey] = $cutoff;
            $index = $this->byConnection[$acceptKey] ?? new SignalHoldIndex();
        } else {
            $this->canceledPages[$acceptKey][$page] = $cutoff;
            $index = $this->byPage[$acceptKey][$page] ?? new SignalHoldIndex();
        }
        return $this->cancellation($index->candidates($cutoff), $acceptKey, $page, $cutoff);
    }

    /**
     * @param Generator<int, int> $identities Original scalar cohort, excluding future navigation
     * @param string $acceptKey Original connection
     * @param ?string $page Original page, absent for whole-connection invalidation
     * @param int $cutoff Exact cancellation generation
     * @return Generator<int, void> One original physical settlement per step
     */
    private function cancellation(Generator $identities, string $acceptKey, ?string $page, int $cutoff): Generator
    {
        yield;
        foreach ($identities as $id) {
            $this->settle($id);
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
    }
}
