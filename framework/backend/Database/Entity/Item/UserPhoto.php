<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Database\Entity\Collection\UserPhotos as EntityUserPhotos;
use Hilos\Database\PhpType;

/**
 * One published profile photo for a person, linked to its registry file.
 *
 * @method static EntityUserPhotos get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityUserPhotos getAll()
 */
class UserPhoto extends Entity
{
    public const string user_id = 'user_id';
    public const string file_id = 'file_id';
    public const string set_at = 'set_at';

    public const string _table = 'hilos_user_photo';
    public const string _primary = self::user_id;
    public const array _columns = [self::user_id, self::file_id, self::set_at];
    public const array _types = [
        self::user_id => PhpType::INTEGER->value,
        self::file_id => PhpType::INTEGER->value,
        self::set_at => PhpType::DATETIME->value,
    ];
    public const array _foreign = [
        self::user_id => User::_table,
        self::file_id => File::_table,
    ];
    public const array _indexes = [
        'uk_user_photo_file' => [Entity::INDEX_UNIQUE => true, Entity::INDEX_COLUMNS => [self::file_id]],
    ];

    public const string _setVia = self::user_id;
    public const bool _setRoot = false;

    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;

    public int $user_id;
    public int $file_id;
    public string $set_at;
}
