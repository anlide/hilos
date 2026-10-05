<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\LegalAcceptanceExport as EntityLegalAcceptanceExport;
use Hilos\Database\Object\Collection\LegalAcceptanceExports as ObjectLegalAcceptanceExports;
use Hilos\HilosException;
use Hilos\Legal\Export\LegalAcceptancesExportState;

/**
 * The durable order of a file of acceptance records; completion is conditional on the order still preparing.
 *
 * @extends Object_<EntityLegalAcceptanceExport>
 * @property ?int $id
 * @property int $userId
 * @property string $state
 * @property ?string $document
 * @property ?string $revisionId
 * @property ?string $search
 * @property string $requestedAt
 * @property ?string $finishedAt
 * @property ?string $expiresAt
 * @property ?string $storedName
 * @property ?int $sizeBytes
 * @property ?int $records
 */
class LegalAcceptanceExport extends Object_
{
    public const string ENTITY_CLASS = EntityLegalAcceptanceExport::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectLegalAcceptanceExports::class;
    public const string id = 'id';
    public const string userId = 'userId';
    public const string state = 'state';
    public const string document = 'document';
    public const string revisionId = 'revisionId';
    public const string search = 'search';
    public const string requestedAt = 'requestedAt';
    public const string finishedAt = 'finishedAt';
    public const string expiresAt = 'expiresAt';
    public const string storedName = 'storedName';
    public const string sizeBytes = 'sizeBytes';
    public const string records = 'records';

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
            self::document => $this->entity->document,
            self::revisionId => $this->entity->revision_id,
            self::search => $this->entity->search,
            self::requestedAt => $this->entity->requested_at,
            self::finishedAt => $this->entity->finished_at,
            self::expiresAt => $this->entity->expires_at,
            self::storedName => $this->entity->stored_name,
            self::sizeBytes => $this->entity->size_bytes,
            self::records => $this->entity->records,
            default => parent::__get($property),
        };
    }

    /**
     * Only the order's own fields are assigned directly; completion uses a conditional write.
     *
     * @param string $property Order property
     * @param mixed $value Persisted scalar
     * @throws DatabaseException When the property cannot be assigned
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::userId => $this->entity->user_id = (int)$value,
            self::state => $this->entity->state = (string)$value,
            self::document => $this->entity->document = $value === null ? null : (string)$value,
            self::revisionId => $this->entity->revision_id = $value === null ? null : (string)$value,
            self::search => $this->entity->search = $value === null ? null : (string)$value,
            self::requestedAt => $this->entity->requested_at = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * @param string $storedName Finished file basename
     * @param int $sizeBytes File size
     * @param int $records Acceptance records written
     * @param string $finishedAt Completion time in SQL UTC
     * @param string $expiresAt Expiry time in SQL UTC
     * @return bool Whether this order still existed and was completed by this call
     * @throws HilosException When the conditional write or its announcement fails
     */
    public function finishReady(string $storedName, int $sizeBytes, int $records, string $finishedAt, string $expiresAt): bool
    {
        return $this->finish(LegalAcceptancesExportState::READY, $storedName, $sizeBytes, $records, $finishedAt, $expiresAt);
    }

    /**
     * @param string $finishedAt Failure time in SQL UTC
     * @param string $expiresAt Expiry time in SQL UTC
     * @return bool Whether this order still existed and was ended by this call
     * @throws HilosException When the conditional write or its announcement fails
     */
    public function finishFailed(string $finishedAt, string $expiresAt): bool
    {
        return $this->finish(LegalAcceptancesExportState::FAILED, null, null, null, $finishedAt, $expiresAt);
    }

    /**
     * @return array<string, mixed> Order row for ORM synchronization
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::state => $this->entity->state,
            self::document => $this->entity->document,
            self::revisionId => $this->entity->revision_id,
            self::search => $this->entity->search,
            self::requestedAt => $this->entity->requested_at,
            self::finishedAt => $this->entity->finished_at,
            self::expiresAt => $this->entity->expires_at,
            self::storedName => $this->entity->stored_name,
            self::sizeBytes => $this->entity->size_bytes,
            self::records => $this->entity->records,
        ];
    }

    /**
     * @param string $state Finished state
     * @param ?string $storedName Ready file basename
     * @param ?int $sizeBytes Ready file size
     * @param ?int $records Ready record count
     * @param string $finishedAt Completion time in SQL UTC
     * @param string $expiresAt Expiry time in SQL UTC
     * @return bool Whether this call won completion of the original order
     * @throws HilosException When the conditional write or its announcement fails
     */
    private function finish(
        string $state,
        ?string $storedName,
        ?int $sizeBytes,
        ?int $records,
        string $finishedAt,
        string $expiresAt,
    ): bool {
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
            'UPDATE `' . EntityLegalAcceptanceExport::_table . '` SET `state` = ?, `stored_name` = ?, `size_bytes` = ?, '
                . '`records` = ?, `finished_at` = ?, `expires_at` = ? WHERE `id` = ? AND `state` = ?',
            [$state, $storedName, $sizeBytes, $records, $finishedAt, $expiresAt, $this->entity->id, LegalAcceptancesExportState::PREPARING],
        );
        if (Database::affectedRows() !== 1) {
            return false;
        }
        $this->entity->state = $state;
        $this->entity->stored_name = $storedName;
        $this->entity->size_bytes = $sizeBytes;
        $this->entity->records = $records;
        $this->entity->finished_at = $finishedAt;
        $this->entity->expires_at = $expiresAt;
        $this->sync();

        return true;
    }
}
