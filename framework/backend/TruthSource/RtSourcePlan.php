<?php

declare(strict_types=1);

namespace Hilos\TruthSource;

use Generator;
use Hilos\Cluster\Peer\DTO\PeerRtClaimEntry;
use Hilos\Core\Agent\AgentId;

/** Private complete claim fold, built one report transition, collection name or claimed key at a time. */
final class RtSourcePlan
{
    public private(set) bool $ready = false;

    /** @var list<string> Full owners in original collection order */
    public private(set) array $fullyOwnedCollections = [];

    /** @var array<string, true> Full-operation whole-collection ownership for constant-time source selection */
    public private(set) array $fullWholeClaims = [];

    /** @var array<string, list<string>> Named-row scopes, excluding full owners and set claims */
    public private(set) array $keyScopes = [];

    /** @var array<string, list<string>> Set scopes, excluding full owners and partial operations */
    public private(set) array $setScopes = [];

    /** @var array<string, true> Ownership without a named-row restriction, including partial operations */
    public private(set) array $wholeClaims = [];

    /** @var array<string, array<string, true>> Named-row ownership, including partial operations */
    public private(set) array $rowClaims = [];

    /** @var array<string, array<string, true>> Set ownership used for replica classification, including partial operations */
    public private(set) array $setClaims = [];

    /** @var list<RtSourceReport> Original report values, retained without their mutable owner */
    private array $reports = [];

    /** @param Generator<int, RtSourceReport> $reports Original finite immutable report cohort */
    public function __construct(private readonly Generator $originalReports)
    {
    }

    /**
     * Consume once until ready; FIFO consumers reuse the completed result. Each string/key visit
     * costs that input's bytes and a hash operation. No pass copies the accumulated scopes.
     * Output becomes usable only when the original report cohort has been folded completely.
     *
     * @return Generator<int, void> One original report transition, collection, partial name or claimed key per step
     */
    public function build(): Generator
    {
        if ($this->ready) {
            return;
        }
        $collections = [];
        $seenCollections = [];
        $keyScopes = [];
        $seenKeys = [];
        $setScopes = [];
        $seenSets = [];
        yield;
        foreach ($this->originalReports as $report) {
            $this->reports[] = $report;
            $partial = [];
            $declared = [];
            yield;
            foreach ($report->partial as $collectionKey) {
                $partial[$collectionKey] = true;
                yield;
            }
            foreach ($report->collections as $collectionKey) {
                $declared[$collectionKey] = true;
                if (!isset($seenCollections[$collectionKey])) {
                    $seenCollections[$collectionKey] = true;
                    $collections[] = $collectionKey;
                }
                if (!isset($report->keys[$collectionKey])) {
                    $this->wholeClaims[$collectionKey] = true;
                    if (!isset($partial[$collectionKey])) {
                        $this->fullWholeClaims[$collectionKey] = true;
                    }
                }
                yield;
            }
            foreach ($report->keys as $collectionKey => $keys) {
                $inScope = isset($declared[$collectionKey]);
                $handedOver = $inScope && is_string($collectionKey) && !isset($partial[$collectionKey])
                    && !isset($report->sets[$collectionKey]);
                if ($handedOver) {
                    $keyScopes[$collectionKey] ??= [];
                }
                yield;
                if (!$inScope) {
                    continue;
                }
                foreach ($keys as $stateId) {
                    $this->rowClaims[$collectionKey][$stateId] = true;
                    if ($handedOver && !isset($seenKeys[$collectionKey][$stateId])) {
                        $seenKeys[$collectionKey][$stateId] = true;
                        $keyScopes[$collectionKey][] = $stateId;
                    }
                    yield;
                }
            }
            foreach ($report->sets as $collectionKey => $setKey) {
                if (isset($declared[$collectionKey])) {
                    $this->setClaims[$collectionKey][$setKey] = true;
                    if (is_string($collectionKey) && !isset($partial[$collectionKey]) && !isset($seenSets[$collectionKey][$setKey])) {
                        $seenSets[$collectionKey][$setKey] = true;
                        $setScopes[$collectionKey][] = $setKey;
                    }
                }
                yield;
            }
        }
        foreach ($collections as $collectionKey) {
            if (isset($this->fullWholeClaims[$collectionKey])) {
                $this->fullyOwnedCollections[] = $collectionKey;
            }
            yield;
        }
        foreach ($keyScopes as $collectionKey => $keys) {
            if (!isset($this->fullWholeClaims[$collectionKey])) {
                $this->keyScopes[$collectionKey] = $keys;
            }
            yield;
        }
        foreach ($setScopes as $collectionKey => $sets) {
            if (!isset($this->fullWholeClaims[$collectionKey])) {
                $this->setScopes[$collectionKey] = $sets;
            }
            yield;
        }
        $this->ready = true;
    }

    /**
     * Keep the leader's per-agent report unaggregated. Agent identity parsing and each DTO
     * construction cost that one original report's fields, not all reports at once.
     *
     * @return Generator<int, PeerRtClaimEntry> Ordinary claim entries in original agent order
     */
    public function claimEntries(): Generator
    {
        foreach ($this->reports as $report) {
            $agent = AgentId::fromId($report->agentId);
            yield new PeerRtClaimEntry($report->agentId, $agent->type, $agent->index,
                $report->collections, $report->partial, $report->keys);
        }
    }
}
