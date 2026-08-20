<?php

namespace Demo\SimplePoll\Database\Object\Item;

use Demo\SimplePoll\Database\Entity\Item\Guest as EntityGuest;
use Demo\SimplePoll\Database\PollDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;

/**
 * Guest - Object wrapper for guest entity.
 *
 * Auto-generated from Entity: Demo\SimplePoll\Database\Entity\Item\Guest
 *
 * Business logic layer with change tracking.
 *
 * @extends Object_<EntityGuest>
 *
 * @property-read ?int $id
 * @property string $sessionToken
 * @property string $name
 * @property string $createdAt
 */
final class Guest extends Object_
{
    public const string ENTITY_CLASS = EntityGuest::class;

    public const string id = 'id';
    public const string sessionToken = 'sessionToken';
    public const string name = 'name';
    public const string createdAt = 'createdAt';

    /**
     * Returns the database collection key for this object type.
     *
     * @return string Collection key (PollDbContext::guests)
     */
    protected static function getCollectionKey(): string
    {
        return PollDbContext::guests;
    }

    /**
     * Returns the value of a guest object property by name.
     *
     * @param string $property Property name (id, sessionToken, name, createdAt)
     * @return mixed Property value or parent method result
     * @throws DatabaseException If entity access fails
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::sessionToken => $this->entity->session_token,
            self::name => $this->entity->name,
            self::createdAt => $this->entity->created_at,
            default => parent::__get($property),
        };
    }

    /**
     * Sets the value of a guest object property.
     *
     * @param string $property Property name to set
     * @param mixed $value New value (cast to appropriate type)
     * @throws DatabaseException If entity sync fails
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::sessionToken => $this->entity->session_token = (string)$value,
            self::name => $this->entity->name = (string)$value,
            self::createdAt => $this->entity->created_at = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the guest object to an associative array with all fields.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::sessionToken => $this->entity->session_token,
            self::name => $this->entity->name,
            self::createdAt => $this->entity->created_at,
        ];
    }
}
