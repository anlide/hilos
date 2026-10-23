<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Collection\ChangeLogEntries as EntityChangeLogEntries;
use Hilos\Database\PhpType;

/**
 * @method static EntityChangeLogEntries get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityChangeLogEntries getAll()
 */
class ChangeLogEntry extends Entity
{
    public const string id = 'id';
    public const string created_at = 'created_at';
    public const string receipt_id = 'receipt_id';
    public const string table_id = 'table_id';
    public const string record_key = 'record_key';
    public const string record_key_hash = 'record_key_hash';
    public const string mutation_type = 'mutation_type';

    public const string _table = 'hilos_change_log';
    public const string _database = DatabaseNames::CHANGE_LOG;
    public const array _primary = [self::id, self::created_at];
    public const array _columns = [
        self::id,
        self::created_at,
        self::receipt_id,
        self::table_id,
        self::record_key,
        self::record_key_hash,
        self::mutation_type,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::created_at => PhpType::DATETIME->value,
        self::receipt_id => PhpType::INTEGER->value,
        self::table_id => PhpType::INTEGER->value,
        self::record_key => PhpType::TEXT->value,
        self::record_key_hash => PhpType::BINARY->value,
        self::mutation_type => PhpType::STRING->value,
    ];

    public const array _indexes = [
        'idx_hcl_receipt' => [
            Entity::INDEX_COLUMNS => [self::receipt_id, self::created_at, self::id],
        ],
        'idx_hcl_table_created' => [
            Entity::INDEX_COLUMNS => [self::table_id, self::created_at, self::id],
        ],
        'idx_hcl_table_record' => [
            Entity::INDEX_COLUMNS => [self::table_id, self::record_key_hash, self::created_at, self::id],
        ],
    ];

    public const bool _journaled = false;
    public const bool _externalWriter = true;
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = false;

    public const array _pii = [
        self::record_key => AnonymizationStrategy::MASK,
        self::record_key_hash => AnonymizationStrategy::NULLIFY,
    ];

    public const array _piiNotPersonal = [
        self::id,
        self::created_at,
        self::receipt_id,
        self::table_id,
        self::mutation_type,
    ];

    public ?int $id = null;
    public string $created_at;
    public ?int $receipt_id = null;
    public int $table_id;
    public string $record_key;
    public ?string $record_key_hash = null;
    public string $mutation_type;
}
