<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\AccountOperationResult as EntityAccountOperationResult;

/** @extends EntityCollection<EntityAccountOperationResult> */
class AccountOperationResults extends EntityCollection
{
    public const string ENTITY_CLASS = EntityAccountOperationResult::class;
}
