<?php

declare(strict_types=1);

namespace Hilos\Runtime;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions;
use Hilos\Utils\Logger;

/**
 * Reads and replaces a whole RT collection, for the node-to-node hand-over of one.
 *
 * A delta says what changed; this says what everything is, which is what a node joining the
 * mesh needs — it has no history to apply deltas to. Both directions live here, and here
 * only, because reaching backing RT state is allowed under `Runtime/` and nowhere else
 * (RT-STATE-REACH); the daemon asks this class rather than the collection.
 *
 * Replacing is not merging: the owner's copy is the whole truth about its collection, so a
 * row the snapshot does not carry is a row that no longer exists. There is no arbitration in
 * the model to decide otherwise — see {@see RtSyncApplicator} for the per-row path.
 */
final class RtSnapshot
{
    /**
     * Which of the given state ids this process actually holds a row for.
     *
     * The presence half of {@see self::rows()}, and separate from it because the caller that
     * needs presence runs in the master on every pass: reading rows to learn what exists would
     * serialise the whole collection to answer a question about a handful of keys. Asked here
     * rather than of the collection for the reason the whole class exists — reaching backing RT
     * state is allowed under `Runtime/` and nowhere else (RT-STATE-REACH).
     *
     * @param string $collectionKey RT collection to ask about
     * @param list<string> $stateIds State ids to look for
     * @return list<string> Those of them this process holds, in the order they were given
     */
    public static function heldKeys(string $collectionKey, array $stateIds): array
    {
        $stateCollection = Hilos::$rt?->getStateCollection($collectionKey);
        if ($stateCollection === null) {
            return [];
        }

        return array_values(array_filter($stateIds, $stateCollection->has(...)));
    }

    /**
     * Reads a whole RT collection as rows, keyed by state id.
     *
     * @param string $collectionKey RT collection to read
     * @return array<string, array<string, mixed>> Rows by state id; empty when this process has
     *     no such collection
     */
    public static function rows(string $collectionKey): array
    {
        $stateCollection = Hilos::$rt?->getStateCollection($collectionKey);
        if ($stateCollection === null) {
            $state = Hilos::$rt?->getStateItem($collectionKey);

            return $state === null ? [] : [$state->getId() => $state->toArray()];
        }

        $rows = [];
        foreach ($stateCollection->toArray() as $stateId => $row) {
            $rows[(string)$stateId] = $row;
        }

        return $rows;
    }

    /**
     * Replaces a whole RT collection with the rows another node handed over.
     *
     * A row the receiving side refuses costs exactly that row, for the reason
     * {@see RtSyncApplicator::applyCreated()} traps the same refusal: the loop around this call
     * catches its own errors only, and one malformed row must not take the process down. What
     * that costs is honest and worth naming — the collection is then a replacement missing a
     * row, which is why the refusal is logged rather than counted.
     *
     * The rows are written as applied-remote, for the reason
     * {@see RtSyncApplicator::applyCreated()} marks its own write that way: this is the owner's
     * copy of the collection, and announcing it as local would send every row of a hand-over
     * straight back into the mesh.
     *
     * The view's wrappers are dropped by hand right after the clear, the same cure
     * {@see RtActions::clearAllStates()} applies on its own road: a mass clear announces
     * nothing, so nothing else would empty a cache that answers keys the collection no longer
     * holds. The whole cache and not a list of keys, because the keys that need repairing are
     * exactly the ones this snapshot does NOT carry — nothing below walks those, and they are
     * what the hand-over is silently dropping.
     *
     * @param string $collectionKey RT collection to replace
     * @param array<string, array<string, mixed>> $rows Rows by state id, as the owner holds them
     * @throws HilosException Whatever the applied write of the snapshot rows raises
     */
    public static function replace(string $collectionKey, array $rows): void
    {
        $stateCollection = Hilos::$rt?->getStateCollection($collectionKey);
        if ($stateCollection === null) {
            self::replaceStandaloneItem($collectionKey, $rows);

            return;
        }

        $stateClass = $stateCollection::STATE_CLASS;
        if (!is_subclass_of($stateClass, RtState::class)) {
            return;
        }

        $stateCollection->clear();
        Hilos::$rt?->getRtCollection($collectionKey)?->clearCache();

        /** @var class-string<RtState> $stateClass */
        SourceChangeBus::whileApplyingRemote(
            static function () use ($collectionKey, $rows, $stateClass, $stateCollection): void {
                foreach ($rows as $stateId => $row) {
                    try {
                        $stateCollection->add($stateClass::fromRow($row));
                    } catch (InvalidFormatException $e) {
                        Logger::warning(
                            'RT snapshot row refused for collection ' . $collectionKey
                            . ' id ' . $stateId . ': ' . $e->getMessage(),
                        );
                    }
                }
            },
        );
    }

