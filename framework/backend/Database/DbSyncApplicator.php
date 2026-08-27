<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Sync\DTO\DbSyncClearedSignalData;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Utils\Logger;

/**
 * Applies DB sync signals (created, updated, deleted) to Hilos::$db.
 *
 * Used by DaemonManager and WorkerManager to sync in-memory collections
 * when receiving DB_SYNC signals from other processes.
 */
final class DbSyncApplicator
{
    /**
     * The write is marked as applied-remote, so the announcement the mirror makes repairs the
     * local views and stops there instead of being handed back to whoever sent it.
     *
     * A row created on ANOTHER NODE is the one fact this process may decline (HIL-670). It takes
     * it only if its own copy of the collection claims to hold the whole set
     * ({@see Objects::isAllLoaded()}): such a copy is what a list is drawn from, and leaving the
     * new row out would make that list lie. A lazy copy holds the rows somebody asked for, and a
     * row nobody has asked for is exactly what it is entitled not to hold - taking it would put
     * every row created anywhere in the cluster into every process's memory, which is the cost
     * lazy loading exists to avoid. Nothing is lost either way: the row is in the database, and
     * a later read fetches it.
     *
     * A row created on THIS node is taken as it always was. The two are not the same question:
     * something in this process just wrote that row, and it is about to be read.
     *
     * @param DbSyncCreatedSignalData $data Full created row payload from another process
     * @param bool $skipSelfBroadcastCheck When true, ignores echoes of this process's own sync write
     * @param ?string $originNodeId Node the write happened on, or null when it was this one
     * @throws HilosException Whatever a subscriber to the mirror's announcement raises
     */
    public static function applyCreated(
        DbSyncCreatedSignalData $data,
        bool $skipSelfBroadcastCheck = true,
        ?string $originNodeId = null,
    ): void {
        if (
            !self::shouldApplyDbSyncRow(
                $data->collectionKey,
                $data->idString,
                $data->row,
                $data->emitter,
                $skipSelfBroadcastCheck,
            )
        ) {
            return;
        }

        $collection = Hilos::$db->getObjectCollection($data->collectionKey);
        if (!$collection instanceof Objects) {
            return;
        }

        if ($originNodeId !== null && !$collection->isAllLoaded()) {
            return;
        }

        $objectClass = $collection::OBJECT_CLASS;
        if (!is_subclass_of($objectClass, Object_::class)) {
            return;
        }

        /** @var class-string<Entity> $entityClass */
        $entityClass = $objectClass::ENTITY_CLASS;
        if (!is_subclass_of($entityClass, Entity::class)) {
            return;
        }

        if (isset($collection[$data->idString])) {
            return;
        }

        $entity = $entityClass::fromRow($data->row);
        $object = $objectClass::fromEntity($entity);
        SourceChangeBus::whileApplyingRemote(static function () use ($collection, $data, $object): void {
            $collection[$data->idString] = $object;
        });
    }

    /**
     * Row keys are entity column names (same as DB_SYNC_CREATED / fromRow).
     *
     * The origin is taken and not used, and that is the point rather than an oversight: a change
     * lands on a row this process is already holding, and one it is not holding it ignores - so
     * where the change was made makes no difference to what happens here (HIL-670). The
     * parameter is present so the four arms read alike and a caller cannot pass the origin to
     * some of them and forget the rest.
     *
     * @param DbSyncUpdatedSignalData $data Diff payload from another process
     * @param bool $skipSelfBroadcastCheck When true, ignores echoes of this process's own sync write
     * @param ?string $originNodeId Node the write happened on, or null when it was this one
     */
    public static function applyUpdated(
        DbSyncUpdatedSignalData $data,
        bool $skipSelfBroadcastCheck = true,
        ?string $originNodeId = null,
    ): void {
        if (
            !self::shouldApplyDbSyncRow(
                $data->collectionKey,
                $data->idString,
                $data->row,
                $data->emitter,
                $skipSelfBroadcastCheck,
            )
        ) {
            return;
        }

        $collection = Hilos::$db->getObjectCollection($data->collectionKey);
        if (!$collection instanceof Objects) {
            return;
        }

        $object = $collection[$data->idString] ?? null;
        if (!$object instanceof Object_) {
            return;
        }

        $object->applyDbSyncEntityUpdate($data->row);
    }

