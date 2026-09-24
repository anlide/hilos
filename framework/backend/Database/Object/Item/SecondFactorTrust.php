<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\SecondFactorTrust as EntitySecondFactorTrust;
use Hilos\Database\Object\Item\Object_;

/**
 * SecondFactorTrust object - wraps the SecondFactorTrust entity (HIL-494).
 *
 * One "don't ask again on this device": a browser (its session row) and a person the
 * second-factor step is skipped for until `trustedUntil`.
 *
 * @extends Object_<EntitySecondFactorTrust>
 *
 * @property-read ?int $id
 * @property int $sessionId
 * @property int $userId
 * @property string $trustedUntil
 * @property string $createdAt
 */
final class SecondFactorTrust extends Object_
{
    public const string ENTITY_CLASS = EntitySecondFactorTrust::class;
    public const string id = 'id';
    public const string sessionId = 'sessionId';
    public const string userId = 'userId';
    public const string trustedUntil = 'trustedUntil';
    public const string createdAt = 'createdAt';

    /**
     * Returns the database collection key.
     *
     * @return string Collection key (HilosDbContext::secondFactorTrusts)
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::secondFactorTrusts;
    }

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Property name (see class @property list)
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known SecondFactorTrust field
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::sessionId => $this->entity->session_id,
            self::userId => $this->entity->user_id,
            self::trustedUntil => $this->entity->trusted_until,
            self::createdAt => $this->entity->created_at,
            default => parent::__get($property),
        };
    }

    /**
     * Magic setter for entity properties.
     *
     * @param string $property Name of a settable property (see the class @property list)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on a SecondFactorTrust
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::sessionId => $this->entity->session_id = (int)$value,
            self::userId => $this->entity->user_id = (int)$value,
            self::trustedUntil => $this->entity->trusted_until = (string)$value,
            self::createdAt => $this->entity->created_at = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the trust row to an associative array.
     *
     * @return array<string, mixed> Trust data (id, sessionId, userId, trustedUntil, createdAt)
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::sessionId => $this->entity->session_id,
            self::userId => $this->entity->user_id,
            self::trustedUntil => $this->entity->trusted_until,
            self::createdAt => $this->entity->created_at,
        ];
    }
}
