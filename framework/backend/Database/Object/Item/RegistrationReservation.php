<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\RegistrationReservation as EntityRegistrationReservation;
use Hilos\Database\Object\Collection\Identities;
use Hilos\Database\Object\Collection\RegistrationReservations;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;

/**
 * RegistrationReservation object - wraps RegistrationReservation entity.
 *
 * Exposes the reservation's non-secret fields and the hold/extend/read-credential
 * primitives. The `secret` is never exposed as a property, in toArray(), or over
 * the DB sync bus; it is minted by
 * {@see RegistrationReservations::createReservation()} and read only by
 * {@see readSecretHash()}, at the single moment the confirmed reservation becomes
 * an identity.
 *
 * @extends Object_<EntityRegistrationReservation>
 *
 * @property-read ?int $id
 * @property string $type
 * @property string $identifier
 * @property string $sessionToken
 * @property string $expiresAt
 */
final class RegistrationReservation extends Object_
{
    public const string ENTITY_CLASS = EntityRegistrationReservation::class;
    public const string id = 'id';
    public const string type = 'type';
    public const string identifier = 'identifier';
    public const string sessionToken = 'sessionToken';
    public const string expiresAt = 'expiresAt';

    /**
     * Returns the database collection key.
     *
     * @return string Collection key (HilosDbContext::registrationReservations)
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::registrationReservations;
    }

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Property name (id, type, identifier, sessionToken, expiresAt)
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known RegistrationReservation field
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::type => $this->entity->type,
            self::identifier => $this->entity->identifier,
            self::sessionToken => $this->entity->session_token,
            self::expiresAt => $this->entity->expires_at,
            default => parent::__get($property),
        };
    }

    /**
     * Magic setter for entity properties.
     *
     * The `secret` has no setter here; it is written only through the reservation
     * layer's mint path ({@see RegistrationReservations::createReservation()}).
     *
     * @param string $property Property name (type, identifier, sessionToken, expiresAt)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on a RegistrationReservation
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::type => $this->entity->type = (string)$value,
            self::identifier => $this->entity->identifier = (string)$value,
            self::sessionToken => $this->entity->session_token = (string)$value,
            self::expiresAt => $this->entity->expires_at = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Whether this reservation still holds its identifier at the given moment.
     *
     * A reservation has no consumed state - confirming it deletes it - so holding
     * is purely a question of expiry. Datetime comparison is lexicographic on the
     * SQL 'Y-m-d H:i:s' format, which orders the same as chronological order.
     *
     * @param string $nowSql Current time as an SQL datetime string
     * @return bool True while the reservation still holds the identifier
     */
    public function isActive(string $nowSql): bool
    {
        return $this->entity->expires_at > $nowSql;
    }

    /**
     * Reads the credential hash this reservation carries.
     *
     * Confirm primitive, symmetric with the identity layer's
     * {@see Identity::verifyPassword()}: the hash is read with a targeted query
     * (it is not ORM-mapped) and handed straight to
     * {@see Identities::createPasswordIdentityWithHash()}, so the credential moves
     * from the reservation into the identity without ever being re-hashed, held in
     * plaintext, or crossing the object/view/sync surface. Returns null for an
     * unpersisted reservation and for a method that reserved without a credential.
     *
     * @return ?string Stored bcrypt hash, or null when the reservation carries none
     * @throws DatabaseException When the secret lookup query fails
     */
    public function readSecretHash(): ?string
    {
        if ($this->entity->id === null) {
            return null;
        }

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($this->entity->id));
        $resultSet = Database::sql(
            'SELECT `' . EntityRegistrationReservation::secret . '` FROM `' . EntityRegistrationReservation::_table
                . '` WHERE `' . EntityRegistrationReservation::id . '` = ?',
            $params,
        )->first();
        if ($resultSet === null) {
            return null;
        }

        $row = $resultSet->first();
        if ($row === null) {
            return null;
        }
        $secret = $row[EntityRegistrationReservation::secret] ?? null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * Pushes this reservation's expiry out to a later moment.
     *
     * Resend primitive: a re-sent code outlives the reservation that carried the
     * previous one, so the hold is extended to the new code's expiry rather than
     * freeing the address under a person who is still reading their inbox. Written
     * with a targeted UPDATE and mirrored on the loaded entity, the same split
     * {@see UserVerification::consume()} uses. A no-op for an unpersisted
     * reservation.
     *
     * @param string $expiresAtSql New expiry as an SQL datetime string
     * @throws DatabaseException When the expiry update query fails
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function extendTo(string $expiresAtSql): void
    {
        if ($this->entity->id === null) {
            return;
        }

        DbWriteGuard::guardItemWrite(
            static::getCollectionKey(),
            (string)$this->entity->id,
            TruthSourceOperation::Update,
        );

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::string($expiresAtSql));
        $params->add(SqlParam::int($this->entity->id));
        Database::sql(
            'UPDATE `' . EntityRegistrationReservation::_table . '` SET `' . EntityRegistrationReservation::expires_at
                . '` = ? WHERE `' . EntityRegistrationReservation::id . '` = ?',
            $params,
        );

        $this->entity->expires_at = $expiresAtSql;
    }

    /**
     * Converts the reservation to an associative array (never includes the secret).
     *
     * @return array<string, mixed> Reservation data (id, type, identifier, sessionToken, expiresAt)
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::type => $this->entity->type,
            self::identifier => $this->entity->identifier,
            self::sessionToken => $this->entity->session_token,
            self::expiresAt => $this->entity->expires_at,
        ];
    }
}
