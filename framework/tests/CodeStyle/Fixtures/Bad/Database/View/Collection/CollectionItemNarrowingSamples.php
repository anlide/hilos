<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad\Database\View\Collection;

use Hilos\Database\Object\Collection\Sessions as ObjectSessions;
use Hilos\Database\Object\Collection\Users as ObjectUsers;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\DbItem;
use Hilos\Database\View\Item\User;

/**
 * Deliberately broken sample: a View collection whose readers do not narrow to the classes
 * its constants name. getObjectCollection() names another collection, first() is not declared
 * at all and offsetGet() stays at the base DbItem; last() is right and must stay silent. Every
 * hit sits on the line of the class keyword, in the order the readers are listed.
 *
 * @extends DbCollection<User, ObjectUsers>
 * @method ObjectSessions|null getObjectCollection()
 * @method User|null last()
 * @method DbItem|null offsetGet(mixed $offset)
 */
class CollectionItemNarrowingSamples extends DbCollection
{
    public const string DB_ITEM_CLASS = User::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUsers::class;
}
