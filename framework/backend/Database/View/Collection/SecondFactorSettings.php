<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Object\Collection\SecondFactorSettings as ObjectSecondFactorSettings;
use Hilos\Database\View\Item\SecondFactorSetting;

/**
 * SecondFactorSettings Db collection - each person's own removal wait (HIL-494).
 *
 * Read-facing representation of the framework-owned hilos_second_factor_setting table,
 * keyed by the person: `Hilos::$db->secondFactorSettings[$userId]` is that person's row,
 * or null when they never chose a wait of their own.
 *
 * @extends DbCollection<SecondFactorSetting, ObjectSecondFactorSettings>
 */
final class SecondFactorSettings extends DbCollection
{
    public const string DB_ITEM_CLASS = SecondFactorSetting::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSecondFactorSettings::class;
}
