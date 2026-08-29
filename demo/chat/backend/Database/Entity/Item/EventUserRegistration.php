<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Entity\Item;

use Demo\Chat\Database\Entity\Collection\EventUserRegistrations as EntityEventUserRegistrations;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * EventUserRegistration - Entity representing event_user_registration table row.
 *
 * Stores scalar payload for user_registered chat events.
 *
 * @method static EntityEventUserRegistrations get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityEventUserRegistrations getAll()
 */
final class EventUserRegistration extends Entity
{
    public const string event_id = 'event_id';
    public const string target_user_id = 'target_user_id';

    public const string _table = 'event_user_registration';
    public const string _primary = self::event_id;
    public const array _columns = [
        self::event_id,
        self::target_user_id,
    ];

    public const array _types = [
        self::event_id => PhpType::INTEGER->value,
        self::target_user_id => PhpType::INTEGER->value,
    ];

    public const array _foreign = [
        self::event_id => Event::_table,
        self::target_user_id => User::_table,
    ];

    public const array _indexes = [
        'target_user_id' => [Entity::INDEX_COLUMNS => [self::target_user_id]],
    ];

    // Two keys and nothing else; the name behind the key is anonymized on the user row.
    public const array _pii = [];

    public const array _piiNotPersonal = [
        self::event_id,
        self::target_user_id,
    ];

    public int $event_id;
    public int $target_user_id;
}
