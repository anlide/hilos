<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Collection\DataExports as EntityDataExports;
use Hilos\Database\PhpType;

/**
 * One requested or finished data copy per person (HIL-303).
 *
 * @method static EntityDataExports get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityDataExports getAll()
 */
class DataExport extends Entity
{
    public const string id = 'id';
    public const string user_id = 'user_id';
    public const string state = 'state';
    public const string requested_at = 'requested_at';
    public const string finished_at = 'finished_at';
    public const string expires_at = 'expires_at';
    public const string stored_name = 'stored_name';
    public const string size_bytes = 'size_bytes';

    public const string _table = 'hilos_data_export';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::user_id,
        self::state,
        self::requested_at,
        self::finished_at,
        self::expires_at,
        self::stored_name,
        self::size_bytes,
    ];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::user_id => PhpType::INTEGER->value,
        self::state => PhpType::STRING->value,
        self::requested_at => PhpType::DATETIME->value,
        self::finished_at => PhpType::DATETIME->value,
        self::expires_at => PhpType::DATETIME->value,
        self::stored_name => PhpType::STRING->value,
        self::size_bytes => PhpType::INTEGER->value,
    ];
    public const array _foreign = [
        self::user_id => User::_table,
    ];

    public const array _indexes = [
        'uq_data_export_user' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::user_id]],
        'idx_data_export_state' => [Entity::INDEX_COLUMNS => [self::state, self::requested_at]],
        'idx_data_export_expires' => [Entity::INDEX_COLUMNS => [self::expires_at]],
    ];
    public const string _setVia = self::user_id;
    public const bool _setRoot = false;
    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;

    public ?int $id = null;
    public int $user_id;
    public string $state;
    public string $requested_at;
    public ?string $finished_at = null;
    public ?string $expires_at = null;
    public ?string $stored_name = null;
    public ?int $size_bytes = null;
}
