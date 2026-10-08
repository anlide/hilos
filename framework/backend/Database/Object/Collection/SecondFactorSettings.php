<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\SecondFactorSetting as EntitySecondFactorSetting;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\SecondFactorSettings as EntitySecondFactorSettings;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\SecondFactorSetting as ObjectSecondFactorSetting;
use Hilos\Database\Object\Objects;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * SecondFactorSettings object collection - each person's own removal wait (HIL-494) and the
 * count of their wrong app codes with the lock it puts (HIL-1285).
 *
 * Keyed by the person, so a person's row is read by key. {@see setResetWait()} upserts the row
 * with the wait in force and the shorter wait parked, if any; {@see countAppCodeMiss()} upserts
 * it to count a wrong app code; {@see lockAppCodes()} and {@see unlockAppCodes()} put and lift
 * the lock on a row that stands.
 *
 * @extends Objects<ObjectSecondFactorSetting>
 * @method ObjectSecondFactorSetting|null current()
 * @method ObjectSecondFactorSetting|null first()
 * @method ObjectSecondFactorSetting|null last()
 * @method ObjectSecondFactorSetting|null get(int|string $key)
 * @method ObjectSecondFactorSetting|null offsetGet(mixed $offset)
 */
class SecondFactorSettings extends Objects
{
    public const string OBJECT_CLASS = ObjectSecondFactorSetting::class;
    public const string ENTITY_COLLECTION_CLASS = EntitySecondFactorSettings::class;
    public const string COLLECTION_KEY = HilosDbContext::secondFactorSettings;

    /**
     * Locks both account sets before merge reads them, without waiting for another writer.
     * The caller must hold a transaction; this read neither opens nor commits one.
     *
     * @param int $survivorId Surviving account
     * @param int $loserId Folded account
     * @throws DatabaseException When a set is busy or its rows cannot be locked
     */
    public function lockForMerge(int $survivorId, int $loserId): void
    {
        Database::sql(
            'SELECT `' . EntitySecondFactorSetting::user_id . '` FROM `' . EntitySecondFactorSetting::_table
                . '` WHERE `' . EntitySecondFactorSetting::user_id . '` IN (?, ?) ORDER BY `'
                . EntitySecondFactorSetting::user_id . '` FOR UPDATE NOWAIT',
            [$survivorId, $loserId],
        );
    }

    /**
     * Stores a person's removal wait: the one in force and a shorter one parked until a moment.
     *
     * @param int $userId Person
     * @param ?int $days Wait in force in days, or null for the administrator's default
     * @param ?int $pendingDays Shorter wait parked, or null when none is
     * @param ?string $pendingFrom Moment the parked wait takes over (SQL datetime), or null when none is parked
     * @throws DatabaseException When the lookup or the write fails
     * @throws CreateNotAllowedException When no truth source in this process may add a row here
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws ObjectGetIdStringNotImplementedException If the row has no primary key
     * @throws LogicException When the collection class constants are not configured
     */
    public function setResetWait(int $userId, ?int $days, ?int $pendingDays, ?string $pendingFrom): void
    {
        $setting = $this->offsetGet($userId);
        $isNew = $setting === null;
        if ($setting === null) {
            $setting = static::OBJECT_CLASS::create();
            $setting->userId = $userId;
        }

        $setting->resetWaitDays = $days;
        $setting->pendingResetWaitDays = $pendingDays;
        $setting->pendingResetWaitFrom = $pendingFrom;
        $setting->updatedAt = TimeHelper::getSqlDateTime();
        $setting->sync();

        if ($isNew) {
            $this[$userId] = $setting;
        }
    }

    /**
     * Counts one wrong app code against a person and answers the count of the window (HIL-1285).
     *
     * A person who never chose a wait has no row yet; the miss creates it the way the first
     * choice does. The count itself is one statement on the row ({@see ObjectSecondFactorSetting::countAppCodeMiss()}):
     * a window begun `$windowSeconds` or more ago is over, and this miss opens a new one.
     *
     * @param int $userId Person
     * @param int $windowSeconds Length of the window the misses are counted in
     * @return int Wrong app codes in the window after this one
     * @throws DatabaseException When the lookup, the insert, the update or the read-back fails
     * @throws CreateNotAllowedException When no truth source in this process may add a row here
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws ObjectGetIdStringNotImplementedException If the row has no primary key
     * @throws LogicException When the collection class constants are not configured
     */
    public function countAppCodeMiss(int $userId, int $windowSeconds): int
    {
        $setting = $this->offsetGet($userId);
        if ($setting === null) {
            $setting = static::OBJECT_CLASS::create();
            $setting->userId = $userId;
            $setting->updatedAt = TimeHelper::getSqlDateTime();
            $setting->sync();
            $this[$userId] = $setting;
        }

        $now = time();

        return $setting->countAppCodeMiss(date('Y-m-d H:i:s', $now - $windowSeconds), date('Y-m-d H:i:s', $now));
    }

    /**
     * Locks a person's app codes until a moment, if their window still holds the ceiling (HIL-1285).
     *
     * @param int $userId Person
     * @param int $atMisses Ceiling the window has to hold for the lock to be put
     * @param int $step Step of the lock on the ladder
     * @param string $until End of the lock (SQL datetime)
     * @return bool True when this call put the lock, false when another miss put it already or the person has no row
     * @throws DatabaseException When the lookup, the update, the row count or the re-announcement fails
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws CreateNotAllowedException Never for a persisted row; declared by the re-announcing sync
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws ObjectGetIdStringNotImplementedException If the row has no primary key
     * @throws LogicException When the collection class constants are not configured
     */
    public function lockAppCodes(int $userId, int $atMisses, int $step, string $until): bool
    {
        $setting = $this->offsetGet($userId);
        if ($setting === null) {
            return false;
        }

        return $setting->lockAppCodes($atMisses, $step, $until, TimeHelper::getSqlDateTime());
    }

    /**
     * Lifts a person's app-code lock: the lock, its step, the miss count and its window all go (HIL-1285).
     *
     * A person with no row has nothing to lift and nothing is written.
     *
     * @param int $userId Person
     * @return ?string End of the lifted lock (SQL datetime) when it was still in force, or null when none was
     * @throws DatabaseException When the lookup or the write fails
     * @throws CreateNotAllowedException Never for a persisted row; declared by the sync
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws ObjectGetIdStringNotImplementedException If the row has no primary key
     * @throws LogicException When the collection class constants are not configured
     */
    public function unlockAppCodes(int $userId): ?string
    {
        $setting = $this->offsetGet($userId);
        if ($setting === null) {
            return null;
        }

        $until = $setting->appCodeLockedUntil;
        $setting->appCodeMisses = 0;
        $setting->appCodeMissesFrom = null;
        $setting->appCodeLockStep = 0;
        $setting->appCodeLockedUntil = null;
        $setting->updatedAt = TimeHelper::getSqlDateTime();
        $setting->sync();

        return $until !== null && strtotime($until) > time() ? $until : null;
    }

    /**
     * Deletes a person's own removal wait - the account is being erased (HIL-302).
     *
     * The row leaves through its object so a delete announcement reaches every reader. A
     * person who never chose a wait has no row, which is not an error.
     *
     * @param int $userId Person whose row to delete
     * @throws DatabaseException When the lookup or the delete fails
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws LogicException When the collection class constants are not configured
     */
    public function deleteForUser(int $userId): void
    {
        $setting = $this->offsetGet($userId);
        if ($setting === null) {
            return;
        }

        $setting->delete();
        unset($this[$userId]);
    }
}
