<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Entity\Item;

use Demo\Chat\Database\Entity\Collection\UserRenames as EntityUserRenames;
use Hilos\Database\Entity\Item\UserRename as FrameworkUserRename;
use Hilos\Database\PhpType;

/**
 * Chat's rename journal row: the framework's, plus the event of the room's feed that shows it (HIL-1196).
 *
 * `event_id` is null until the feed line is written, and again once the room's history is
 * cleared: the journal is the person's history and outlives the feed. One row per event, so the
 * feed joins the row by its event.
 *
 * @method static EntityUserRenames get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityUserRenames getAll()
 */
final class UserRename extends FrameworkUserRename
{
    public const string event_id = 'event_id';

    public const array _columns = [...parent::_columns, self::event_id];
    public const array _types = [...parent::_types, self::event_id => PhpType::INTEGER->value];
    public const array _foreign = [...parent::_foreign, self::event_id => Event::_table];
    public const array _indexes = [
        ...parent::_indexes,
        'uk_user_rename_event' => [self::INDEX_UNIQUE => true, self::INDEX_COLUMNS => [self::event_id]],
    ];
    public const array _piiNotPersonal = [...parent::_piiNotPersonal, self::event_id];

    public ?int $event_id = null;
}
