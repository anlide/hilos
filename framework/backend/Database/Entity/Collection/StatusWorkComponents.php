<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use ArrayAccess;
use Hilos\Database\Entity\Item\StatusWorkComponent as EntityStatusWorkComponent;
use IteratorAggregate;

/**
 * @extends EntityCollection<EntityStatusWorkComponent>
 * @implements IteratorAggregate<int|string, EntityStatusWorkComponent>
 * @implements ArrayAccess<int|string, EntityStatusWorkComponent>
 */
class StatusWorkComponents extends EntityCollection
{
    public const string ENTITY_CLASS = EntityStatusWorkComponent::class;
}
