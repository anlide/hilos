<?php

declare(strict_types=1);

namespace Hilos\Database\Entity\Collection;

use ArrayAccess;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\PushSubscription as EntityPushSubscription;
use IteratorAggregate;

/**
 * PushSubscriptions - Entity collection for the framework hilos_push_subscription table.
 *
 * @extends EntityCollection<EntityPushSubscription>
 * @implements IteratorAggregate<int|string, EntityPushSubscription>
 * @implements ArrayAccess<int|string, EntityPushSubscription>
 */
final class PushSubscriptions extends EntityCollection
{
    public const string ENTITY_CLASS = EntityPushSubscription::class;
}
