<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\Database;
use Hilos\Database\DatabaseBinding;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\ChangeLogEntry as EntityChangeLogEntry;
use Hilos\Database\Entity\Item\ChangeLogReceipt as EntityChangeLogReceipt;
use Hilos\Database\Object\Collection\ChangeLogReceipts as ObjectChangeLogReceipts;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Hilos;

/**
 * @extends Object_<EntityChangeLogReceipt>
 *
 * @property-read ?int $id
 * @property string $created_at
 * @property ?int $actor_user_id
 * @property ?int $subject_user_id
 * @property ?int $session_id
 * @property string $channel
 * @property string $action
 * @property ?string $agent
 * @property ?string $source
 * @property int $open_handovers
 */
class ChangeLogReceipt extends Object_
{
    public const string ENTITY_CLASS = EntityChangeLogReceipt::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectChangeLogReceipts::class;

    public const string id = EntityChangeLogReceipt::id;
    public const string created_at = EntityChangeLogReceipt::created_at;
    public const string actor_user_id = EntityChangeLogReceipt::actor_user_id;
    public const string subject_user_id = EntityChangeLogReceipt::subject_user_id;
    public const string session_id = EntityChangeLogReceipt::session_id;
    public const string channel = EntityChangeLogReceipt::channel;
    public const string action = EntityChangeLogReceipt::action;
    public const string agent = EntityChangeLogReceipt::agent;
    public const string source = EntityChangeLogReceipt::source;
    public const string open_handovers = EntityChangeLogReceipt::open_handovers;

    /**
     * @return string Global auto-increment id, independent of the physical partition key
     * @throws ObjectGetIdStringNotImplementedException When an unsaved row has no id
     */
    public function getIdString(): string
    {
        return (string)($this->entity->id
            ?? throw new ObjectGetIdStringNotImplementedException('A change log row has no id'));
    }

    /**
     * @param string $property Column name
     * @return mixed Scalar column value
     * @throws DatabaseException When the property does not exist or cannot be accessed
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::created_at => $this->entity->created_at,
            self::actor_user_id => $this->entity->actor_user_id,
            self::subject_user_id => $this->entity->subject_user_id,
            self::session_id => $this->entity->session_id,
            self::channel => $this->entity->channel,
            self::action => $this->entity->action,
            self::agent => $this->entity->agent,
            self::source => $this->entity->source,
            self::open_handovers => $this->entity->open_handovers,
            default => parent::__get($property),
        };
    }

    /**
     * @param string $property Column name
     * @param mixed $value New scalar value
     * @throws DatabaseException When the property cannot be written
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::created_at => $this->entity->created_at = (string)$value,
            self::actor_user_id => $this->entity->actor_user_id = $value === null ? null : (int)$value,
            self::subject_user_id => $this->entity->subject_user_id = $value === null ? null : (int)$value,
            self::session_id => $this->entity->session_id = $value === null ? null : (int)$value,
            self::channel => $this->entity->channel = (string)$value,
            self::action => $this->entity->action = (string)$value,
            self::agent => $this->entity->agent = $value === null ? null : (string)$value,
            self::source => $this->entity->source = $value === null ? null : (string)$value,
            self::open_handovers => $this->entity->open_handovers = (int)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Applies a stored insert this collection already wrote, and announces the new row.
     *
     * The snapshot is the row read back after the insert, so created_at is the database clock.
     * No second write is issued. A later rollback cuts the tie to the row and leaves the values.
     *
     * @param array<string, mixed> $row Stored receipt columns, open_handovers included
     * @throws DatabaseException When the binding cannot be resolved or the created sync cannot be queued
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     * @throws ObjectGetIdStringNotImplementedException When the stored row has no id
     */
    public function acceptStoredInsert(array $row): void
    {
        $index = $this->entity::getDatabase()->connectionIndex;
        $stored = (static::ENTITY_CLASS)::fromRow($row)->toArray();
        $this->restoreSaved($stored, related: true);
        Database::onRollback(function () use ($stored): void {
            $this->restoreSaved($stored, related: false);
        }, index: $index);
        $this->broadcastDbSyncAfterSync(true, $stored, []);
    }

    /**
     * Adds a delta to the durable handover counter.
     *
     * The counter changes in one statement on the row connection. A zero delta does nothing.
     * A missing row is left as it is. The in-memory counter is not written back.
     *
     * @param int $delta Handovers to add, negative to release
     * @throws DatabaseException When the update, the refresh or the announcement fails
     * @throws WriteNotAllowedException When no truth source in this process may update the row
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     * @throws ObjectGetIdStringNotImplementedException When the persisted row has no id
     */
    public function adjustHandovers(int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $binding = $this->persistedBinding(TruthSourceOperation::Update);
        if ($binding === null) {
            return;
        }

        $this->runHandoverUpdate($binding, $this->counterSql($binding), [
            $delta,
            $this->entity->id,
            $this->entity->created_at,
        ]);
    }

