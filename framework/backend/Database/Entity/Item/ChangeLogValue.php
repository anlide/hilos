<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Collection\ChangeLogValues as EntityChangeLogValues;
use Hilos\Database\PhpType;

/**
 * @method static EntityChangeLogValues get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityChangeLogValues getAll()
 */
class ChangeLogValue extends Entity
{
    public const string id = 'id';
    public const string created_at = 'created_at';
    public const string change_id = 'change_id';
    public const string old_value = 'old_value';
    public const string new_value = 'new_value';

    public const string _table = 'hilos_change_log_value';
    public const string _database = DatabaseNames::CHANGE_LOG;
    public const array _primary = [self::id, self::created_at];
    public const array _columns = [
        self::id,
        self::created_at,
        self::change_id,
        self::old_value,
        self::new_value,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::created_at => PhpType::DATETIME->value,
        self::change_id => PhpType::INTEGER->value,
        self::old_value => PhpType::TEXT->value,
        self::new_value => PhpType::TEXT->value,
    ];

    public const array _indexes = [
        'uk_hcl_value_change' => [
            Entity::INDEX_COLUMNS => [self::change_id, self::created_at],
            Entity::INDEX_UNIQUE => true,
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
        self::change_id,
    ];

    public ?int $id = null;
    public string $created_at;
    public int $change_id;
    public ?string $old_value = null;
    public ?string $new_value = null;
}
