<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Entity\Item;

use Demo\Chat\Database\Entity\Collection\Users as EntityUsers;
use Hilos\Database\Entity\Item\User as FrameworkUser;
use Hilos\Database\PhpType;

/**
 * Chat's person extends the framework row with its merge tombstone until HIL-1199.
 *
 * @method static EntityUsers get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityUsers getAll()
 */
final class User extends FrameworkUser
{
    public const string merged_into = 'merged_into';

    public const array _columns = [...parent::_columns, self::merged_into];
    public const array _types = [...parent::_types, self::merged_into => PhpType::INTEGER->value];
    public const array _indexes = [
        ...parent::_indexes,
        'merged_into' => [self::INDEX_COLUMNS => [self::merged_into]],
    ];
    public const array _piiNotPersonal = [...parent::_piiNotPersonal, self::merged_into];

    public ?int $merged_into = null;
}