    /**
     * Replaces only the named rows of an RT collection with the ones their owner handed over.
     *
     * The narrow twin of {@see replace()}, for a node that owns entities rather than the
     * collection around them (HIL-589): what it knows to be the whole truth is those rows, so
     * those rows are all it may speak for. Inside the scope the rule is the one above — a row
     * of the scope the frame does not carry is a row that no longer exists — and outside it
     * nothing is touched, because the rows another node writes are none of this frame's
     * business and clearing them is exactly the split that would follow.
     *
     * A row the frame carries outside its own scope is DROPPED. The scope is the authority the
     * sender claims, and a row beyond it is one the sender does not speak for - taking it would
     * let a frame reach past the very thing the receiver judged it by, and overwrite a row this
     * node owns. A malformed row inside the scope costs that row and is logged, the same bargain
     * {@see replace()} strikes and for the same reason.
     *
     * The deletions run inside the applied-remote window along with the writes, and unlike
     * {@see replace()} they HAVE to: that one empties the collection with a clear, which
     * announces nothing, while a scoped sweep removes rows one by one and every removal is an
     * announcement. Made as a local write it would go straight back onto the mesh as this
     * node's own delta about a row it does not own — the echo, arriving as a deletion.
     *
     * The whole wrapper cache is dropped rather than the scope's keys, again as in
     * {@see replace()}: the wrappers that need repairing are the ones for rows this frame
     * DELETED, and those are precisely the keys nothing below walks.
     *
     * @param string $collectionKey RT collection to replace within
     * @param list<string> $scopeKeys Rows this snapshot speaks for
     * @param array<string, array<string, mixed>> $rows Rows by state id, as the owner holds them
     * @throws HilosException Whatever the applied write of the snapshot rows raises
     */
    public static function replaceScope(string $collectionKey, array $scopeKeys, array $rows): void
    {
        $stateCollection = Hilos::$rt?->getStateCollection($collectionKey);
        if ($stateCollection === null) {
            self::replaceStandaloneItem($collectionKey, $rows);

            return;
        }

        $stateClass = $stateCollection::STATE_CLASS;
        if (!is_subclass_of($stateClass, RtState::class)) {
            return;
        }

        Hilos::$rt?->getRtCollection($collectionKey)?->clearCache();

        /** @var class-string<RtState> $stateClass */
        SourceChangeBus::whileApplyingRemote(
            static function () use ($collectionKey, $scopeKeys, $rows, $stateClass, $stateCollection): void {
                foreach ($scopeKeys as $stateId) {
                    if (!isset($rows[$stateId])) {
                        $stateCollection->remove($stateId);

                        continue;
                    }

                    try {
                        $stateCollection->add($stateClass::fromRow($rows[$stateId]));
                    } catch (InvalidFormatException $e) {
                        Logger::warning(
                            'RT snapshot row refused for collection ' . $collectionKey
                            . ' id ' . $stateId . ': ' . $e->getMessage(),
                        );
                    }
                }
            },
        );
    }

    /**
     * Writes the one row of a snapshot onto a standalone RT item.
     *
     * A truth source may be registered for a single item rather than a collection (the backup
     * runtime is one), and the per-row path already carries those; a hand-over that skipped
     * them would leave a joining node with the one shape of RT state nothing ever fills in.
     * The item itself is never created or removed here — it is mounted by the context and
     * exists on both nodes — so an empty snapshot leaves it as it was rather than clearing it,
     * and a row for another id is not this item's state and is ignored.
     *
     * @param string $collectionKey RT context key of the standalone item
     * @param array<string, array<string, mixed>> $rows Rows the owner sent, at most one of them ours
     */
    private static function replaceStandaloneItem(string $collectionKey, array $rows): void
    {
        $state = Hilos::$rt?->getStateItem($collectionKey);
        if ($state === null) {
            return;
        }

        $row = $rows[$state->getId()] ?? null;
        if ($row === null) {
            return;
        }

        try {
            $state->applyDiff($row);
        } catch (InvalidFormatException $e) {
            Logger::warning(
                'RT snapshot row refused for item ' . $collectionKey
                . ' id ' . $state->getId() . ': ' . $e->getMessage(),
            );

            return;
        }

        $state->markRtSyncBaseline();
    }
}
