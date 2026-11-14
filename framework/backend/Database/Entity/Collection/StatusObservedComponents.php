<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use ArrayAccess;
use Hilos\Database\Entity\Item\StatusObservedComponent as EntityStatusObservedComponent;
use IteratorAggregate;

/**
 * @extends EntityCollection<EntityStatusObservedComponent>
 * @implements IteratorAggregate<int|string, EntityStatusObservedComponent>
 * @implements ArrayAccess<int|string, EntityStatusObservedComponent>
 */
class StatusObservedComponents extends EntityCollection
{
    public const string ENTITY_CLASS = EntityStatusObservedComponent::class;
}
