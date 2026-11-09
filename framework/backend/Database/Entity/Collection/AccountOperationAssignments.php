<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\AccountOperationAssignment as EntityAccountOperationAssignment;

/** @extends EntityCollection<EntityAccountOperationAssignment> */
class AccountOperationAssignments extends EntityCollection
{
    public const string ENTITY_CLASS = EntityAccountOperationAssignment::class;
}
