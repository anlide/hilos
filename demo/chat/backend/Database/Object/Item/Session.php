<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Object\Item;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Entity\Item\Session as EntitySession;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;

/**
 * Session - Object wrapper for session entity.
 *
 * @extends Object_<EntitySession>
 *
 * @property-read ?int $id
 * @property string $token
 * @property ?int $userId
 * @property ?int $impersonatorUserId
 * @property ?string $createdAt
 * @property ?string $lastSeenAt
 * @property ?string $expiresAt
 */
final class Session extends Object_
{
    public const string ENTITY_CLASS = EntitySession::class;

    public const string id = 'id';
    public const string token = 'token';
    public const string userId = 'userId';
    public const string impersonatorUserId = 'impersonatorUserId';
    public const string createdAt = 'createdAt';
    public const string lastSeenAt = 'lastSeenAt';
    public const string expiresAt = 'expiresAt';

    /**
     * Returns the database collection key for this object type.
     *
     * @return string Collection key (ChatDbContext::sessions)
     */
    protected static function getCollectionKey(): string
    {
        return ChatDbContext::sessions;
    }

    /**
     * Returns the value of a session object property by name.
     *
     * @param string $property Property name (id, token, userId, createdAt, lastSeenAt, expiresAt)
     * @return mixed Property value or parent method result
     * @throws DatabaseException If entity access fails
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::token => $this->entity->token,
            self::userId => $this->entity->user_id,
            self::impersonatorUserId => $this->entity->impersonator_user_id,
            self::createdAt => $this->entity->created_at,
            self::lastSeenAt => $this->entity->last_seen_at,
            self::expiresAt => $this->entity->expires_at,
            default => parent::__get($property),
        };
    }

    /**
     * Sets the value of a session object property.
     *
     * @param string $property Property name to set
     * @param mixed $value New value (cast to appropriate type)
     * @throws DatabaseException If entity sync fails
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::token => $this->entity->token = (string)$value,
            self::userId => $this->entity->user_id = $value === null ? null : (int)$value,
            self::impersonatorUserId => $this->entity->impersonator_user_id = $value === null ? null : (int)$value,
            self::createdAt => $this->entity->created_at = is_scalar($value) ? (string)$value : null,
            self::lastSeenAt => $this->entity->last_seen_at = is_scalar($value) ? (string)$value : null,
            self::expiresAt => $this->entity->expires_at = is_scalar($value) ? (string)$value : null,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the session object to an associative array with all fields.
     *
     * `impersonatorUserId` is intentionally excluded: the impersonation marker is
     * read-legal server-side (the agent's guards read it via {@see __get}) but is
     * kept off the browser-sync projection this leaf — the impersonating state is
     * not surfaced to the frontend until the follow-up in-app UX leaf.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::token => $this->entity->token,
            self::userId => $this->entity->user_id,
            self::createdAt => $this->entity->created_at,
            self::lastSeenAt => $this->entity->last_seen_at,
            self::expiresAt => $this->entity->expires_at,
        ];
    }
}
