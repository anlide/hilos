<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Closure;
use Generator;

/** One master's indexed pending address ledger, without a second owner of completed payloads. */
final class PendingPageIndex
{
    private int $sequence = 0;

    private int $sourceSequence = 0;

    private int $sourceConnectionSequence = 0;

    /** @var array<int, PendingPageEntry> Scalar obligations and their original lifetime flags */
    private array $entries = [];

    /** @var array<string, array<string, int>> Exact pending token to original admission */
    private array $byToken = [];

    /** @var array<string, PendingPageConnection> Current active connection lifetimes */
    private array $connections = [];

    /** @var array<int, int> Current source generation to its stable attempt admission */
    private array $sources = [];

    /** @var array<int, PendingPageSource> Original connection interests in selected source addresses */
    private array $sourceConnections = [];

    /** @var array<string, SignalHoldIndex> Connection-interest generations selected on each remote node */
    private array $byNode = [];

    /** @var array<string, SignalHoldIndex> Connection-interest generations selected for local agents */
    private array $byAgent = [];

    /**
     * @param Closure(string, string): ?PendingPageDelivery $resolve Read the manager's original payload slot
     * @param Closure(PendingPageEntry): void $settled Release that exact original payload slot
     */
    public function __construct(private readonly Closure $resolve, private readonly Closure $settled)
    {
    }

    /** @param PendingPageDelivery $delivery Selected serving address, before the original payload slot is written */
    public function record(PendingPageDelivery $delivery): void
    {
        $id = $this->byToken[$delivery->acceptKey][$delivery->deliveryId] ?? null;
        $entry = $id === null ? null : $this->entries[$id];
        if ($entry !== null && (!$this->isActive($entry) || $entry->page !== $delivery->page)) {
            $this->settle($entry->id);
            $entry = null;
        }
        if ($entry === null) {
            $connection = $this->connections[$delivery->acceptKey] ??= new PendingPageConnection();
            $bucket = $connection->pages[$delivery->page] ??= new PendingPageBucket();
            $entry = new PendingPageEntry(++$this->sequence, $delivery->acceptKey, $delivery->deliveryId, $delivery->page,
                $connection, $bucket, $delivery->agentId, $delivery->nodeId);
            $this->entries[$entry->id] = $entry;
            $this->byToken[$entry->acceptKey][$entry->deliveryId] = $entry->id;
            $connection->identities->add($entry->id);
            $bucket->identities->add($entry->id);
            $connection->setPageTail($entry->page, $entry->id);
        } else {
            $this->forgetSource($entry);
            $entry->agentId = $delivery->agentId;
            $entry->nodeId = $delivery->nodeId;
        }
        $entry->sourceId = ++$this->sourceSequence;
        $this->sources[$entry->sourceId] = $entry->id;
        $key = serialize([$entry->nodeId === null ? $entry->agentId : null, $entry->nodeId]);
        $sourceConnectionId = $entry->connection->sources[$key] ?? null;
        if ($sourceConnectionId === null) {
            $source = new PendingPageSource(++$this->sourceConnectionSequence, $key, $entry->connection, $entry->agentId, $entry->nodeId);
            $this->sourceConnections[$source->id] = $source;
            $entry->connection->sources[$key] = $source->id;
            if ($entry->nodeId === null) {
                ($this->byAgent[$entry->agentId] ??= new SignalHoldIndex())->add($source->id);
            } else {
                ($this->byNode[$entry->nodeId] ??= new SignalHoldIndex())->add($source->id);
            }
        } else {
            $source = $this->sourceConnections[$sourceConnectionId];
        }
        $source->pending++;
        $entry->sourceConnectionId = $source->id;
    }

    /**
     * @param string $acceptKey Original connection key
     * @param string $deliveryId Exact internal attempt token
     * @return ?PendingPageDelivery Still-active original payload, absent immediately after cancellation
     */
    public function get(string $acceptKey, string $deliveryId): ?PendingPageDelivery
    {
        $id = $this->byToken[$acceptKey][$deliveryId] ?? null;
        $entry = $id === null ? null : ($this->entries[$id] ?? null);
        return $entry !== null && $this->isActive($entry) ? ($this->resolve)($acceptKey, $deliveryId) : null;
    }

    /**
     * @param string $acceptKey Original connection
     * @return ?PendingPageDelivery Latest active original admission, unaffected by an older token being addressed again
     */
    public function last(string $acceptKey): ?PendingPageDelivery
    {
        $id = ($this->connections[$acceptKey] ?? null)?->lastIdentity();
        $entry = $id === null ? null : $this->entries[$id];
        return $entry === null ? null : $this->get($acceptKey, $entry->deliveryId);
    }

