<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\SecondFactorBackupCode as EntitySecondFactorBackupCode;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * SecondFactorBackupCode object - wraps the SecondFactorBackupCode entity (HIL-494).
 *
 * One one-shot backup code. {@see spend()} burns it with the condition on the write, so
 * the same code typed in two tabs passes once. The code itself is DB-only and moves only
 * through {@see readCode()} and {@see writeCode()}.
 *
 * @extends Object_<EntitySecondFactorBackupCode>
 *
 * @property-read ?int $id
 * @property int $userId
 * @property-read ?string $usedAt
 * @property string $createdAt
 */
final class SecondFactorBackupCode extends Object_
{
    public const string ENTITY_CLASS = EntitySecondFactorBackupCode::class;
    public const string id = 'id';
    public const string userId = 'userId';
    public const string usedAt = 'usedAt';
    public const string createdAt = 'createdAt';

    /**
     * Returns the database collection key.
     *
     * @return string Collection key (HilosDbContext::secondFactorBackupCodes)
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::secondFactorBackupCodes;
    }

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Property name (see class @property list)
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known SecondFactorBackupCode field
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::usedAt => $this->entity->used_at,
            self::createdAt => $this->entity->created_at,
            default => parent::__get($property),
        };
    }

    /**
     * Magic setter for entity properties.
     *
     * `usedAt` has no setter here; it moves only through {@see spend()}.
     *
     * @param string $property Name of a settable property (see the class @property list)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on a SecondFactorBackupCode
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::userId => $this->entity->user_id = (int)$value,
            self::createdAt => $this->entity->created_at = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Reads the stored code, normalized.
     *
     * @return ?string Stored code, or null when none is stored or the row is unpersisted
     * @throws DatabaseException When the code lookup query fails
     */
    public function readCode(): ?string
    {
        if ($this->entity->id === null) {
            return null;
        }

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($this->entity->id));
        $row = Database::sql(
            'SELECT `' . EntitySecondFactorBackupCode::code . '` FROM `' . EntitySecondFactorBackupCode::_table
                . '` WHERE `' . EntitySecondFactorBackupCode::id . '` = ?',
            $params,
        )->firstRow();
        $code = $row[EntitySecondFactorBackupCode::code] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * Stores the code of this row, normalized.
     *
     * Written with a targeted UPDATE right after the row is inserted, so the code stays out
     * of the ORM columns and the cross-worker sync payload. A no-op for an unpersisted row.
     *
     * @param string $code Normalized backup code
     * @throws DatabaseException When the code update query fails
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function writeCode(string $code): void
    {
        if ($this->entity->id === null) {
            return;
        }

        DbWriteGuard::guardItemWrite(
            static::getCollectionKey(),
            (string)$this->entity->id,
            $this->touchedSetKeys(...),
            TruthSourceOperation::Update,
        );

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::string($code));
        $params->add(SqlParam::int($this->entity->id));
        Database::sql(
            'UPDATE `' . EntitySecondFactorBackupCode::_table . '` SET `' . EntitySecondFactorBackupCode::code
                . '` = ? WHERE `' . EntitySecondFactorBackupCode::id . '` = ?',
            $params,
        );
    }

    /**
     * Burns this code, if nobody burned it first.
     *
     * The UPDATE carries `used_at IS NULL`, so of two workers spending the same code exactly
     * one changes the row and gets true; false means the code was already used and never
     * that the write failed - a failing write throws. The winner's row is re-announced
     * through {@see sync()}, so the count on the profile moves in every process. An
     * unpersisted code answers false.
     *
     * @return bool True when this call burned the code, false when it was used already
     * @throws DatabaseException When the update, the row count or the re-announcement fails
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws CreateNotAllowedException Never for a persisted row; declared by the re-announcing sync
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws ObjectGetIdStringNotImplementedException If the primary key is null during the re-announcement
     */
    public function spend(): bool
    {
        if ($this->entity->id === null) {
            return false;
        }

        DbWriteGuard::guardItemWrite(
            static::getCollectionKey(),
            (string)$this->entity->id,
            $this->touchedSetKeys(...),
            TruthSourceOperation::Update,
        );

        $now = TimeHelper::getSqlDateTime();
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::string($now));
        $params->add(SqlParam::int($this->entity->id));
        Database::sql(
            'UPDATE `' . EntitySecondFactorBackupCode::_table . '` SET `' . EntitySecondFactorBackupCode::used_at
                . '` = ? WHERE `' . EntitySecondFactorBackupCode::id . '` = ?'
                . ' AND `' . EntitySecondFactorBackupCode::used_at . '` IS NULL',
            $params,
        );
        if (Database::affectedRows() !== 1) {
            return false;
        }

        $this->entity->used_at = $now;
        $this->sync();

        return true;
    }

    /**
     * Converts the backup code row to an associative array (never includes the code).
     *
     * @return array<string, mixed> Backup code data (id, userId, usedAt, createdAt)
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::usedAt => $this->entity->used_at,
            self::createdAt => $this->entity->created_at,
        ];
    }
}
