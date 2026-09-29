<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\Entity\Collection\UserMerges as EntityUserMerges;
use Hilos\Database\PhpType;

/**
 * Framework merge row: an account folded into another one - which, into which, when (HIL-1199).
 *
 * Keyed by the folded account, so an account is folded at most once and the row answers
 * "is this account merged" by key. A null `survivor_user_id` is a survivor whose account was
 * erased since - the folded account stays folded. A null `merged_at` is a row carried over
 * from a project's former column, which never recorded the moment. A project adds columns by
 * extending the whole ORM chain under the userMerges key (docs/agents/architecture/people-table.md).
 *
 * @method static EntityUserMerges get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityUserMerges getAll()
 */
class UserMerge extends Entity
{
    // Column name constants
    public const string user_id = 'user_id';
    public const string survivor_user_id = 'survivor_user_id';
    public const string merged_at = 'merged_at';

    // Table meta information
    public const string _table = 'hilos_user_merge';
    public const string _primary = self::user_id;
    public const array _columns = [
        self::user_id,
        self::survivor_user_id,
        self::merged_at,
    ];

    // Column types
    public const array _types = [
        self::user_id => PhpType::INTEGER->value,
        self::survivor_user_id => PhpType::INTEGER->value,
        self::merged_at => PhpType::DATETIME->value,
    ];

    // Foreign keys
    public const array _foreign = [
        self::user_id => User::_table,
        self::survivor_user_id => User::_table,
    ];

    // Indexes
    public const array _indexes = [
        'idx_user_merge_survivor' => [Entity::INDEX_COLUMNS => [self::survivor_user_id]],
    ];

    public const string _setVia = self::user_id;
    public const bool _setRoot = false;

    // Two account numbers and a moment: that one account was folded into another says
    // nothing about who the person is.
    public const array _pii = [];

    public const array _piiNotPersonal = [
        self::user_id,
        self::survivor_user_id,
        self::merged_at,
    ];

    // Properties
    public int $user_id;
    public ?int $survivor_user_id = null;
    public ?string $merged_at = null;
}
