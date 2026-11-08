<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\AccountErasureOperation as EntityAccountErasureOperation;

/** @extends EntityCollection<EntityAccountErasureOperation> */
class AccountErasureOperations extends EntityCollection
{
    public const string ENTITY_CLASS = EntityAccountErasureOperation::class;
}
