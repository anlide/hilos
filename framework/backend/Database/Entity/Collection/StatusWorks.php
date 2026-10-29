<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use ArrayAccess;
use Hilos\Database\Entity\Item\StatusWork as EntityStatusWork;
use IteratorAggregate;

/**
 * @extends EntityCollection<EntityStatusWork>
 * @implements IteratorAggregate<int|string, EntityStatusWork>
 * @implements ArrayAccess<int|string, EntityStatusWork>
 */
class StatusWorks extends EntityCollection
{
    public const string ENTITY_CLASS = EntityStatusWork::class;
}
