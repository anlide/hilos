<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\LogicException;

use Hilos\Database\DatabaseException;

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
class SecondFactorSettings extends DbCollection
{
    public const string DB_ITEM_CLASS = SecondFactorSetting::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSecondFactorSettings::class;
    /**
     * Reads the merge's two sets under a nonwaiting row lock and drops stale wrappers.
     * Called only inside the merge transaction, before any ordinary reads.
     *
     * @param int $survivorId Surviving account
     * @param int $loserId Folded account
     * @throws DatabaseException When a set is busy or its rows cannot be locked
     * @throws LogicException When the collection class constants are not configured
     */
    public function lockForMerge(int $survivorId, int $loserId): void
    {
        $this->objectCollection->lockForMerge($survivorId, $loserId);
        $this->clearCache();
    }

}