    /**
     * Deletes this receipt when it has no open handovers and no journal rows.
     *
     * One conditional delete. A missing row or a delete that matches nothing is left unpublished.
     *
     * @throws DatabaseException When the delete or its announcement fails
     * @throws WriteNotAllowedException When no truth source in this process may remove the row
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     * @throws ObjectGetIdStringNotImplementedException When the persisted row has no id
     */
    public function deleteIfEmpty(): void
    {
        $this->deleteWhenEmpty(requireClosedHandovers: true);
    }

    /**
     * Deletes an empty migration receipt without reading the handover column.
     *
     * Migration does not keep a local hold, and the column may not exist yet. Any other channel
     * is refused. A missing row or a delete that matches nothing is left unpublished.
     *
     * @throws LogicException When the receipt channel is not migration
     * @throws DatabaseException When the delete or its announcement fails
     * @throws WriteNotAllowedException When no truth source in this process may remove the row
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     * @throws ObjectGetIdStringNotImplementedException When the persisted row has no id
     */
    public function deleteEmptyMigrationReceipt(): void
    {
        if ($this->isRelated() && $this->entity->channel !== JournalReceiptData::CHANNEL_MIGRATION) {
            throw new LogicException('Migration receipt cleanup refuses channel ' . $this->entity->channel);
        }

        $this->deleteWhenEmpty(requireClosedHandovers: false);
    }

    /**
     * Core columns a receipt insert may read back. The handover column is included only when
     * the statement wrote it, so a migration that predates the column does not select it.
     *
     * @param bool $includeHandovers Whether the handover column is part of the projection
     * @return string Comma-separated quoted core columns
     */
    public static function columnProjection(bool $includeHandovers): string
    {
        $entityClass = static::ENTITY_CLASS;
        $columns = [
            $entityClass::id,
            $entityClass::created_at,
            $entityClass::actor_user_id,
            $entityClass::subject_user_id,
            $entityClass::session_id,
            $entityClass::channel,
            $entityClass::action,
            $entityClass::agent,
            $entityClass::source,
        ];
        if ($includeHandovers) {
            $columns[] = $entityClass::open_handovers;
        }
        $quoted = [];
        foreach ($columns as $column) {
            $quoted[] = '`' . $column . '`';
        }

        return implode(', ', $quoted);
    }

    /**
     * @param TruthSourceOperation $operation Update or remove on the persisted row
     * @return ?DatabaseBinding Null when this object is not a stored row
     * @throws DatabaseException When the binding cannot be resolved or a foreign transaction forbids the write
     * @throws WriteNotAllowedException When no truth source in this process may perform the operation
     * @throws ObjectGetIdStringNotImplementedException When the persisted row has no id
     */
    private function persistedBinding(TruthSourceOperation $operation): ?DatabaseBinding
    {
        if (!$this->isRelated() || $this->entity->id === null) {
            return null;
        }

        $binding = $this->entity::getDatabase();
        Database::assertCanWrite($binding->connectionIndex);
        DbWriteGuard::guardItemWrite(
            static::getCollectionKey(),
            $this->getIdString(),
            $this->touchedSetKeys(...),
            $operation,
        );

        return $binding;
    }

    /**
     * @param DatabaseBinding $binding Receipt binding the statement runs on
     * @return string Update of the handover counter by the persisted key
     * @throws DatabaseException When the receipt table name cannot be resolved
     */
    private function counterSql(DatabaseBinding $binding): string
    {
        $entityClass = static::ENTITY_CLASS;
        $table = $binding->getSqlTableName($entityClass::_table);

        return 'UPDATE ' . $table
            . ' SET `' . $entityClass::open_handovers . '` = `' . $entityClass::open_handovers . '` + ?'
            . ' WHERE `' . $entityClass::id . '` = ? AND `' . $entityClass::created_at . '` = ?';
    }

    /**
     * Runs one non-idempotent receipt update and refreshes this object from the row it left.
     *
     * A lost update is not sent again. No affected row is not an insert and not an announcement.
     * The statement's values are the only ones written; the previous snapshot is not.
     *
     * @param DatabaseBinding $binding Receipt binding already allowed to update
     * @param string $sql Update bound to this receipt
     * @param array<int, mixed> $params Bound parameters of that update
     * @throws DatabaseException When the update, the refresh or the announcement fails
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     * @throws ObjectGetIdStringNotImplementedException When the persisted row has no id
     */
    private function runHandoverUpdate(DatabaseBinding $binding, string $sql, array $params): void
    {
        $before = $this->entitySync->toArray();
        Database::sql($sql, $params, tryReconnect: false, index: $binding->connectionIndex);
        if (Database::affectedRows($binding->connectionIndex) < 1) {
            return;
        }

        $this->refreshFromSnapshot($binding, $before);
    }

