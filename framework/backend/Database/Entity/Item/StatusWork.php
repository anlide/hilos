<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Collection\StatusWorks as EntityStatusWorks;
use Hilos\Database\PhpType;

/**
 * Durable Status storage owned by the existing cluster Status agent.
 *
 * @method static EntityStatusWorks get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntityStatusWorks getAll()
 */
class StatusWork extends Entity
{
    public const string id = 'id';
    public const string public_id = 'public_id';
    public const string title = 'title';
    public const string description = 'description';
    public const string starts_at = 'starts_at';
    public const string ends_at = 'ends_at';
    public const string created_at = 'created_at';
    public const string updated_at = 'updated_at';
    public const string canceled_at = 'canceled_at';
    public const string consumed_at = 'consumed_at';
    public const string expired_at = 'expired_at';
    public const string version = 'version';
    public const string last_mail_queued_at = 'last_mail_queued_at';

    public const string _table = 'hilos_status_work';
    public const string _database = DatabaseNames::PRIMARY;
    public const bool _journaled = false;
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::public_id,
        self::title,
        self::description,
        self::starts_at,
        self::ends_at,
        self::created_at,
        self::updated_at,
        self::canceled_at,
        self::consumed_at,
        self::expired_at,
        self::version,
        self::last_mail_queued_at,
    ];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::public_id => PhpType::STRING->value,
        self::title => PhpType::STRING->value,
        self::description => PhpType::TEXT->value,
        self::starts_at => PhpType::INTEGER->value,
        self::ends_at => PhpType::INTEGER->value,
        self::created_at => PhpType::INTEGER->value,
        self::updated_at => PhpType::INTEGER->value,
        self::canceled_at => PhpType::INTEGER->value,
        self::consumed_at => PhpType::INTEGER->value,
        self::expired_at => PhpType::INTEGER->value,
        self::version => PhpType::INTEGER->value,
        self::last_mail_queued_at => PhpType::INTEGER->value,
    ];
    public const array _foreign = [];
    public const array _indexes = [
        'uk_status_work_public' => [
            Entity::INDEX_COLUMNS => [
                self::public_id,
            ],
            Entity::INDEX_UNIQUE => true,
        ],
        'idx_status_work_pending_starts_at' => [
            Entity::INDEX_COLUMNS => [
                self::canceled_at,
                self::consumed_at,
                self::expired_at,
                self::starts_at,
                self::id,
            ],
        ],
        'idx_status_work_pending_ends_at' => [
            Entity::INDEX_COLUMNS => [
                self::canceled_at,
                self::consumed_at,
                self::expired_at,
                self::ends_at,
                self::id,
            ],
        ],
        'idx_status_work_pending_title' => [
            Entity::INDEX_COLUMNS => [
                self::canceled_at,
                self::consumed_at,
                self::expired_at,
                self::title,
                self::id,
            ],
        ],
        'idx_status_work_window' => [
            Entity::INDEX_COLUMNS => [
                self::canceled_at,
                self::starts_at,
                self::ends_at,
                self::id,
            ],
        ],
    ];
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = true;
    public const array _pii = [];
    public const array _piiNotPersonal = self::_columns;

    public ?int $id = null;
    public string $public_id;
    public string $title;
    public ?string $description = null;
    public int $starts_at;
    public int $ends_at;
    public int $created_at;
    public int $updated_at;
    public ?int $canceled_at = null;
    public ?int $consumed_at = null;
    public ?int $expired_at = null;
    public int $version;
    public ?int $last_mail_queued_at = null;
}