    /**
     * @param string $acceptKey Original connection
     * @param string $page Original page
     * @return bool Whether that page has any active pending attempt
     */
    public function hasPage(string $acceptKey, string $page): bool
    {
        return isset($this->connections[$acceptKey]->pages[$page]);
    }

    /**
     * @param string $acceptKey Original connection
     * @param string $deliveryId Exact completed attempt token
     */
    public function remove(string $acceptKey, string $deliveryId): void
    {
        $id = $this->byToken[$acceptKey][$deliveryId] ?? null;
        if ($id !== null) {
            $this->settle($id);
        }
    }

    /**
     * @param int $sourceId Original selected-address generation
     * @return ?PendingPageDelivery Current original payload, absent after readdressing, completion or cancellation
     */
    public function candidate(int $sourceId): ?PendingPageDelivery
    {
        $id = $this->sources[$sourceId] ?? null;
        $entry = $id === null ? null : ($this->entries[$id] ?? null);
        return $entry !== null && $entry->sourceId === $sourceId ? $this->get($entry->acceptKey, $entry->deliveryId) : null;
    }

    /**
     * @param string $acceptKey Original connection ending its wait
     * @param ?string $page Original page, absent for the whole connection
     * @return Generator<int, void> Indexed physical cleanup after immediate logical invalidation
     */
    public function cancelConnection(string $acceptKey, ?string $page = null): Generator
    {
        $connection = $this->connections[$acceptKey] ?? null;
        if ($page === null) {
            if ($connection !== null) {
                $connection->active = false;
            }
            unset($this->connections[$acceptKey]);
            $identities = ($connection?->identities ?? new SignalHoldIndex())->candidates();
        } else {
            $bucket = $connection?->pages[$page] ?? null;
            if ($bucket !== null) {
                $bucket->active = false;
                unset($connection->pages[$page]);
                $connection->setPageTail($page, null);
            }
            $identities = ($bucket?->identities ?? new SignalHoldIndex())->candidates();
        }
        return $this->cancellation($identities);
    }

    /**
     * @param list<string> $agentIds Original lost local agents
     * @param ?string $nodeId Original lost remote node
     * @param ?string $acceptKey One connection changing owner
     * @param ?string $page Original page of that connection
     * @return Generator<int, ?int> Scalar source generations from the original finite addressed cohort
     */
    public function recoveryCandidates(array $agentIds = [], ?string $nodeId = null, ?string $acceptKey = null, ?string $page = null): Generator
    {
        $tail = $this->sourceSequence;
        if ($acceptKey !== null) {
            $bucket = ($this->connections[$acceptKey] ?? null)?->pages[$page] ?? null;
            return $this->connectionCandidates(($bucket?->identities ?? new SignalHoldIndex())->candidates(), $tail);
        }
        if ($nodeId !== null) {
            return $this->nodeCandidates(
                ($this->byNode[$nodeId] ?? new SignalHoldIndex())->candidates($this->sourceConnectionSequence),
                $this->sequence, $tail, $nodeId,
            );
        }
        return $this->agentCandidates($agentIds, $this->sourceConnectionSequence, $this->sequence, $tail);
    }

    /**
     * @param PendingPageEntry $entry Existing original obligation
     * @return bool Whether its original connection and page lifetimes remain current
     */
    private function isActive(PendingPageEntry $entry): bool
    {
        return $entry->connection->active && $entry->bucket->active;
    }

    /** @param int $id Original obligation whose payload is now complete or abandoned */
    private function settle(int $id): void
    {
        $entry = $this->entries[$id] ?? null;
        if ($entry === null) {
            return;
        }
        unset($this->entries[$id], $this->byToken[$entry->acceptKey][$entry->deliveryId]);
        if ($this->byToken[$entry->acceptKey] === []) {
            unset($this->byToken[$entry->acceptKey]);
        }
        $this->forgetSource($entry);
        $entry->connection->identities->remove($id);
        $entry->bucket->identities->remove($id);
        if (($entry->connection->pages[$entry->page] ?? null) === $entry->bucket) {
            $tail = $entry->bucket->identities->lastIdentity();
            $entry->connection->setPageTail($entry->page, $tail);
            if ($tail === null) {
                unset($entry->connection->pages[$entry->page]);
            }
        }
        if ($entry->connection->identities->isEmpty() && ($this->connections[$entry->acceptKey] ?? null) === $entry->connection) {
            unset($this->connections[$entry->acceptKey]);
        }
        ($this->settled)($entry);
    }

