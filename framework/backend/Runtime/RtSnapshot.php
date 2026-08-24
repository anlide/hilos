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
     * @throws HilosException Whatever a subscriber to the collection's announcement raises
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
