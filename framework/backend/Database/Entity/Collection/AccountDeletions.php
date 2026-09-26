<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\AccountDeletion as EntityAccountDeletion;

/**
 * AccountDeletions entity collection.
 *
 * @extends EntityCollection<EntityAccountDeletion>
 */
final class AccountDeletions extends EntityCollection
{
    public const string ENTITY_CLASS = EntityAccountDeletion::class;
}
