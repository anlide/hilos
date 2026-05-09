<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Entity\Item;

use Demo\Chat\Database\Entity\Collection\EventUserRenames as EntityEventUserRenames;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * EventUserRename - Entity representing event_user_rename table row.
 *
 * Stores scalar payload for user rename chat events.
 *
 * @method static EntityEventUserRenames get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityEventUserRenames getAll()
 */
final class EventUserRename extends Entity
{
    public const string event_id = 'event_id';
    public const string target_user_id = 'target_user_id';
    public const string actor_user_id = 'actor_user_id';
    public const string old_name = 'old_name';
    public const string new_name = 'new_name';

    public const string _table = 'event_user_rename';
    public const string _primary = self::event_id;
    public const array _columns = [
        self::event_id,
        self::target_user_id,
        self::actor_user_id,
        self::old_name,
        self::new_name,
    ];

    public const array _types = [
        self::event_id => PhpType::INTEGER->value,
        self::target_user_id => PhpType::INTEGER->value,
        self::actor_user_id => PhpType::INTEGER->value,
        self::old_name => PhpType::STRING->value,
        self::new_name => PhpType::STRING->value,
    ];

    public const array _foreign = [
        self::event_id => Event::_table,
        self::target_user_id => User::_table,
        self::actor_user_id => User::_table,
    ];

    public const array _indexes = [
        'target_user_id' => [Entity::INDEX_COLUMNS => [self::target_user_id]],
        'actor_user_id' => [Entity::INDEX_COLUMNS => [self::actor_user_id]],
    ];

    public int $event_id;
    public int $target_user_id;
    public ?int $actor_user_id = null;
    public string $old_name = '';
    public string $new_name = '';
}
