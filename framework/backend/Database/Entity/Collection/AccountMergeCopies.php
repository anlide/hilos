<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\AccountMergeCopy as EntityAccountMergeCopy;

/** @extends EntityCollection<EntityAccountMergeCopy> */
class AccountMergeCopies extends EntityCollection
{
    public const string ENTITY_CLASS = EntityAccountMergeCopy::class;
}
