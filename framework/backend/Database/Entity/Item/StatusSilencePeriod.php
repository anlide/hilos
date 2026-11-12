<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Collection\StatusSilencePeriods as EntityStatusSilencePeriods;
use Hilos\Database\PhpType;

/**
 * Closed publication gaps retained independently of the public history window.
 *
 * @method static EntityStatusSilencePeriods get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityStatusSilencePeriods getAll()
 */
class StatusSilencePeriod extends Entity
{
    public const string id = 'id';
    public const string public_id = 'public_id';
    public const string started_at = 'started_at';
    public const string ended_at = 'ended_at';

    public const string _table = 'hilos_status_silence_period';
    public const string _database = DatabaseNames::PRIMARY;
    public const bool _journaled = false;
    public const string _primary = self::id;
    public const array _columns = [self::id, self::public_id, self::started_at, self::ended_at];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::public_id => PhpType::STRING->value,
        self::started_at => PhpType::INTEGER->value,
        self::ended_at => PhpType::INTEGER->value,
    ];
    public const array _moments = [self::started_at, self::ended_at];
    public const array _indexes = [
        'uk_status_silence_period_public' => [
            Entity::INDEX_COLUMNS => [self::public_id],
            Entity::INDEX_UNIQUE => true,
        ],
        'idx_status_silence_period_ended' => [
            Entity::INDEX_COLUMNS => [self::ended_at, self::id],
        ],
    ];
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = false;
    public const array _pii = [];
    public const array _piiNotPersonal = self::_columns;

    public ?int $id = null;
    public string $public_id;
    public int $started_at;
    public int $ended_at;
}
