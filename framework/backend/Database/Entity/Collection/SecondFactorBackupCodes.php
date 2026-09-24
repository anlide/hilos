<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\SecondFactorBackupCode as EntitySecondFactorBackupCode;

/**
 * SecondFactorBackupCodes entity collection.
 *
 * @extends EntityCollection<EntitySecondFactorBackupCode>
 */
final class SecondFactorBackupCodes extends EntityCollection
{
    public const string ENTITY_CLASS = EntitySecondFactorBackupCode::class;
}
