<?php

declare(strict_types=1);

namespace Hilos\Core\Source\Interest;

use Closure;
use Generator;
use Hilos\Core\Source\SourceChange;
use Hilos\TruthSource\RtNodeSourceMap;
use WeakReference;

/**
 * Who holds a collection, as far as the process doing the sending knows.
 *
 * The addressing half of what {@see RtNodeSourceMap} does for ownership, and built the same way
 * and for the same reason: the answer lives in another process, so it has to be reported rather
 * than read, and what arrives is kept here beside the sender. Where the two differ is the
 * question - that map says whether a frame may be believed, this one says whether it is worth
 * sending at all.
 *
 * One class with two instances, because the two levels of the same fan-out ask exactly this: the
 * daemon master holds one keyed by worker, the peer server holds one keyed by node. A holder is
 * therefore a plain string and the caller says what it means; nothing here needs to know which
 * of the two it is looking at.
 *
 * A holder's list is replaced whole ({@see note()}), never merged. What is reported is the whole
 * of what that holder reads, so a key absent from a later report is a key it stopped reading -
 * and merging would keep sending frames for it until the holder died.
 */
final class SourceReaderMap
{
    /** @var array<string, array<string, list<string>>> [holder id => [kind => collection keys]] */
    private array $byHolder = [];

    /** @var array<string, array<string, array<string, true>>> Private membership lookup; raw reports retain their original order */
    private array $membershipByHolder = [];

    /** @var array<string, int> Stable outer-holder order; a full release followed by a rejoin receives a new position */
    private array $reportPositions = [];

    private int $reportSequence = 0;

    /** Immutable scalar report paths; retained union readers never capture the mutable whole map. */
    private ?SourceReaderReportNode $reportRoot = null;

    /** Complete paired union for this exact report basis; old FIFO readers keep their own finite basis. */
    private ?SourceReaderUnion $union = null;

    /**
     * Records everything one holder reads, replacing whatever it read before.
     *
     * The returned keys are what the caller owes the holder an initial state for: a collection
     * already on its list is one it already holds a copy of, and sending it again would undo
     * whatever deltas landed since.
     *
     * @param string $holderId Worker or node reporting what it reads
     * @param string $kind Source kind, KIND_DB or KIND_RT of {@see SourceChange}
     * @param list<string> $collectionKeys Every collection it reads of that kind
     * @return list<string> Those of them this holder did not read before
     */
    public function note(string $holderId, string $kind, array $collectionKeys): array
    {
        $held = $this->membershipByHolder[$holderId][$kind] ?? [];

        $added = [];
        foreach ($collectionKeys as $collectionKey) {
            if (!isset($held[$collectionKey])) {
                $added[] = $collectionKey;
            }
        }

        $this->replace($holderId, $kind, $collectionKeys, array_fill_keys($collectionKeys, true));

        return $added;
    }

    /**
     * Builds both halves privately, then publishes the complete report without a yield between
     * them. The caller serializes reports and validates the exact transport on every step.
     * Original membership is read when this FIFO entry starts, after any earlier report.
     * Each visit still costs that name's bytes/hash and possible PHP array growth; retirement
     * of the old report remains separate from this construction bound.
     *
     * @param string $holderId Worker or node reporting its complete interest
     * @param list<string> $rtCollections Every runtime collection in original report order
     * @param list<string> $dbCollections Every database collection in original report order
     * @param Closure(): bool $isCurrent Exact original transport and FIFO binding, false after departure
     * @return Generator<int, void, void, array{db: list<string>, rt: list<string>}> Newly owed states, empty after departure
     */
    public function noteReportDelivery(string $holderId, array $rtCollections, array $dbCollections, Closure $isCurrent): Generator
    {
        $map = WeakReference::create($this);
        return (static function () use ($map, $holderId, $rtCollections, $dbCollections, $isCurrent): Generator {
            $added = [SourceChange::KIND_DB => [], SourceChange::KIND_RT => []];
            yield;
            if (!$isCurrent() || $map->get() === null) {
                return $added;
            }
            $held = $map->get()->membershipByHolder[$holderId] ?? [];
            $membership = [];
            foreach ([SourceChange::KIND_DB => $dbCollections, SourceChange::KIND_RT => $rtCollections] as $kind => $keys) {
                $membership[$kind] = [];
                yield;
                foreach ($keys as $collectionKey) {
                    if (!$isCurrent() || $map->get() === null) {
                        return [SourceChange::KIND_DB => [], SourceChange::KIND_RT => []];
                    }
                    if (!isset($held[$kind][$collectionKey])) {
                        $added[$kind][] = $collectionKey;
                    }
                    $membership[$kind][$collectionKey] = true;
                    yield;
                }
            }
            if (!$isCurrent() || $map->get() === null) {
                return [SourceChange::KIND_DB => [], SourceChange::KIND_RT => []];
            }
            $map->get()->replace($holderId, SourceChange::KIND_DB, $dbCollections, $membership[SourceChange::KIND_DB]);
            $map->get()->replace($holderId, SourceChange::KIND_RT, $rtCollections, $membership[SourceChange::KIND_RT]);
            return $added;
        })();
    }

    /**
     * Drops everything one holder read, because it is gone.
     *
     * A worker that dies never reports its own departure, and an entry outliving the process
     * behind it is the same wrong answer {@see RtNodeSourceMap::release()} exists to prevent -
     * frames addressed to a reader that is not there.
     *
     * @param string $holderId Worker or node whose link closed
     */
    public function release(string $holderId): void
    {
        if (isset($this->reportPositions[$holderId])) {
            $this->union = null;
            $this->reportRoot = SourceReaderReportNode::remove($this->reportRoot, $this->reportPositions[$holderId]);
            unset($this->reportPositions[$holderId]);
        }
        unset($this->byHolder[$holderId], $this->membershipByHolder[$holderId]);
    }

