<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\Entity\Collection\FileVariants as EntityFileVariants;
use Hilos\Database\PhpType;

/**
 * One rendered copy of a registry file, owned by the files library (HIL-141).
 *
 * @method static EntityFileVariants get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityFileVariants getAll()
 */
final class FileVariant extends Entity
{
    public const string id = 'id';
    public const string file_id = 'file_id';
    public const string variant = 'variant';
    public const string signature = 'signature';
    public const string stored_name = 'stored_name';
    public const string mime_type = 'mime_type';
    public const string size = 'size';
    public const string created_at = 'created_at';

    public const string _table = 'hilos_file_variant';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::file_id,
        self::variant,
        self::signature,
        self::stored_name,
        self::mime_type,
        self::size,
        self::created_at,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::file_id => PhpType::INTEGER->value,
        self::variant => PhpType::STRING->value,
        self::signature => PhpType::STRING->value,
        self::stored_name => PhpType::STRING->value,
        self::mime_type => PhpType::STRING->value,
        self::size => PhpType::INTEGER->value,
        self::created_at => PhpType::DATETIME->value,
    ];

    public const array _foreign = [self::file_id => File::_table];

    public const array _indexes = [
        'uk_file_variant' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::file_id, self::variant]],
        'uk_file_variant_stored_name' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::stored_name]],
    ];

    public const string _setVia = self::file_id;
    public const bool _setRoot = false;

    // Settings names and fingerprints, random storage names and byte counts describe no person.
    public const array _pii = [];
    public const array _piiNotPersonal = [
        self::id,
        self::file_id,
        self::variant,
        self::signature,
        self::stored_name,
        self::mime_type,
        self::size,
        self::created_at,
    ];

    public ?int $id = null;
    public int $file_id;
    public string $variant;
    public string $signature;
    public string $stored_name;
    public string $mime_type;
    public int $size;
    public string $created_at;
}
