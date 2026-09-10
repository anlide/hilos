<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Auth\Registration\RegistrationReservationService;
use Hilos\Database\Entity\Collection\RegistrationReservations as EntityRegistrationReservations;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * RegistrationReservation Entity - represents hilos_registration_reservation table row.
 *
 * The durable half of reserve-on-submit registration (HIL-415): submitting the
 * registration form no longer creates an account, it RESERVES the identifier for
 * a TTL and sends one confirmation code; the account is created only when that
 * code comes back ({@see RegistrationReservationService}). The row holds exactly
 * what the challenge cannot: the address being held, and whether the code that
 * proves it has already come back.
 *
 * It is a table of its own rather than a column on `hilos_user_verification`
 * because holding a registration needs a UNIQUE key, which a challenge table can
 * never carry - consumed and expired challenges legitimately pile up per
 * identifier. That key is `session_token` (HIL-608): one browser leads one
 * registration at a time, so a submit of another address evicts this browser's
 * own previous hold, while `identifier` carries a plain index because several
 * browsers may legitimately be registering the same address at once. It is what
 * makes the hold OWNED - the reservation is landed by the session that started
 * it, so a letter answered in another browser cannot land somebody else's
 * password into the account it creates.
 *
 * The `code_accepted_at` column is the durable proof of the address (HIL-825).
 * The hold carries no credential at all: the password is asked for AFTER the
 * code, and the account, its identity and its password are written together when
 * that password is saved. So the column is an ordinary mapped one - it is a mark,
 * not a secret - and its durability is what puts a browser that proved an address
 * back on the password screen across a reload, a closed tab and a daemon restart.
 *
 * No DB-level foreign key: the reservation exists precisely while no user does,
 * and framework tables never FK across the framework/project boundary anyway.
 *
 * @method static EntityRegistrationReservations get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityRegistrationReservations getAll()
 */
final class RegistrationReservation extends Entity
{
    public const string id = 'id';
    public const string type = 'type';
    public const string identifier = 'identifier';
    public const string session_token = 'session_token';
    public const string code_accepted_at = 'code_accepted_at';
    public const string expires_at = 'expires_at';

    public const string _table = 'hilos_registration_reservation';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::type,
        self::identifier,
        self::session_token,
        self::code_accepted_at,
        self::expires_at,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::type => PhpType::STRING->value,
        self::identifier => PhpType::STRING->value,
        self::session_token => PhpType::STRING->value,
        self::code_accepted_at => PhpType::DATETIME->value,
        self::expires_at => PhpType::DATETIME->value,
    ];

    public const array _indexes = [
        'uk_reservation_session' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::session_token]],
        'idx_reservation_identifier' => [Entity::INDEX_COLUMNS => [self::identifier]],
        'idx_reservation_expires' => [Entity::INDEX_COLUMNS => [self::expires_at]],
    ];

    // A reservation lives before anybody exists, so it has no owner column at all.
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = false;

    // A held registration is a token-shaped row that expires on its own; a restored
    // copy has no use for one.
    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;

    public ?int $id = null;
    public string $type;
    public string $identifier;
    public string $session_token;
    public ?string $code_accepted_at = null;
    public string $expires_at;
}
