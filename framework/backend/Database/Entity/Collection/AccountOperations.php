<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\AccountOperation as EntityAccountOperation;

/** @extends EntityCollection<EntityAccountOperation> */
class AccountOperations extends EntityCollection
{
    public const string ENTITY_CLASS = EntityAccountOperation::class;
}
