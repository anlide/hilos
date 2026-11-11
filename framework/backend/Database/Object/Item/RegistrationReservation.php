<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\RegistrationReservation as EntityRegistrationReservation;
use Hilos\Database\Object\Collection\RegistrationReservations as ObjectRegistrationReservations;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;

/**
 * RegistrationReservation object - wraps RegistrationReservation entity.
 *
 * Exposes the reservation's fields and the hold/extend/prove primitives. The hold
 * carries no credential (HIL-825): the password is asked for after the code, so
 * what a proved hold remembers is the moment the code came back, and that is an
 * ordinary mapped column rather than a secret.
 *
 * @extends Object_<EntityRegistrationReservation>
 *
 * @property-read ?int $id
 * @property string $type
 * @property string $identifier
 * @property string $sessionToken
 * @property-read ?int $codeAcceptedAt
 * @property ?string $acceptedRevisions
 * @property int $expiresAt
 * @property int $createdAt
 */
class RegistrationReservation extends Object_
{
    public const string ENTITY_CLASS = EntityRegistrationReservation::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectRegistrationReservations::class;
    public const string id = 'id';
    public const string type = 'type';
    public const string identifier = 'identifier';
    public const string sessionToken = 'sessionToken';
    public const string codeAcceptedAt = 'codeAcceptedAt';
    public const string acceptedRevisions = 'acceptedRevisions';
    public const string expiresAt = 'expiresAt';
    public const string createdAt = 'createdAt';

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Property name (id, type, identifier, sessionToken, codeAcceptedAt, acceptedRevisions, expiresAt, createdAt)
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
            self::codeAcceptedAt => $this->entity->code_accepted_at,
            self::acceptedRevisions => $this->entity->accepted_revisions,
            self::expiresAt => $this->entity->expires_at,
            self::createdAt => $this->entity->created_at,
            default => parent::__get($property),
        };
    }

    /**
     * Magic setter for entity properties.
     *
     * The proof mark has no setter here; it is written by {@see markCodeAccepted()},
     * which is the only place allowed to say an address was proved.
     *
     * @param string $property Property name (type, identifier, sessionToken, acceptedRevisions, expiresAt, createdAt)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on a RegistrationReservation
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::type => $this->entity->type = (string)$value,
            self::identifier => $this->entity->identifier = (string)$value,
            self::sessionToken => $this->entity->session_token = (string)$value,
            self::acceptedRevisions => $this->entity->accepted_revisions = $value,
            self::expiresAt => $this->entity->expires_at = (int)$value,
            self::createdAt => $this->entity->created_at = (int)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * @return ?array<string, string> Persisted boundary map, or null for a hold predating consent
     */
    public function acceptedRevisions(): ?array
    {
        if ($this->entity->accepted_revisions === null) {
            return null;
        }
        $accepted = json_decode($this->entity->accepted_revisions, true);

        return is_array($accepted) ? $accepted : null;
    }

    /**
     * Whether this reservation still holds its identifier at the given moment.
     *
     * A reservation has no consumed state - confirming it deletes it - so holding
     * is purely a question of expiry.
     *
     * @param int $nowMs Current moment in epoch milliseconds
     * @return bool True while the reservation still holds the identifier
     */
    public function isActive(int $nowMs): bool
    {
        return $this->entity->expires_at > $nowMs;
    }

    /**
     * Whether the code that proves this hold's address has already come back.
     *
     * The question the password step stands on (HIL-825): a proved hold is what a
     * returning tab is put back on the password screen by, and an unproved one still
     * names the code screen. It is asked of the row rather than of a live code
     * because the code is spent the moment it is accepted - the proof moved onto the
     * hold precisely so that spending it breaks nothing.
     *
     * @return bool True once {@see markCodeAccepted()} has written the proof
     */
    public function isProven(): bool
    {
        return $this->entity->code_accepted_at !== null;
    }

    /**
     * Marks this hold's address as proved by an accepted code.
     *
     * Confirm primitive of the two-step registration (HIL-825): the account is not
     * created here, the address is only proved, and the proof is durable so the
     * password step survives a reload, a closed tab and a daemon restart. Written
     * with a targeted UPDATE and mirrored on the loaded entity, the same split
     * {@see extendTo()} uses. A no-op for an unpersisted reservation.
     *
     * @param int $acceptedAtMs Moment the code was accepted, in epoch milliseconds
     * @throws DatabaseException When the proof update query fails
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function markCodeAccepted(int $acceptedAtMs): void
    {
        if ($this->entity->id === null) {
            return;
        }

        DbWriteGuard::guardItemWrite(
            static::getCollectionKey(),
            (string)$this->entity->id,
            $this->touchedSetKeys(...),
            TruthSourceOperation::Update,
        );

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($acceptedAtMs));
        $params->add(SqlParam::int($this->entity->id));
        Database::sql(
            'UPDATE `' . EntityRegistrationReservation::_table . '` SET `'
                . EntityRegistrationReservation::code_accepted_at
                . '` = ? WHERE `' . EntityRegistrationReservation::id . '` = ?',
            $params,
        );

        $this->entity->code_accepted_at = $acceptedAtMs;
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
     * @param int $expiresAtMs New expiry in epoch milliseconds
     * @throws DatabaseException When the expiry update query fails
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function extendTo(int $expiresAtMs): void
    {
        if ($this->entity->id === null) {
            return;
        }

        DbWriteGuard::guardItemWrite(
            static::getCollectionKey(),
            (string)$this->entity->id,
            $this->touchedSetKeys(...),
            TruthSourceOperation::Update,
        );

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($expiresAtMs));
        $params->add(SqlParam::int($this->entity->id));
        Database::sql(
            'UPDATE `' . EntityRegistrationReservation::_table . '` SET `' . EntityRegistrationReservation::expires_at
                . '` = ? WHERE `' . EntityRegistrationReservation::id . '` = ?',
            $params,
        );

        $this->entity->expires_at = $expiresAtMs;
    }

    /**
     * Converts the reservation to an associative array.
     *
     * @return array<string, mixed> Reservation data (id, type, identifier, sessionToken, codeAcceptedAt, acceptedRevisions, expiresAt, createdAt)
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::type => $this->entity->type,
            self::identifier => $this->entity->identifier,
            self::sessionToken => $this->entity->session_token,
            self::codeAcceptedAt => $this->entity->code_accepted_at,
            self::acceptedRevisions => $this->entity->accepted_revisions,
            self::expiresAt => $this->entity->expires_at,
            self::createdAt => $this->entity->created_at,
        ];
    }
}