    /**
     * @param DatabaseBinding $binding Receipt binding the update used
     * @param array<string, mixed> $before Values this object last saved
     * @throws DatabaseException When the snapshot or the announcement fails
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     * @throws ObjectGetIdStringNotImplementedException When the refreshed row has no id
     */
    private function refreshFromSnapshot(DatabaseBinding $binding, array $before): void
    {
        $row = $this->readPersistedSnapshot($binding);
        if ($row === null) {
            return;
        }

        $after = (static::ENTITY_CLASS)::fromRow($row)->toArray();
        $diff = [];
        $previous = [];
        foreach ($after as $column => $value) {
            $prior = $before[$column] ?? null;
            if ($value !== $prior) {
                $diff[$column] = $value;
                $previous[$column] = $prior;
            }
        }
        $this->restoreSaved($after, related: true);
        if ($diff === []) {
            return;
        }

        Database::onRollback(function () use ($before): void {
            $this->restoreSaved($before, related: true);
        }, index: $binding->connectionIndex);
        $this->broadcastDbSyncAfterSync(false, $diff, $previous);
    }

    /**
     * @param DatabaseBinding $binding Receipt binding
     * @return ?array<string, mixed> Stored core columns, or null when the row is gone
     * @throws DatabaseException When the snapshot query fails
     */
    private function readPersistedSnapshot(DatabaseBinding $binding): ?array
    {
        $entityClass = static::ENTITY_CLASS;
        $table = $binding->getSqlTableName($entityClass::_table);
        $rows = Database::sql(
            'SELECT ' . self::columnProjection(true) . ' FROM ' . $table
            . ' WHERE `' . $entityClass::id . '` = ? AND `' . $entityClass::created_at . '` = ?',
            [$this->entity->id, $this->entity->created_at],
            index: $binding->connectionIndex,
        )->rows();

        return $rows[0] ?? null;
    }

    /**
     * @param bool $requireClosedHandovers Whether the delete also requires a zero handover counter
     * @throws DatabaseException When the delete or its announcement fails
     * @throws WriteNotAllowedException When no truth source in this process may remove the row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     * @throws ObjectGetIdStringNotImplementedException When the persisted row has no id
     */
    private function deleteWhenEmpty(bool $requireClosedHandovers): void
    {
        $binding = $this->persistedBinding(TruthSourceOperation::Remove);
        if ($binding === null) {
            return;
        }

        $entityClass = static::ENTITY_CLASS;
        $table = $binding->getSqlTableName($entityClass::_table);
        $entries = $binding->getSqlTableName(EntityChangeLogEntry::_table);
        $sql = 'DELETE FROM ' . $table
            . ' WHERE `' . $entityClass::id . '` = ? AND `' . $entityClass::created_at . '` = ?';
        $params = [$this->entity->id, $this->entity->created_at];
        if ($requireClosedHandovers) {
            $sql .= ' AND `' . $entityClass::open_handovers . '` = 0';
        }
        $sql .= ' AND NOT EXISTS (SELECT 1 FROM ' . $entries
            . ' WHERE `' . EntityChangeLogEntry::receipt_id . '` = ?)';
        $params[] = $this->entity->id;

        $stored = $this->entitySync->toArray();
        $row = $this->toArray();
        $idString = $this->getIdString();
        Database::sql($sql, $params, index: $binding->connectionIndex);
        if (Database::affectedRows($binding->connectionIndex) < 1) {
            return;
        }

        Database::onRollback(function () use ($stored): void {
            $this->restoreSaved($stored, related: true);
        }, index: $binding->connectionIndex);
        $this->restoreSaved($stored, related: false);
        $collectionKey = static::getCollectionKey();
        if ($collectionKey === '') {
            return;
        }

        $this->queueDbSyncDeleted($collectionKey, $idString, $row);
        $objects = Hilos::$db?->mountedObjectCollection($collectionKey);
        if ($objects !== null) {
            unset($objects[$idString]);

            return;
        }

        // No mounted store (early migration). Same fact a cacheless offsetUnset would publish.
        SourceChangeBus::publish(SourceChange::dbDeleted(
            $collectionKey,
            $idString,
            [],
            ExecutionContext::currentAcceptKey(),
            ExecutionContext::currentRequestId(),
        ), connectionIndex: $binding->connectionIndex);
    }
}
