<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad\Database\Object\Collection;

use Hilos\Database\Entity\Collection\Users as EntityUsers;
use Hilos\Database\Object\Item\User as ObjectUser;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Item\User;

/**
 * Deliberately broken sample: an Object collection that narrows offsetGet() alone, so first(),
 * last() and get() are reported one after another on the line of the class keyword.
 *
 * @extends Objects<ObjectUser>
 * @method ObjectUser|null offsetGet(mixed $offset)
 */
class CollectionItemNarrowingSamples extends Objects
{
    public const string OBJECT_CLASS = ObjectUser::class;
    public const string ENTITY_COLLECTION_CLASS = EntityUsers::class;
}

/**
 * Deliberately broken sample: the whole set is declared, but get() is a real method that
 * answers the View item of another layer. The three tags beside it are right and stay silent,
 * and a real method is read the way a tag is: by the type it writes down.
 *
 * @extends Objects<ObjectUser>
 * @method ObjectUser|null first()
 * @method ObjectUser|null last()
 * @method ObjectUser|null offsetGet(mixed $offset)
 */
class ForeignItemGet extends Objects
{
    public const string OBJECT_CLASS = ObjectUser::class;
    public const string ENTITY_COLLECTION_CLASS = EntityUsers::class;

    /**
     * @param int|string|null $key Row key, or null for a missing optional key
     * @return User|null The item of another layer
     */
    public function get(int|string|null $key): ?User
    {
        return null;
    }
}
