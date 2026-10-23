<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Collection\ChangeLogChanges as EntityChangeLogChanges;
use Hilos\Database\PhpType;

/**
 * @method static EntityChangeLogChanges get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityChangeLogChanges getAll()
 */
class ChangeLogChange extends Entity
{
    public const string id = 'id';
    public const string created_at = 'created_at';
    public const string log_id = 'log_id';
    public const string field_id = 'field_id';
    public const string kind = 'kind';
    public const string old_present = 'old_present';
    public const string new_present = 'new_present';
    public const string old_value = 'old_value';
    public const string new_value = 'new_value';

    public const string _table = 'hilos_change_log_change';
    public const string _database = DatabaseNames::CHANGE_LOG;
    public const array _primary = [self::id, self::created_at];
    public const array _columns = [
        self::id,
        self::created_at,
        self::log_id,
        self::field_id,
        self::kind,
        self::old_present,
        self::new_present,
        self::old_value,
        self::new_value,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::created_at => PhpType::DATETIME->value,
        self::log_id => PhpType::INTEGER->value,
        self::field_id => PhpType::INTEGER->value,
        self::kind => PhpType::STRING->value,
        self::old_present => PhpType::BOOLEAN->value,
        self::new_present => PhpType::BOOLEAN->value,
        self::old_value => PhpType::TEXT->value,
        self::new_value => PhpType::TEXT->value,
    ];

    public const array _indexes = [
        'idx_hcl_change_log' => [
            Entity::INDEX_COLUMNS => [self::log_id, self::created_at, self::id],
        ],
        'idx_hcl_change_field' => [
            Entity::INDEX_COLUMNS => [self::field_id, self::created_at, self::log_id],
        ],
    ];

    public const bool _journaled = false;
    public const bool _externalWriter = true;
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = false;

    public const array _pii = [
        self::old_value => AnonymizationStrategy::MASK,
        self::new_value => AnonymizationStrategy::MASK,
    ];

    public const array _piiNotPersonal = [
        self::id,
        self::created_at,
        self::log_id,
        self::field_id,
        self::kind,
        self::old_present,
        self::new_present,
    ];

    public ?int $id = null;
    public string $created_at;
    public int $log_id;
    public int $field_id;
    public string $kind;
    public bool $old_present;
    public bool $new_present;
    public ?string $old_value = null;
    public ?string $new_value = null;
}
