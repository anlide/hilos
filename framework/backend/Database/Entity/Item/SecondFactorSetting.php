<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Item;

use Hilos\Database\Entity\Collection\SecondFactorSettings as EntitySecondFactorSettings;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;

/**
 * SecondFactorSetting Entity - represents the hilos_second_factor_setting table row.
 *
 * A person's own choice of how long a removal of their second factor waits (HIL-494),
 * within the administrator's bounds. NULL `reset_wait_days` is the administrator's
 * default. A shorter wait is parked in `pending_reset_wait_days` until
 * `pending_reset_wait_from`, the moment the wait in force when it was asked for runs out.
 * Keyed by the person.
 *
 * @method static EntitySecondFactorSettings get(array|string $filters = [], array|string $filtersParam = [], array|string $orderBy = [])
 * @method static EntitySecondFactorSettings getAll()
 */
final class SecondFactorSetting extends Entity
{
    public const string user_id = 'user_id';
    public const string reset_wait_days = 'reset_wait_days';
    public const string pending_reset_wait_days = 'pending_reset_wait_days';
    public const string pending_reset_wait_from = 'pending_reset_wait_from';
    public const string updated_at = 'updated_at';

    public const string _table = 'hilos_second_factor_setting';
    public const string _primary = self::user_id;
    public const array _columns = [
        self::user_id,
        self::reset_wait_days,
        self::pending_reset_wait_days,
        self::pending_reset_wait_from,
        self::updated_at,
    ];

    public const array _types = [
        self::user_id => PhpType::INTEGER->value,
        self::reset_wait_days => PhpType::INTEGER->value,
        self::pending_reset_wait_days => PhpType::INTEGER->value,
        self::pending_reset_wait_from => PhpType::DATETIME->value,
        self::updated_at => PhpType::DATETIME->value,
    ];

    public const array _indexes = [];

    public const string _setVia = self::user_id;
    public const bool _setRoot = false;

    // A number of days and when it takes over: how long a person's removal waits says
    // nothing about who they are.
    public const array _pii = [];

    public const array _piiNotPersonal = [
        self::user_id,
        self::reset_wait_days,
        self::pending_reset_wait_days,
        self::pending_reset_wait_from,
        self::updated_at,
    ];

    public int $user_id;
    public ?int $reset_wait_days = null;
    public ?int $pending_reset_wait_days = null;
    public ?string $pending_reset_wait_from = null;
    public string $updated_at;
}
