<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\AuthBlock as EntityAuthBlock;

/**
 * AuthBlocks entity collection.
 *
 * @extends EntityCollection<EntityAuthBlock>
 */
final class AuthBlocks extends EntityCollection
{
    public const string ENTITY_CLASS = EntityAuthBlock::class;
}