    /** @param PendingPageEntry $entry Original selected-address generation being retired */
    private function forgetSource(PendingPageEntry $entry): void
    {
        unset($this->sources[$entry->sourceId]);
        $source = $this->sourceConnections[$entry->sourceConnectionId] ?? null;
        if ($source === null || --$source->pending > 0) {
            return;
        }
        unset($this->sourceConnections[$source->id], $source->connection->sources[$source->key]);
        if ($source->nodeId === null) {
            $index = $this->byAgent[$source->agentId] ?? null;
            $index?->remove($source->id);
            if ($index?->isEmpty()) {
                unset($this->byAgent[$source->agentId]);
            }
        } else {
            $index = $this->byNode[$source->nodeId] ?? null;
            $index?->remove($source->id);
            if ($index?->isEmpty()) {
                unset($this->byNode[$source->nodeId]);
            }
        }
    }

    /**
     * @param Generator<int, int> $identities Captured original admissions
     * @return Generator<int, void> Physical payload release without a roster snapshot
     */
    private function cancellation(Generator $identities): Generator
    {
        yield;
        foreach ($identities as $id) {
            $this->settle($id);
            yield;
        }
    }

    /**
     * @param Generator<int, int> $identities Original page admissions
     * @param int $tail Last source generation at the recovery fact
     * @return Generator<int, ?int> Current original source, or an empty bounded visit
     */
    private function connectionCandidates(Generator $identities, int $tail): Generator
    {
        foreach ($identities as $id) {
            $entry = $this->entries[$id] ?? null;
            yield $entry !== null && $entry->sourceId <= $tail ? $entry->sourceId : null;
        }
    }

    /**
     * @param list<string> $agentIds Original local loss cohort
     * @param int $interestTail Last original connection-interest generation
     * @param int $entryTail Last original pending admission
     * @param int $tail Last original selected-address generation
     * @return Generator<int, ?int> Deduplicated original agent source generations
     */
    private function agentCandidates(array $agentIds, int $interestTail, int $entryTail, int $tail): Generator
    {
        $agents = [];
        foreach ($agentIds as $agentId) {
            $agents[$agentId] = true;
            yield null;
        }
        $seen = [];
        foreach ($agents as $agentId => $_) {
            foreach (($this->byAgent[$agentId] ?? new SignalHoldIndex())->candidates($interestTail) as $interestId) {
                yield null;
                $source = $this->sourceConnections[$interestId] ?? null;
                if ($source === null || isset($seen[spl_object_id($source->connection)])) {
                    continue;
                }
                $seen[spl_object_id($source->connection)] = true;
                yield from $this->selectedConnectionCandidates($source->connection->identities->candidates($entryTail), $tail, $agents);
            }
        }
    }

    /**
     * @param Generator<int, int> $interests Original connection interests in the lost node
     * @param int $entryTail Last original pending admission
     * @param int $tail Last original selected-address generation
     * @param string $nodeId Original remote node
     * @return Generator<int, ?int> Original attempts in their connection's original admission order
     */
    private function nodeCandidates(Generator $interests, int $entryTail, int $tail, string $nodeId): Generator
    {
        foreach ($interests as $interestId) {
            yield null;
            $source = $this->sourceConnections[$interestId] ?? null;
            if ($source !== null) {
                yield from $this->selectedConnectionCandidates($source->connection->identities->candidates($entryTail), $tail, [], $nodeId);
            }
        }
    }

    /**
     * @param Generator<int, int> $identities Original pending admissions in connection order
     * @param int $tail Last selected-address generation at the loss fact
     * @param array<string, true> $agents Original local agent membership set
     * @param ?string $nodeId Original remote node, absent for local agents
     * @return Generator<int, ?int> Original matching selected-address identity, or an empty bounded visit
     */
    private function selectedConnectionCandidates(Generator $identities, int $tail, array $agents, ?string $nodeId = null): Generator
    {
        foreach ($identities as $id) {
            $entry = $this->entries[$id] ?? null;
            $matches = $entry !== null && $entry->sourceId <= $tail
                && ($nodeId === null ? ($entry->nodeId === null && isset($agents[$entry->agentId])) : $entry->nodeId === $nodeId);
            yield $matches ? $entry->sourceId : null;
        }
    }
}
