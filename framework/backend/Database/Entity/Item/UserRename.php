<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Collection\UserRenames as EntityUserRenames;
use Hilos\Database\PhpType;

/**
 * Framework rename journal row: who was renamed, by whom, from what, to what, when (HIL-1195).
 *
 * `renamed_by_user_id` is the person who did the rename - the renamed person's own id when
 * they renamed themselves - and null when the author is not a person; rows carried over from a
 * project's former journal are null there too, and so is a row whose author was erased. A
 * project adds columns by extending the whole ORM chain under the userRenames key
 * (docs/agents/architecture/people-table.md).
 *
 * @method static EntityUserRenames get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityUserRenames getAll()
 */
class UserRename extends Entity
{
    // Column name constants
    public const string id = 'id';
    public const string user_id = 'user_id';
    public const string renamed_by_user_id = 'renamed_by_user_id';
    public const string old_name = 'old_name';
    public const string new_name = 'new_name';
    public const string renamed_at = 'renamed_at';

    // Table meta information
    public const string _table = 'hilos_user_rename';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::user_id,
        self::renamed_by_user_id,
        self::old_name,
        self::new_name,
        self::renamed_at,
    ];

    // Column types
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::renamed_by_user_id => PhpType::INTEGER->value,
        self::old_name => PhpType::STRING->value,
        self::new_name => PhpType::STRING->value,
        self::renamed_at => PhpType::DATETIME->value,
    ];

    // Foreign keys
    public const array _foreign = [
        self::user_id => User::_table,
        self::renamed_by_user_id => User::_table,
    ];

    // Indexes
    public const array _indexes = [
        'idx_user_rename_user' => [Entity::INDEX_COLUMNS => [self::user_id]],
        'idx_user_rename_renamed_by' => [Entity::INDEX_COLUMNS => [self::renamed_by_user_id]],
    ];

    public const string _setVia = self::user_id;
    public const bool _setRoot = false;

    // Both names are the person's, but two fake-name columns of one row would both read
    // `User <pk>`: the new name is faked and the old one masked, so a rename stays a change.
    public const array _pii = [
        self::old_name => AnonymizationStrategy::MASK,
        self::new_name => AnonymizationStrategy::FAKE_NAME,
    ];

    public const array _piiNotPersonal = [
        self::id,
        self::user_id,
        self::renamed_by_user_id,
        self::renamed_at,
    ];

    // Properties
    public ?int $id = null;
    public int $user_id;
    public ?int $renamed_by_user_id = null;
    public string $old_name = '';
    public string $new_name = '';
    public string $renamed_at;
}
