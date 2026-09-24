<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\SecondFactorSetting as EntitySecondFactorSetting;
use Hilos\Database\Object\Item\Object_;

/**
 * SecondFactorSetting object - wraps the SecondFactorSetting entity (HIL-494).
 *
 * A person's own wait before a removal of their second factor, and the shorter wait
 * parked until the one in force runs out. Keyed by the person.
 *
 * @extends Object_<EntitySecondFactorSetting>
 *
 * @property int $userId
 * @property ?int $resetWaitDays
 * @property ?int $pendingResetWaitDays
 * @property ?string $pendingResetWaitFrom
 * @property string $updatedAt
 */
final class SecondFactorSetting extends Object_
{
    public const string ENTITY_CLASS = EntitySecondFactorSetting::class;
    public const string userId = 'userId';
    public const string resetWaitDays = 'resetWaitDays';
    public const string pendingResetWaitDays = 'pendingResetWaitDays';
    public const string pendingResetWaitFrom = 'pendingResetWaitFrom';
    public const string updatedAt = 'updatedAt';

    /**
     * Returns the database collection key.
     *
     * @return string Collection key (HilosDbContext::secondFactorSettings)
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::secondFactorSettings;
    }

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
            self::updatedAt => $this->entity->updated_at = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the setting row to an associative array.
     *
     * @return array<string, mixed> Setting data (userId, resetWaitDays, pendingResetWaitDays, pendingResetWaitFrom, updatedAt)
     */
    public function toArray(): array
    {
        return [
            self::userId => $this->entity->user_id,
            self::resetWaitDays => $this->entity->reset_wait_days,
            self::pendingResetWaitDays => $this->entity->pending_reset_wait_days,
            self::pendingResetWaitFrom => $this->entity->pending_reset_wait_from,
            self::updatedAt => $this->entity->updated_at,
        ];
    }
}
