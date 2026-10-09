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
     * serialize the whole collection to answer a question about a handful of keys. Asked here
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
     * Reads the rows of some sets of an RT collection, keyed by state id.
     *
     * What a node owning a set hands over (HIL-1116): not the collection but the rows of its set
     * it holds right now. The field a row is cut by is named by the row class
     * ({@see RtState::SET_VIA}), and a row belongs to a set when that field, read as a string,
     * is the set's key. Here and not in the daemon for the reason the whole class exists.
     *
     * @param string $collectionKey RT collection to read
     * @param list<string> $setKeys Set keys whose rows to read
     * @return array<string, array<string, mixed>> Rows by state id whose set key is one of those, empty when the
     *     collection is cut by no field
     */
    public static function setRows(string $collectionKey, array $setKeys): array
    {
        $field = self::setFieldOf($collectionKey);
        if ($field === null || $setKeys === []) {
            return [];
        }

        return array_filter(
            self::rows($collectionKey),
            static fn (array $row): bool => in_array(self::setKeyIn($row, $field), $setKeys, true),
        );
    }

    /**
     * Names the set one row of an RT collection is in.
     *
     * Asked of a row that came over the wire as an array, where no row object stands to ask
     * {@see RtState::touchedSetKeys()}; the value is read the way that method reads it.
     *
     * @param string $collectionKey RT collection the row belongs to
     * @param array<string, mixed> $row Row as the wire carries it
     * @return ?string Set key of the row, null when the row is in nobody's set or the collection is cut by no field
     */
    public static function setKeyOfRow(string $collectionKey, array $row): ?string
    {
        $field = self::setFieldOf($collectionKey);

        return $field === null ? null : self::setKeyIn($row, $field);
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
     * collection around them (HIL-589). The daemon passes only affected row ids: every carried
     * row and every missing row it proved came from this sender within the sender's claim
     * (HIL-1178). This method replaces those ids and leaves every other row untouched.
     *
     * A row the frame carries outside these affected ids is DROPPED. The daemon filters them
     * against the declared claim before calling here, so an extra row cannot reach past its
     * ownership check. A malformed row inside the affected ids costs that row and is logged,
     * the same bargain {@see replace()} strikes and for the same reason.
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
     * @param list<string> $scopeKeys Concrete row ids to replace or remove
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

    /**
     * Names the field the rows of a mounted state collection are cut into sets by.
     *
     * @param string $collectionKey RT collection to ask about
     * @return ?string Field its row class names as {@see RtState::SET_VIA}, null when no state collection is
     *     mounted under the key or its row class names no field
     */
    private static function setFieldOf(string $collectionKey): ?string
    {
        $stateClass = Hilos::$rt?->stateClassOf($collectionKey);
        if ($stateClass === null || !is_subclass_of($stateClass, RtState::class) || $stateClass::SET_VIA === '') {
            return null;
        }

        return $stateClass::SET_VIA;
    }

    /**
     * Reads the set key of one row, as {@see RtState::touchedSetKeys()} reads it: a scalar as a string.
     *
     * @param array<string, mixed> $row Row to read
     * @param string $field Field the rows are cut by
     * @return ?string Set key of the row, null when the field is absent, null or not a scalar
     */
    private static function setKeyIn(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;

        return is_scalar($value) ? (string)$value : null;
    }
}
