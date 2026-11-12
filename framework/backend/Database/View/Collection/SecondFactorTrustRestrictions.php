<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Object\Collection\SecondFactorTrustRestrictions as ObjectSecondFactorTrustRestrictions;
use Hilos\Database\View\Item\SecondFactorTrustRestriction;

/**
 * SecondFactorTrustRestrictions Db collection - the shortenings of browser trust (HIL-1900).
 *
 * Read-facing representation of the framework-owned hilos_second_factor_trust_restriction table.
 * Array offsets are primary ids; use isset or ?? null for optional rows.
 *
 * @extends DbCollection<SecondFactorTrustRestriction, ObjectSecondFactorTrustRestrictions>
 * @method ObjectSecondFactorTrustRestrictions|null getObjectCollection()
 * @method SecondFactorTrustRestriction|null first()
 * @method SecondFactorTrustRestriction|null last()
 * @method SecondFactorTrustRestriction offsetGet(mixed $offset)
 */
class SecondFactorTrustRestrictions extends DbCollection
{
    public const string DB_ITEM_CLASS = SecondFactorTrustRestriction::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSecondFactorTrustRestrictions::class;
}
