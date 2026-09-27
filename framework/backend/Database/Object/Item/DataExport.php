<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\DataExport\DataExportState;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\DataExport as EntityDataExport;
use Hilos\HilosException;

/**
 * The durable queue row; completion is conditional on the original request still preparing.
 *
 * @extends Object_<EntityDataExport>
 * @property ?int $id
 * @property int $userId
 * @property string $state
 * @property string $requestedAt
 * @property ?string $finishedAt
 * @property ?string $expiresAt
 * @property ?string $storedName
 * @property ?int $sizeBytes
 */
class DataExport extends Object_
{
    public const string ENTITY_CLASS = EntityDataExport::class;
    public const string id = 'id';
    public const string userId = 'userId';
    public const string state = 'state';
    public const string requestedAt = 'requestedAt';
    public const string finishedAt = 'finishedAt';
    public const string expiresAt = 'expiresAt';
    public const string storedName = 'storedName';
    public const string sizeBytes = 'sizeBytes';

    /**
     * @param string $property Row property
     * @return mixed Persisted scalar
     * @throws DatabaseException When the property is unknown
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::state => $this->entity->state,
            self::requestedAt => $this->entity->requested_at,
            self::finishedAt => $this->entity->finished_at,
            self::expiresAt => $this->entity->expires_at,
            self::storedName => $this->entity->stored_name,
            self::sizeBytes => $this->entity->size_bytes,
            default => parent::__get($property),
        };
    }

    /**
     * Only the initial request fields are assigned directly; completion uses a conditional write.
     *
     * @param string $property Initial request property
     * @param mixed $value Persisted scalar
     * @throws DatabaseException When the property cannot be assigned
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::userId => $this->entity->user_id = (int)$value,
            self::requestedAt => $this->entity->requested_at = (string)$value,
            self::state => $this->entity->state = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * @param string $storedName Finished archive basename
     * @param int $sizeBytes Archive size
     * @param string $finishedAt Completion time in SQL UTC
     * @param string $expiresAt Expiry time in SQL UTC
     * @return bool Whether this request still existed and was completed by this call
     * @throws HilosException When the conditional write or its announcement fails
     */
    public function finishReady(string $storedName, int $sizeBytes, string $finishedAt, string $expiresAt): bool
    {
        return $this->finish(DataExportState::READY, $storedName, $sizeBytes, $finishedAt, $expiresAt);
    }

    /**
     * @param string $finishedAt Failure time in SQL UTC
     * @param string $expiresAt Expiry time in SQL UTC
     * @return bool Whether this request still existed and was ended by this call
     * @throws HilosException When the conditional write or its announcement fails
     */
    public function finishFailed(string $finishedAt, string $expiresAt): bool
    {
        return $this->finish(DataExportState::FAILED, null, null, $finishedAt, $expiresAt);
    }

    /**
     * @return array<string, mixed> Queue row for ORM synchronization
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::state => $this->entity->state,
            self::requestedAt => $this->entity->requested_at,
            self::finishedAt => $this->entity->finished_at,
            self::expiresAt => $this->entity->expires_at,
            self::storedName => $this->entity->stored_name,
            self::sizeBytes => $this->entity->size_bytes,
        ];
    }

    /**
     * @return string The framework queue's collection key
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::dataExports;
    }

    /**
     * @param string $state Finished state
     * @param ?string $storedName Ready archive basename
     * @param ?int $sizeBytes Ready archive size
     * @param string $finishedAt Completion time in SQL UTC
     * @param string $expiresAt Expiry time in SQL UTC
     * @return bool Whether this call won completion of the original request
     * @throws HilosException When the conditional write or its announcement fails
     */
    private function finish(string $state, ?string $storedName, ?int $sizeBytes, string $finishedAt, string $expiresAt): bool
    {
        if ($this->entity->id === null) {
            return false;
        }
        DbWriteGuard::guardItemWrite(
            self::getCollectionKey(),
            (string)$this->entity->id,
            $this->touchedSetKeys(...),
            TruthSourceOperation::Update,
        );
        Database::sql(
            'UPDATE `' . EntityDataExport::_table . '` SET `state` = ?, `stored_name` = ?, `size_bytes` = ?, '
                . '`finished_at` = ?, `expires_at` = ? WHERE `id` = ? AND `state` = ?',
            [$state, $storedName, $sizeBytes, $finishedAt, $expiresAt, $this->entity->id, DataExportState::PREPARING],
        );
        if (Database::affectedRows() !== 1) {
            return false;
        }
        $this->entity->state = $state;
        $this->entity->stored_name = $storedName;
        $this->entity->size_bytes = $sizeBytes;
        $this->entity->finished_at = $finishedAt;
        $this->entity->expires_at = $expiresAt;
        $this->sync();

        return true;
    }
}
