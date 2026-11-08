<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\AccountErasureAssignment as EntityAccountErasureAssignment;

/** @extends EntityCollection<EntityAccountErasureAssignment> */
class AccountErasureAssignments extends EntityCollection
{
    public const string ENTITY_CLASS = EntityAccountErasureAssignment::class;
}
