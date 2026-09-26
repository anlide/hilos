<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Collection\Files as EntityFiles;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * File Entity - represents the hilos_file table row.
 *
 * One published file of the files registry (HIL-336): the name it is kept under in the
 * project's files directory, what the uploader called it, who owns it, who may be given it,
 * and whether the project has linked it yet. Framework holds the contract; projects activate
 * the table thinly (copy the migration stub) and the framework DbContext exposes the
 * collection.
 *
 * `owner_user_id` is a soft reference: the person table is still the project's (HIL-1133).
 *
 * @method static EntityFiles get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityFiles getAll()
 */
final class File extends Entity
{
    public const string id = 'id';
    public const string stored_name = 'stored_name';
    public const string filename = 'filename';
    public const string mime_type = 'mime_type';
    public const string size = 'size';
    public const string owner_user_id = 'owner_user_id';
    public const string visibility = 'visibility';
    public const string bound = 'bound';
    public const string created_at = 'created_at';

    public const string _table = 'hilos_file';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::stored_name,
        self::filename,
        self::mime_type,
        self::size,
        self::owner_user_id,
        self::visibility,
        self::bound,
        self::created_at,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::stored_name => PhpType::STRING->value,
        self::filename => PhpType::STRING->value,
        self::mime_type => PhpType::STRING->value,
        self::size => PhpType::INTEGER->value,
        self::owner_user_id => PhpType::INTEGER->value,
        self::visibility => PhpType::STRING->value,
        self::bound => PhpType::BOOLEAN->value,
        self::created_at => PhpType::DATETIME->value,
    ];

    public const array _indexes = [
        'uk_file_stored_name' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::stored_name]],
        'idx_file_bound_created' => [Entity::INDEX_COLUMNS => [self::bound, self::created_at]],
    ];

    // The owner is the NOT NULL column that names a person; nobody hangs a set on a file yet.
    public const string _setVia = self::owner_user_id;
    public const bool _setRoot = false;

    // The name a person gave the file can say who they are or what it is about; the rest
    // is the registry's own bookkeeping.
    public const array _pii = [
        self::filename => AnonymizationStrategy::MASK,
    ];

    public const array _piiNotPersonal = [
        self::id,
        self::stored_name,
        self::mime_type,
        self::size,
        self::owner_user_id,
        self::visibility,
        self::bound,
        self::created_at,
    ];

    public ?int $id = null;
    public string $stored_name;
    public string $filename;
    public string $mime_type;
    public int $size;
    public int $owner_user_id;
    public string $visibility;
    public bool $bound;
    public string $created_at;
}
