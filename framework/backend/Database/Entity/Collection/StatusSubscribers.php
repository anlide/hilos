<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use ArrayAccess;
use Hilos\Database\Entity\Item\StatusSubscriber as EntityStatusSubscriber;
use IteratorAggregate;

/**
 * @extends EntityCollection<EntityStatusSubscriber>
 * @implements IteratorAggregate<int|string, EntityStatusSubscriber>
 * @implements ArrayAccess<int|string, EntityStatusSubscriber>
 */
class StatusSubscribers extends EntityCollection
{
    public const string ENTITY_CLASS = EntityStatusSubscriber::class;
}
