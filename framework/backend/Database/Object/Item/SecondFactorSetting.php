<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\SecondFactorSetting as EntitySecondFactorSetting;
use Hilos\Database\Object\Collection\SecondFactorSettings as ObjectSecondFactorSettings;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;

/**
 * SecondFactorSetting object - wraps the SecondFactorSetting entity (HIL-494).
 *
 * A person's own wait before a removal of their second factor, and the shorter wait
 * parked until the one in force runs out; the wrong app codes counted against the person and
 * the lock their ceiling put on app codes (HIL-1285). Keyed by the person.
 *
 * @extends Object_<EntitySecondFactorSetting>
 *
 * @property int $userId
 * @property ?int $resetWaitDays
 * @property ?int $pendingResetWaitDays
 * @property ?string $pendingResetWaitFrom
 * @property int $appCodeMisses
 * @property ?string $appCodeMissesFrom
 * @property int $appCodeLockStep
 * @property ?string $appCodeLockedUntil
 * @property string $updatedAt
 */
class SecondFactorSetting extends Object_
{
    public const string ENTITY_CLASS = EntitySecondFactorSetting::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSecondFactorSettings::class;
    public const string userId = 'userId';
    public const string resetWaitDays = 'resetWaitDays';
    public const string pendingResetWaitDays = 'pendingResetWaitDays';
    public const string pendingResetWaitFrom = 'pendingResetWaitFrom';
    public const string appCodeMisses = 'appCodeMisses';
    public const string appCodeMissesFrom = 'appCodeMissesFrom';
    public const string appCodeLockStep = 'appCodeLockStep';
    public const string appCodeLockedUntil = 'appCodeLockedUntil';
    public const string updatedAt = 'updatedAt';

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Property name (see class @property list)
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known SecondFactorSetting field
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::userId => $this->entity->user_id,
            self::resetWaitDays => $this->entity->reset_wait_days,
            self::pendingResetWaitDays => $this->entity->pending_reset_wait_days,
            self::pendingResetWaitFrom => $this->entity->pending_reset_wait_from,
            self::appCodeMisses => $this->entity->app_code_misses,
            self::appCodeMissesFrom => $this->entity->app_code_misses_from,
            self::appCodeLockStep => $this->entity->app_code_lock_step,
            self::appCodeLockedUntil => $this->entity->app_code_locked_until,
            self::updatedAt => $this->entity->updated_at,
            default => parent::__get($property),
        };
    }

    /**
     * Magic setter for entity properties.
     *
     * @param string $property Name of a settable property (see the class @property list)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on a SecondFactorSetting
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::userId => $this->entity->user_id = (int)$value,
            self::resetWaitDays => $this->entity->reset_wait_days = $value === null ? null : (int)$value,
            self::pendingResetWaitDays => $this->entity->pending_reset_wait_days = $value === null ? null : (int)$value,
            self::pendingResetWaitFrom => $this->entity->pending_reset_wait_from = $value === null ? null : (string)$value,
            self::appCodeMisses => $this->entity->app_code_misses = (int)$value,
            self::appCodeMissesFrom => $this->entity->app_code_misses_from = $value === null ? null : (string)$value,
            self::appCodeLockStep => $this->entity->app_code_lock_step = (int)$value,
            self::appCodeLockedUntil => $this->entity->app_code_locked_until = $value === null ? null : (string)$value,
            self::updatedAt => $this->entity->updated_at = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the setting row to an associative array.
     *
     * @return array<string, mixed> Setting data (userId, resetWaitDays, pendingResetWaitDays, pendingResetWaitFrom,
     *     appCodeMisses, appCodeMissesFrom, appCodeLockStep, appCodeLockedUntil, updatedAt)
     */
    public function toArray(): array
    {
        return [
            self::userId => $this->entity->user_id,
            self::resetWaitDays => $this->entity->reset_wait_days,
            self::pendingResetWaitDays => $this->entity->pending_reset_wait_days,
            self::pendingResetWaitFrom => $this->entity->pending_reset_wait_from,
            self::appCodeMisses => $this->entity->app_code_misses,
            self::appCodeMissesFrom => $this->entity->app_code_misses_from,
            self::appCodeLockStep => $this->entity->app_code_lock_step,
            self::appCodeLockedUntil => $this->entity->app_code_locked_until,
            self::updatedAt => $this->entity->updated_at,
        ];
    }

    /**
     * Counts one wrong app code against the person and answers the count of the window (HIL-1285).
     *
     * The count is kept by the ROW, as the session keeps the count of its sign-in wait: one
     * statement either opens a new window with this miss - none was open, or the one open began
     * at or before `$windowEnded` - or adds the miss to the window that is open, so of two wrong
     * codes sent at once both are counted and neither is lost to a read-modify-write. The mirror
     * is re-read afterwards, because the ceiling is judged by it, and goes back with the row if
     * a transaction around the miss does not commit.
     *
     * @param string $windowEnded Moment a window must have begun after to be still open (SQL datetime)
     * @param string $now Moment of the miss, which opens a new window (SQL datetime)
     * @return int Wrong app codes in the window after this one
     * @throws DatabaseException When the update or the read-back fails
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function countAppCodeMiss(string $windowEnded, string $now): int
    {
        DbWriteGuard::guardItemWrite(
            static::getCollectionKey(),
            (string)$this->entity->user_id,
            $this->touchedSetKeys(...),
            TruthSourceOperation::Update,
        );

        $windowOpens = '`' . EntitySecondFactorSetting::app_code_misses_from . '` IS NULL OR `'
            . EntitySecondFactorSetting::app_code_misses_from . '` <= ?';
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::string($windowEnded));
        $params->add(SqlParam::string($windowEnded));
        $params->add(SqlParam::string($now));
        $params->add(SqlParam::string($now));
        $params->add(SqlParam::int($this->entity->user_id));
        Database::sql(
            'UPDATE `' . EntitySecondFactorSetting::_table . '` SET `' . EntitySecondFactorSetting::app_code_misses
                . '` = IF(' . $windowOpens . ', 1, `' . EntitySecondFactorSetting::app_code_misses . '` + 1), `'
                . EntitySecondFactorSetting::app_code_misses_from . '` = IF(' . $windowOpens . ', ?, `'
                . EntitySecondFactorSetting::app_code_misses_from . '`), `' . EntitySecondFactorSetting::updated_at
                . '` = ? WHERE `' . EntitySecondFactorSetting::user_id . '` = ?',
            $params,
        );

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($this->entity->user_id));
        $row = Database::sql(
            'SELECT `' . EntitySecondFactorSetting::app_code_misses . '`, `' . EntitySecondFactorSetting::app_code_misses_from
                . '`, `' . EntitySecondFactorSetting::updated_at . '` FROM `' . EntitySecondFactorSetting::_table
                . '` WHERE `' . EntitySecondFactorSetting::user_id . '` = ?',
            $params,
        )->firstRow();
        if ($row === null) {
            return 0;
        }

        $stored = [$this->entity->app_code_misses, $this->entity->app_code_misses_from, $this->entity->updated_at];
        $this->mirrorMisses(
            (int)$row[EntitySecondFactorSetting::app_code_misses],
            $row[EntitySecondFactorSetting::app_code_misses_from] === null
                ? null
                : (string)$row[EntitySecondFactorSetting::app_code_misses_from],
            (string)$row[EntitySecondFactorSetting::updated_at],
        );
        Database::onRollback(function () use ($stored): void {
            $this->mirrorMisses(...$stored);
        });

        return $this->entity->app_code_misses;
    }

    /**
     * Locks the person's app codes until a moment, if the window still holds the ceiling (HIL-1285).
     *
     * The UPDATE carries `app_code_misses >= ?`, and it empties the window it locks for, so of
     * two misses that both reached the ceiling exactly one changes the row and gets true - one
     * lock, one notice; false means the other miss locked first and never that the write
     * failed - a failing write throws. The winner's row is re-announced through {@see sync()},
     * so every reader of the collection sees the lock.
     *
     * @param int $atMisses Ceiling the window has to hold for the lock to be put
     * @param int $step Step of the lock on the ladder
     * @param string $until End of the lock (SQL datetime)
     * @param string $now Moment of the lock (SQL datetime)
     * @return bool True when this call put the lock, false when another miss put it already
     * @throws DatabaseException When the update, the row count or the re-announcement fails
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws CreateNotAllowedException Never for a persisted row; declared by the re-announcing sync
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws ObjectGetIdStringNotImplementedException If the primary key is null during the re-announcement
     */
    public function lockAppCodes(int $atMisses, int $step, string $until, string $now): bool
    {
        DbWriteGuard::guardItemWrite(
            static::getCollectionKey(),
            (string)$this->entity->user_id,
            $this->touchedSetKeys(...),
            TruthSourceOperation::Update,
        );

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($step));
        $params->add(SqlParam::string($until));
        $params->add(SqlParam::string($now));
        $params->add(SqlParam::int($this->entity->user_id));
        $params->add(SqlParam::int($atMisses));
        Database::sql(
            'UPDATE `' . EntitySecondFactorSetting::_table . '` SET `' . EntitySecondFactorSetting::app_code_lock_step
                . '` = ?, `' . EntitySecondFactorSetting::app_code_locked_until . '` = ?, `'
                . EntitySecondFactorSetting::app_code_misses . '` = 0, `' . EntitySecondFactorSetting::app_code_misses_from
                . '` = NULL, `' . EntitySecondFactorSetting::updated_at . '` = ? WHERE `'
                . EntitySecondFactorSetting::user_id . '` = ? AND `' . EntitySecondFactorSetting::app_code_misses . '` >= ?',
            $params,
        );
        if (Database::affectedRows() !== 1) {
            return false;
        }

        $this->entity->app_code_lock_step = $step;
        $this->entity->app_code_locked_until = $until;
        $this->entity->app_code_misses = 0;
        $this->entity->app_code_misses_from = null;
        $this->entity->updated_at = $now;
        $this->sync();

        return true;
    }

    /**
     * Puts the miss count, its window and the row's stamp the database answered into the mirror.
     *
     * @param int $misses Wrong app codes in the window
     * @param ?string $missesFrom Beginning of the window (SQL datetime), or null when none is open
     * @param string $updatedAt Row's stamp (SQL datetime)
     */
    private function mirrorMisses(int $misses, ?string $missesFrom, string $updatedAt): void
    {
        $this->entity->app_code_misses = $misses;
        $this->entity->app_code_misses_from = $missesFrom;
        $this->entity->updated_at = $updatedAt;
        $this->entitySync->app_code_misses = $misses;
        $this->entitySync->app_code_misses_from = $missesFrom;
        $this->entitySync->updated_at = $updatedAt;
    }
}
