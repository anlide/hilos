<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Object\Collection\PushSubscriptions as ObjectPushSubscriptions;
use Hilos\Database\View\Item\PushSubscription;

/**
 * PushSubscriptions Db collection.
 *
 * Read-facing representation of the framework-owned hilos_push_subscription table
 * (HIL-199). The push delivery channel reads a recipient's endpoints through the
 * object collection's {@see ObjectPushSubscriptions::forUser()}; the collection
 * action applies a device subscribe/unsubscribe.
 *
 * @extends DbCollection<PushSubscription, ObjectPushSubscriptions>
 */
final class PushSubscriptions extends DbCollection
{
    public const string DB_ITEM_CLASS = PushSubscription::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectPushSubscriptions::class;
}
