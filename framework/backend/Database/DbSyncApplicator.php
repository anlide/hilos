<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Core\Sync\DTO\DbSyncClearedSignalData;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Hilos;

/**
 * Applies DB sync signals (created, updated, deleted) to Hilos::$db.
 *
 * Used by DaemonManager and WorkerManager to sync in-memory collections
 * when receiving DB_SYNC signals from other processes.
 */
final class DbSyncApplicator
{
    /**
     * @param DbSyncCreatedSignalData $data Full created row payload from another process
     * @param bool $skipSelfBroadcastCheck When true, ignores echoes of this process's own sync write
     */
    public static function applyCreated(DbSyncCreatedSignalData $data, bool $skipSelfBroadcastCheck = true): void
    {
        if (!self::shouldApplyDbSyncRow($data->collectionKey, $data->idString, $data->row, $skipSelfBroadcastCheck)) {
            return;
        }

        $collection = Hilos::$db->getObjectCollection($data->collectionKey);
        if (!$collection instanceof Objects) {
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
        $collection[$data->idString] = $object;
    }

    /**
     * Row keys are entity column names (same as DB_SYNC_CREATED / fromRow).
     *
     * @param DbSyncUpdatedSignalData $data Diff payload from another process
     * @param bool $skipSelfBroadcastCheck When true, ignores echoes of this process's own sync write
     */
    public static function applyUpdated(DbSyncUpdatedSignalData $data, bool $skipSelfBroadcastCheck = true): void
    {
        if (!self::shouldApplyDbSyncRow($data->collectionKey, $data->idString, $data->row, $skipSelfBroadcastCheck)) {
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
     * @param DbSyncDeletedSignalData $data Deleted row identity from another process
     * @param bool $skipSelfBroadcastCheck When true, ignores echoes of this process's own sync write
     */
    public static function applyDeleted(DbSyncDeletedSignalData $data, bool $skipSelfBroadcastCheck = true): void
    {
        if (!self::shouldApplyDbSync($data->collectionKey, $data->idString, $skipSelfBroadcastCheck)) {
            return;
        }

        $collection = Hilos::$db->getObjectCollection($data->collectionKey);
        if (!$collection instanceof Objects) {
            return;
        }

        if (isset($collection[$data->idString])) {
            unset($collection[$data->idString]);
        }
    }

    /**
     * Applies a whole-collection clear (truncate) from another process.
     *
     * Drops the local in-memory rows for the collection without re-running the
     * physical DELETE, which already ran in the originating process.
     *
     * @param DbSyncClearedSignalData $data Cleared collection identity from another process
     * @param bool $skipSelfBroadcastCheck When true, ignores echoes of this process's own clear
     */
    public static function applyCleared(DbSyncClearedSignalData $data, bool $skipSelfBroadcastCheck = true): void
    {
        if ($data->collectionKey === '') {
            return;
        }

        if ($skipSelfBroadcastCheck && Hilos::$sr !== null && Hilos::$sr->shouldSkipDbSyncClearApply($data->collectionKey)) {
            return;
        }

        Hilos::$db->clearCollectionInMemory($data->collectionKey);
    }

    /**
     * Re-hydrates all DB-backed collections after the DB was replaced under the live
     * process (restore fires this signal once it swapped the DB). Whole-context event
     * with no per-row payload: every collection is reset to the fresh DB so stale
     * pre-replacement rows no longer collide with freshly-minted ids.
     *
     * @throws LogicException When a represented collection entity class is not configured (eager reload)
     * @throws DatabaseException If reloading an eager collection from the fresh DB fails
     */
    public static function applyReHydrate(): void
    {
        Hilos::$db?->reHydrateDbBackedCollections();
    }

    /**
     * @param string $collectionKey DB collection key from sync payload
     * @param string $idString Target object id from sync payload
     * @param array<string, mixed> $row Full row or diff row
     * @param bool $skipSelfBroadcastCheck When true, applies self-broadcast guard from Hilos::$sr
     * @return bool Whether sync row should be applied
     */
    private static function shouldApplyDbSyncRow(
        string $collectionKey,
        string $idString,
        array $row,
        bool $skipSelfBroadcastCheck,
    ): bool {
        if ($collectionKey === '' || $idString === '' || $row === []) {
            return false;
        }

        return self::shouldApplyDbSync($collectionKey, $idString, $skipSelfBroadcastCheck);
    }

    /**
     * @param string $collectionKey DB collection key from sync payload
     * @param string $idString Target object id from sync payload
     * @param bool $skipSelfBroadcastCheck When true, applies self-broadcast guard from Hilos::$sr
     * @return bool Whether sync should be applied
     */
    private static function shouldApplyDbSync(
        string $collectionKey,
        string $idString,
        bool $skipSelfBroadcastCheck,
    ): bool {
        if ($collectionKey === '' || $idString === '') {
            return false;
        }

        if ($skipSelfBroadcastCheck && Hilos::$sr !== null && Hilos::$sr->shouldSkipDbSyncApply($collectionKey, $idString)) {
            return false;
        }

        return true;
    }
}
