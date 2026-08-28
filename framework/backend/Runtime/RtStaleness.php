<?php

declare(strict_types=1);

namespace Hilos\Runtime;

use Hilos\Runtime\View\Collection\HilosSessionRotations;
use Hilos\TruthSource\RtReplicaOriginMap;

/**
 * Which RT rows this process holds a frozen copy of, and since when.
 *
 * A replica whose owner became unreachable is still served — refusing everything on a broken
 * link would replace a stale answer with an empty one, which is no more true (HIL-711). What
 * changes is that the row now carries an answer to "is its source still reachable": frozen since
 * moment T, or fresh. What a reader does with that answer is the reader's own decision, and only
 * one of them in the framework refuses outright ({@see HilosSessionRotations::claimable()}).
 *
 * The mark lives BESIDE the rows rather than in them. A row is the owner's copy, byte for byte
 * as it was handed over, and a housekeeping field added to it would travel into the projection
 * the browser sees and into every snapshot diff — this node would then be replicating its own
 * bookkeeping as though the owner had written it.
 *
 * Marking is uniform across every RT collection and enumerates none of them: a per-collection
 * list of exceptions is the shape HIL-589 named as the cue to move to tombstones, and there is
 * nothing here that needs one.
 *
 * The store lives in BOTH processes and is read the same way in both. In the master it is filled
 * from the origin map ({@see RtReplicaOriginMap}) when a link drops; in a worker it is filled by
 * the frame the master sends. Static for the reason {@see RtSnapshot} is: there is one runtime
 * per process, and this is a fact about that process's copy of it.
 *
 * Time is measured by the receiver's clock. Node clocks are not synchronised, and the reader's
 * question is "how long have I been unable to hear about this", not "when did the owner last
 * write it".
 */
final class RtStaleness
{
    /** @var array<string, array<string, float>> [collectionKey => [stateId => frozen since]] */
    private static array $frozen = [];

    /**
     * Marks the named rows of a collection as frozen since a moment.
     *
     * The earliest mark on a row wins: a row already frozen stays frozen from when it first
     * was, because that is when the reader stopped hearing about it. A second link dropping
     * cannot make a row younger.
     *
     * @param string $collectionKey RT collection the rows belong to
     * @param list<string> $stateIds Rows whose source became unreachable
     * @param float $since Microtime of the receiver's clock when it did
     */
    public static function mark(string $collectionKey, array $stateIds, float $since): void
    {
        foreach ($stateIds as $stateId) {
            $held = self::$frozen[$collectionKey][$stateId] ?? null;
            if ($held === null || $since < $held) {
                self::$frozen[$collectionKey][$stateId] = $since;
            }
        }
    }

    /**
     * Drops the mark from the named rows, because their source is reachable again.
     *
     * @param string $collectionKey RT collection the rows belong to
     * @param list<string> $stateIds Rows that are current again
     */
    public static function clear(string $collectionKey, array $stateIds): void
    {
        foreach ($stateIds as $stateId) {
            unset(self::$frozen[$collectionKey][$stateId]);
        }
        if ((self::$frozen[$collectionKey] ?? []) === []) {
            unset(self::$frozen[$collectionKey]);
        }
    }

    /**
     * Since when a row, or anything in a collection, has been frozen.
     *
     * Asked without a row the question is about the collection, and the answer is the EARLIEST
     * moment among its frozen rows: what a reader of the collection wants to know is how old the
     * oldest thing it is being shown may be.
     *
     * @param string $collectionKey RT collection to ask about
     * @param ?string $stateId Row to narrow the question to, or null to ask about the collection
     * @return ?float Microtime the copy froze at, or null when it is fresh
     */
    public static function staleSince(string $collectionKey, ?string $stateId = null): ?float
    {
        $rows = self::$frozen[$collectionKey] ?? [];
        if ($stateId !== null) {
            return $rows[$stateId] ?? null;
        }

        return $rows === [] ? null : min($rows);
    }

    /**
     * Every frozen row of one collection, and when each froze.
     *
     * @param string $collectionKey RT collection to ask about
     * @return array<string, float> Frozen rows by state id; empty when none of it is frozen
     */
    public static function staleRows(string $collectionKey): array
    {
        return self::$frozen[$collectionKey] ?? [];
    }

    /**
     * Every collection holding something frozen, and when the oldest of it froze.
     *
     * @return array<string, float> Earliest frozen moment by RT collection
     */
    public static function staleCollections(): array
    {
        $collections = [];
        foreach (self::$frozen as $collectionKey => $rows) {
            if ($rows !== []) {
                $collections[$collectionKey] = min($rows);
            }
        }

        return $collections;
    }

    /**
     * Forgets every mark this process holds.
     *
     * For a process starting over, and for the tests around it: process-wide state outlives a
     * case, so without this one case's marks would be read by the next.
     */
    public static function reset(): void
    {
        self::$frozen = [];
    }
}
