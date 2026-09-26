<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use Hilos\Database\Entity\Item\File as EntityFile;

/**
 * Files entity collection.
 *
 * @extends EntityCollection<EntityFile>
 */
final class Files extends EntityCollection
{
    public const string ENTITY_CLASS = EntityFile::class;
}
