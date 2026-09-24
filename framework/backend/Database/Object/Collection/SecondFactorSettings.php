<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\SecondFactorSettings as EntitySecondFactorSettings;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\SecondFactorSetting as ObjectSecondFactorSetting;
use Hilos\Database\Object\Objects;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * SecondFactorSettings object collection - each person's own removal wait (HIL-494).
 *
 * Keyed by the person, so a person's row is read by key. {@see setResetWait()} is the one
 * write: it upserts the row with the wait in force and the shorter wait parked, if any.
 *
 * @extends Objects<ObjectSecondFactorSetting>
 * @method ObjectSecondFactorSetting|null current()
 * @method ObjectSecondFactorSetting|null first()
 * @method ObjectSecondFactorSetting|null last()
 * @method ObjectSecondFactorSetting|null get(int|string $key)
 * @method ObjectSecondFactorSetting|null offsetGet(mixed $offset)
 */
final class SecondFactorSettings extends Objects
{
    public const string OBJECT_CLASS = ObjectSecondFactorSetting::class;
    public const string ENTITY_COLLECTION_CLASS = EntitySecondFactorSettings::class;
    public const string COLLECTION_KEY = HilosDbContext::secondFactorSettings;

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
            $setting = ObjectSecondFactorSetting::create();
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
}