    /**
     * Applied-remote for the same reason as {@see self::applyCreated()}: the removal repairs the
     * local views without being announced back to the process that sent it.
     *
     * The origin is taken and not used, for the reason given on {@see self::applyUpdated()}: a
     * removal reaches a row this process holds or none at all.
     *
     * @param DbSyncDeletedSignalData $data Deleted row identity from another process
     * @param bool $skipSelfBroadcastCheck When true, ignores echoes of this process's own sync write
     * @param ?string $originNodeId Node the write happened on, or null when it was this one
     * @throws HilosException Whatever a subscriber to the mirror's announcement raises
     */
    public static function applyDeleted(
        DbSyncDeletedSignalData $data,
        bool $skipSelfBroadcastCheck = true,
        ?string $originNodeId = null,
    ): void {
        if (!self::shouldApplyDbSync($data->collectionKey, $data->idString, $data->emitter, $skipSelfBroadcastCheck)) {
            return;
        }

        $collection = Hilos::$db->getObjectCollection($data->collectionKey);
        if (!$collection instanceof Objects) {
            return;
        }

        if (isset($collection[$data->idString])) {
            SourceChangeBus::whileApplyingRemote(static function () use ($collection, $data): void {
                unset($collection[$data->idString]);
            });
        }
    }

    /**
     * Applies a whole-collection clear (truncate) from another process.
     *
     * Re-reads the collection instead of blanking it: the physical DELETE already ran
     * in the originating process, so after a legitimate clear the re-read is empty and
     * equivalent, while a clear applied twice converges on the current table instead of
     * pinning an empty mirror over rows written since. Echoes of this process's own
     * clear are recognized by the emitter identity in the payload.
     *
     * A failed re-read is logged rather than propagated: this runs inside the worker
     * message loop and the daemon signal loop, neither of which catches, so a database
     * hiccup during someone else's truncate would take the process down together with
     * its agents and connections. The collection is left marked for re-read, so the next
     * access retries the load instead of trusting an empty mirror.
     *
     * The origin is taken and not used, for the reason given on {@see self::applyUpdated()}: a
     * clear names no row, and re-reading the collection is right wherever the truncate ran.
     *
     * @param DbSyncClearedSignalData $data Cleared collection identity from another process
     * @param bool $skipSelfBroadcastCheck When true, ignores echoes of this process's own clear
     * @param ?string $originNodeId Node the clear happened on, or null when it was this one
     */
    public static function applyCleared(
        DbSyncClearedSignalData $data,
        bool $skipSelfBroadcastCheck = true,
        ?string $originNodeId = null,
    ): void {
        if ($data->collectionKey === '') {
            return;
        }

        if ($skipSelfBroadcastCheck && Hilos::$sr !== null && Hilos::$sr->shouldSkipDbSyncClearApply($data->emitter)) {
            return;
        }

        try {
            Hilos::$db->reHydrateCollection($data->collectionKey);
        } catch (DatabaseException | LogicException $e) {
            Logger::error('DB clear apply could not re-read the collection', [
                'collectionKey' => $data->collectionKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Re-hydrates all DB-backed collections after the DB was replaced under the live
     * process (restore fires this signal once it swapped the DB). Whole-context event
     * with no per-row payload: every collection is reset to the fresh DB so stale
     * pre-replacement rows no longer collide with freshly-minted ids.
     *
     * @throws LogicException When a represented collection entity class is not configured (eager reload)
     * @throws DatabaseException If reloading an eager collection from the fresh DB fails
     * @throws HilosException When the concrete collection refuses to be loaded directly
     */
    public static function applyReHydrate(): void
    {
        Hilos::$db?->reHydrateDbBackedCollections();
    }

    /**
     * @param string $collectionKey DB collection key from sync payload
     * @param string $idString Target object id from sync payload
     * @param array<string, mixed> $row Full row or diff row
     * @param ?string $emitter Emitter identity from sync payload, null when unstamped
     * @param bool $skipSelfBroadcastCheck When true, applies self-broadcast guard from Hilos::$sr
     * @return bool Whether sync row should be applied
     */
    private static function shouldApplyDbSyncRow(
        string $collectionKey,
        string $idString,
        array $row,
        ?string $emitter,
        bool $skipSelfBroadcastCheck,
    ): bool {
        if ($collectionKey === '' || $idString === '' || $row === []) {
            return false;
        }

        return self::shouldApplyDbSync($collectionKey, $idString, $emitter, $skipSelfBroadcastCheck);
    }

    /**
     * The emitter stamp travels down here rather than being read off the registry alone:
     * a row this process is awaiting an echo for can also be written by someone else, and
     * only the stamp separates the two.
     *
     * @param string $collectionKey DB collection key from sync payload
     * @param string $idString Target object id from sync payload
     * @param ?string $emitter Emitter identity from sync payload, null when unstamped
     * @param bool $skipSelfBroadcastCheck When true, applies self-broadcast guard from Hilos::$sr
     * @return bool Whether sync should be applied
     */
    private static function shouldApplyDbSync(
        string $collectionKey,
        string $idString,
        ?string $emitter,
        bool $skipSelfBroadcastCheck,
    ): bool {
        if ($collectionKey === '' || $idString === '') {
            return false;
        }

        if (
            $skipSelfBroadcastCheck
            && Hilos::$sr !== null
            && Hilos::$sr->shouldSkipDbSyncApply($collectionKey, $idString, $emitter)
        ) {
            return false;
        }

        return true;
    }
}
