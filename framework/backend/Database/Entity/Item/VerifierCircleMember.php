<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Auth\Session\SessionCarrier;
use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Collection\VerifierCircleMembers as EntityVerifierCircleMembers;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\PhpType;

/**
 * VerifierCircleMember Entity - represents the hilos_verifier_circle table row.
 *
 * One person an operator named as a verifier of the system after a restore (HIL-643).
 * The row names them by the same (type, identifier) pair {@see EntityIdentity} is keyed
 * by, and for the same reason {@see SessionCarrier} carries a person across a database
 * replacement by pairs: the circle itself lives in the database a restore rewrites, so a
 * `user_id` stored here would name a different person once the archive is in place. The
 * pair is resolved to a number afresh every time it is read, which is why the table has
 * no `user_id` column at all.
 *
 * `(identity_type, identifier)` is UNIQUE, and that index is the whole idempotency gate:
 * naming the same address twice is refused by the database rather than by a read before
 * the write, because two admin tabs fit between such a read and its insert.
 *
 * `created_at` is DB-maintained and, like created/updated columns elsewhere, is
 * intentionally not ORM-mapped: nothing reads it. It is named as a constant only so the
 * personal-data verdict below can cover it.
 *
 * Framework holds the contract; a project that declares the backup feature activates the
 * table thinly (copy the migration stub) and the framework DbContext exposes the
 * collection.
 *
 * @method static EntityVerifierCircleMembers get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityVerifierCircleMembers getAll()
 */
final class VerifierCircleMember extends Entity
{
    public const string id = 'id';
    public const string identity_type = 'identity_type';
    public const string identifier = 'identifier';
    /** DB-only stamp written by the database itself; named here so the PII verdict can name it. */
    public const string created_at = 'created_at';

    public const string _table = 'hilos_verifier_circle';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::identity_type,
        self::identifier,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::identity_type => PhpType::STRING->value,
        self::identifier => PhpType::STRING->value,
    ];

    public const array _indexes = [
        'uk_verifier_circle_identity' => [
            Entity::INDEX_UNIQUE => true,
            Entity::INDEX_COLUMNS => [self::identity_type, self::identifier],
        ],
    ];

    // A member is named by an identity pair and belongs to nobody's set: the circle is a
    // list of the installation's own, not part of any one account's data.
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = false;

    // The verdict is Identity's own, on the same column with the same content: the address
    // a person is called by. Inherited literally, including the case of a phone number in
    // it, so the two tables cannot disagree about the same string.
    public const array _pii = [
        self::identifier => AnonymizationStrategy::FAKE_EMAIL,
    ];

    // `identity_type` names a login method and nobody in particular.
    public const array _piiNotPersonal = [
        self::id,
        self::identity_type,
        self::created_at,
    ];

    public ?int $id = null;
    public string $identity_type;
    public string $identifier;
}
