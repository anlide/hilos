<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\AccountErasureResult as EntityAccountErasureResult;

/** @extends EntityCollection<EntityAccountErasureResult> */
class AccountErasureResults extends EntityCollection
{
    public const string ENTITY_CLASS = EntityAccountErasureResult::class;
}
