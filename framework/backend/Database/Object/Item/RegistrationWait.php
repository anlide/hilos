<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\RegistrationWait as EntityRegistrationWait;
use Hilos\Database\Object\Item\Object_;

/**
 * RegistrationWait object - wraps RegistrationWait entity.
 *
 * The session's durable memory of an unfinished registration (HIL-486): whose
 * session is waiting, and on which identifier. Nothing else is stored, because
 * everything else about that registration - when the code expires, which channel
 * carried it - belongs to the hold and the challenge, which outlive no session and
 * are read from their own rows.
 *
 * @extends Object_<EntityRegistrationWait>
 *
 * @property-read ?int $id
 * @property string $sessionToken
 * @property string $identifier
 */
final class RegistrationWait extends Object_
{
    public const string ENTITY_CLASS = EntityRegistrationWait::class;
    public const string id = 'id';
    public const string sessionToken = 'sessionToken';
    public const string identifier = 'identifier';

    /**
     * Returns the database collection key.
     *
     * @return string Collection key (HilosDbContext::registrationWaits)
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::registrationWaits;
    }

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Property name (id, sessionToken, identifier)
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known RegistrationWait field
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::sessionToken => $this->entity->session_token,
            self::identifier => $this->entity->identifier,
            default => parent::__get($property),
        };
    }

    /**
     * Magic setter for entity properties.
     *
     * @param string $property Property name (sessionToken, identifier)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on a RegistrationWait
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::sessionToken => $this->entity->session_token = (string)$value,
            self::identifier => $this->entity->identifier = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the wait to an associative array.
     *
     * @return array<string, mixed> Wait data (id, sessionToken, identifier)
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::sessionToken => $this->entity->session_token,
            self::identifier => $this->entity->identifier,
        ];
    }
}
