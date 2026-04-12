<?php

declare(strict_types=1);

namespace Hilos\Database;

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
     * Apply DB sync created: create Object from row and add to collection.
     *
     * @param array<string, mixed> $signalData collectionKey, idString, row
     */
    public static function applyCreated(array $signalData): void
    {
        $collectionKey = $signalData['collectionKey'] ?? '';
        $idString = $signalData['idString'] ?? '';
        $row = $signalData['row'] ?? [];

        if ($collectionKey === '' || $idString === '' || $row === []) {
            return;
        }

        if (Hilos::$sr !== null && Hilos::$sr->shouldSkipDbSyncApply($collectionKey, $idString)) {
            return;
        }

        $collection = Hilos::$db->getObjectCollection($collectionKey);
        if (!$collection instanceof Objects) {
            return;
        }

        $objectClass = $collection::OBJECT_CLASS;
        if ($objectClass === '' || !is_subclass_of($objectClass, Object_::class)) {
            return;
        }

        /** @var class-string<Entity> $entityClass */
        $entityClass = $objectClass::ENTITY_CLASS;
        if ($entityClass === '' || !is_subclass_of($entityClass, Entity::class)) {
            return;
        }

        if (isset($collection[$idString])) {
            return;
        }

        $entity = $entityClass::fromRow($row);
        $object = $objectClass::fromEntity($entity);
        $collection[$idString] = $object;
    }

    /**
     * Apply DB sync updated: find Object by idString, merge diff into wrapped Entity.
     * Row keys are entity column names (same as DB_SYNC_CREATED / fromRow).
     *
     * @param array<string, mixed> $signalData collectionKey, idString, row (diff)
     */
    public static function applyUpdated(array $signalData): void
    {
        $collectionKey = $signalData['collectionKey'] ?? '';
        $idString = $signalData['idString'] ?? '';
        $row = $signalData['row'] ?? [];

        if ($collectionKey === '' || $idString === '' || $row === []) {
            return;
        }

        if (Hilos::$sr !== null && Hilos::$sr->shouldSkipDbSyncApply($collectionKey, $idString)) {
            return;
        }

        $collection = Hilos::$db->getObjectCollection($collectionKey);
        if (!$collection instanceof Objects) {
            return;
        }

        $object = $collection[$idString] ?? null;
        if (!$object instanceof Object_) {
            return;
        }

        $object->applyDbSyncEntityUpdate($row);
    }

    /**
     * Apply DB sync deleted: remove Object by idString from collection.
     *
     * @param array<string, mixed> $signalData collectionKey, idString
     */
    public static function applyDeleted(array $signalData): void
    {
        $collectionKey = $signalData['collectionKey'] ?? '';
        $idString = $signalData['idString'] ?? '';

        if ($collectionKey === '' || $idString === '') {
            return;
        }

        if (Hilos::$sr !== null && Hilos::$sr->shouldSkipDbSyncApply($collectionKey, $idString)) {
            return;
        }

        $collection = Hilos::$db->getObjectCollection($collectionKey);
        if (!$collection instanceof Objects) {
            return;
        }

        if (isset($collection[$idString])) {
            unset($collection[$idString]);
        }
    }
}
