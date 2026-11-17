<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\AccountMergeFence as EntityAccountMergeFence;

/** @extends EntityCollection<EntityAccountMergeFence> */
class AccountMergeFences extends EntityCollection
{
    public const string ENTITY_CLASS = EntityAccountMergeFence::class;
}
