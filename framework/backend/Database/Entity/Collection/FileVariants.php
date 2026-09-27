<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\FileVariant as EntityFileVariant;

/**
 * @extends EntityCollection<EntityFileVariant>
 */
class FileVariants extends EntityCollection
{
    public const string ENTITY_CLASS = EntityFileVariant::class;
}
