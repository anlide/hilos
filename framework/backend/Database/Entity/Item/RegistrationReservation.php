<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Database\Entity\Collection\RegistrationReservations as EntityRegistrationReservations;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\RegistrationReservation as ObjectRegistrationReservation;
use Hilos\Database\PhpType;

/**
 * RegistrationReservation Entity - represents hilos_registration_reservation table row.
 *
 * The durable half of reserve-on-submit registration (HIL-415): submitting the
 * registration form no longer creates an account, it RESERVES the identifier for
 * a TTL and sends one confirmation code; the account is created only when that
 * code comes back ({@see RegistrationReservationService}). The row holds exactly
 * what the challenge cannot: the address being held and the credential chosen
 * for the account that does not exist yet.
 *
 * It is a table of its own rather than a column on `hilos_user_verification`
 * because holding an address needs UNIQUE(identifier), which a challenge table
 * can never carry - consumed and expired challenges legitimately pile up per
 * identifier. Uniqueness is on the identifier ALONE, not on (type, identifier)
 * as on `hilos_identity`: one address is one reservation and one code whatever
 * method reserved it, otherwise a password and a magic-link submit would hold
 * the same address and mail two codes for it.
 *
 * The `secret` column (bcrypt hash of the chosen password; NULL for the methods
 * that carry no credential) is DB-only: it is intentionally absent from _columns
 * and from the object/view ORM layer, and is read only through
 * ({@see ObjectRegistrationReservation::readSecretHash()}) when the confirmed
 * reservation is turned into an identity. The hash never crosses the object,
 * view, frontend, or cross-worker sync boundary.
 *
 * No DB-level foreign key: the reservation exists precisely while no user does,
 * and framework tables never FK across the framework/project boundary anyway.
 *
 * @object-exclude secret
 *
 * @method static EntityRegistrationReservations get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityRegistrationReservations getAll()
 */
final class RegistrationReservation extends Entity
{
    public const string id = 'id';
    public const string type = 'type';
    public const string identifier = 'identifier';
    /** DB-only credential column (see @object-exclude); referenced by the confirm query, never ORM-mapped. */
    public const string secret = 'secret';
    public const string expires_at = 'expires_at';

    public const string _table = 'hilos_registration_reservation';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::type,
        self::identifier,
        self::expires_at,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::type => PhpType::STRING->value,
        self::identifier => PhpType::STRING->value,
        self::expires_at => PhpType::DATETIME->value,
    ];

    public const array _indexes = [
        'uk_reservation_identifier' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::identifier]],
        'idx_reservation_expires' => [Entity::INDEX_COLUMNS => [self::expires_at]],
    ];

    public ?int $id = null;
    public string $type;
    public string $identifier;
    public string $expires_at;
}
