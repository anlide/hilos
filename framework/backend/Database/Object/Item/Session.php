<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Session as EntitySession;
use Hilos\Database\Object\Item\Object_;

/**
 * Session - Object wrapper for the hilos_session entity.
 *
 * @extends Object_<EntitySession>
 *
 * @property-read ?int $id
 * @property string $token
 * @property ?int $userId
 * @property ?int $impersonatorUserId
 * @property string $createdAt
 * @property string $lastSeenAt
 * @property ?string $expiresAt
 * @property ?string $pendingRegistrationIdentifier
 * @property ?string $pendingRegistrationSince
 * @property ?string $pendingAck
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
    public const string pendingRegistrationIdentifier = 'pendingRegistrationIdentifier';
    public const string pendingRegistrationSince = 'pendingRegistrationSince';
    public const string pendingAck = 'pendingAck';

    /**
     * Returns the database collection key for this object type.
     *
     * @return string Collection key (HilosDbContext::sessions)
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::sessions;
    }

    /**
     * Returns the value of a session object property by name.
     *
     * @param string $property Property name (id, token, userId, impersonatorUserId, createdAt,
     *     lastSeenAt, expiresAt, pendingRegistrationIdentifier, pendingRegistrationSince, pendingAck)
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
            self::pendingRegistrationIdentifier => $this->entity->pending_registration_identifier,
            self::pendingRegistrationSince => $this->entity->pending_registration_since,
            self::pendingAck => $this->entity->pending_ack,
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
            self::createdAt => $this->entity->created_at = (string)$value,
            self::lastSeenAt => $this->entity->last_seen_at = (string)$value,
            self::expiresAt => $this->entity->expires_at = is_scalar($value) ? (string)$value : null,
            self::pendingRegistrationIdentifier => $this->entity->pending_registration_identifier
                = is_scalar($value) ? (string)$value : null,
            self::pendingRegistrationSince => $this->entity->pending_registration_since
                = is_scalar($value) ? (string)$value : null,
            self::pendingAck => $this->entity->pending_ack = is_scalar($value) ? (string)$value : null,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the session object to an associative array with its non-marker fields.
     *
     * `impersonatorUserId`, the `pendingRegistration*` pair and `pendingAck` are
     * intentionally excluded: all three are read-legal server-side (guards and the session
     * host read them via {@see __get}) but are kept off the browser-sync projection — the
     * impersonating state, the unfinished registration and the announcement the session
     * still owes are surfaced to the frontend through the session state frame and the
     * handshake response, not this row.
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
