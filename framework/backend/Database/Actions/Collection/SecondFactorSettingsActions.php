<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Actions\Exception\UnknownLazyStrategyException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\SecondFactorSettings as ObjectSecondFactorSettings;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Collection\SecondFactorSettings as DbCollectionSecondFactorSettings;
use Hilos\Database\View\Item\SecondFactorSetting;

/**
 * SecondFactorSettingsActions - write operations for the SecondFactorSettings collection (HIL-494).
 *
 * One write, an upsert: a person's row is created on the first choice they make, so the
 * write is a collection one even when the row turns out to exist.
 *
 * @extends DbActions<SecondFactorSetting, ObjectSecondFactorSettings>
 * @property-read DbCollectionSecondFactorSettings $collection
 * @property-read ObjectSecondFactorSettings $objectCollection
 */
final class SecondFactorSettingsActions extends DbActions
{
    /**
     * Stores a person's removal wait: the one in force and a shorter one parked until a moment.
     *
     * @param int $userId Person
     * @param ?int $days Wait in force in days, or null for the administrator's default
     * @param ?int $pendingDays Shorter wait parked, or null when none is
     * @param ?string $pendingFrom Moment the parked wait takes over (SQL datetime), or null when none is parked
     * @throws CreateNotAllowedException When the truth source rejects the insert
     * @throws WriteNotAllowedException When the truth source rejects the update
     * @throws UnknownLazyStrategyException When the collection has an unsupported lazy strategy
     * @throws LogicException When the object collection entity class is not configured
     * @throws DatabaseException When the lookup or the write fails
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If the row has no primary key
     */
    public function setResetWait(int $userId, ?int $days, ?int $pendingDays, ?string $pendingFrom): void
    {
        $this->ensureCanCreate();

        $this->objectCollection->setResetWait($userId, $days, $pendingDays, $pendingFrom);
    }
}