    /**
     * @param string $holderId Worker or node to ask about
     * @param string $kind Source kind, KIND_DB or KIND_RT of {@see SourceChange}
     * @param string $collectionKey Collection to ask about
     * @return bool True when that holder reads it
     */
    public function holds(string $holderId, string $kind, string $collectionKey): bool
    {
        return isset($this->membershipByHolder[$holderId][$kind][$collectionKey]);
    }

    /**
     * @param string $kind Source kind, KIND_DB or KIND_RT of {@see SourceChange}
     * @param string $collectionKey Collection to ask about
     * @return list<string> Holders that read it, in the order they first reported
     */
    public function holders(string $kind, string $collectionKey): array
    {
        $holders = [];
        foreach ($this->membershipByHolder as $holderId => $byKind) {
            if (isset($byKind[$kind][$collectionKey])) {
                $holders[] = (string)$holderId;
            }
        }

        return $holders;
    }

    /**
     * Returns everything any holder of this map reads, each collection named once.
     *
     * The union rather than a holder's own list, because that is what the level above asks for:
     * a node tells the mesh what it reads as one list, and which of its workers is behind a key
     * is nobody else's business - the answer would be the same for the sender either way.
     * Completed results are reused until a report changes that kind or a holder leaves, so the
     * next report can capture its predecessor's union without walking every name again.
     *
     * @param string $kind Source kind, KIND_DB or KIND_RT of {@see SourceChange}
     * @return list<string> Collections at least one holder reads, in current holder/report order
     */
    public function collections(string $kind): array
    {
        if ($kind === SourceChange::KIND_RT || $kind === SourceChange::KIND_DB) {
            $union = $this->unionPlan();
            foreach ($union->build() as $_) {
            }
            return $union->collections[$kind];
        }
        $collections = [];
        foreach ($this->collectionCandidates($kind) as $collectionKey) {
            if ($collectionKey !== null) {
                $collections[] = $collectionKey;
            }
        }

        return $collections;
    }

    /**
     * Captures both kinds in O(1), sharing immutable report paths rather than the mutable map.
     * A completed pair remains the next transition's BEFORE basis without rebuilding it.
     *
     * @return SourceReaderUnion Private restartable preparation for this exact original report basis
     */
    public function unionPlan(): SourceReaderUnion
    {
        if ($this->union === null) {
            $root = $this->reportRoot;
            $this->union = new SourceReaderUnion(
                static fn(): Generator => self::unionCandidates($root, SourceChange::KIND_RT),
                static fn(): Generator => self::unionCandidates($root, SourceChange::KIND_DB),
            );
        }
        return $this->union;
    }

    /**
     * Captures original holder and report values in O(1), before the first advancement. Null
     * pays for a holder transition or duplicate, so a large duplicate input cannot hide in one
     * next(). Each name still costs its bytes/hash and possible seen-map growth; immutable root
     * retirement and final array destruction are separate costs, not a total byte bound.
     *
     * @param string $kind Source kind whose finite ordered union is needed
     * @return Generator<int, ?string> Original unique names, with one visit per holder or raw name
     */
    public function collectionCandidates(string $kind): Generator
    {
        return self::unionCandidates($this->reportRoot, $kind);
    }

    /**
     * @param ?SourceReaderReportNode $root Original immutable report cohort, never its mutable owner
     * @param string $kind Source kind to fold
     * @return Generator<int, ?string> One raw name or holder transition after O(log holders) tree transitions
     */
    private static function unionCandidates(?SourceReaderReportNode $root, string $kind): Generator
    {
        $seen = [];
        foreach (self::orderedReports($root) as $report) {
            yield null;
            foreach ($report[$kind] ?? [] as $collectionKey) {
                if (isset($seen[$collectionKey])) {
                    yield null;
                } else {
                    $seen[$collectionKey] = true;
                    yield $collectionKey;
                }
            }
        }
    }

    /**
     * @param ?SourceReaderReportNode $node Original immutable subtree
     * @return Generator<int, array<string, list<string>>> Original holder reports in original outer order
     */
    private static function orderedReports(?SourceReaderReportNode $node): Generator
    {
        if ($node === null) {
            return;
        }
        yield from self::orderedReports($node->left);
        yield $node->position => $node->report;
        yield from self::orderedReports($node->right);
    }

    /**
     * @param string $holderId Original holder whose report is being replaced
     * @param string $kind Source kind whose raw order and membership change together
     * @param list<string> $collectionKeys Complete original report, including duplicate names
     * @param array<string, true> $membership Prepared membership lookup
     */
    private function replace(string $holderId, string $kind, array $collectionKeys, array $membership): void
    {
        $this->union = null;
        if ($collectionKeys === []) {
            unset($this->byHolder[$holderId][$kind], $this->membershipByHolder[$holderId][$kind]);
            if (($this->byHolder[$holderId] ?? []) === []) {
                $this->release($holderId);
            } elseif (isset($this->reportPositions[$holderId])) {
                $this->reportRoot = SourceReaderReportNode::put($this->reportRoot, $this->reportPositions[$holderId], $this->byHolder[$holderId]);
            }
            return;
        }
        $this->byHolder[$holderId][$kind] = $collectionKeys;
        $this->membershipByHolder[$holderId][$kind] = $membership;
        $position = $this->reportPositions[$holderId] ??= ++$this->reportSequence;
        $this->reportRoot = SourceReaderReportNode::put($this->reportRoot, $position, $this->byHolder[$holderId]);
    }
}
