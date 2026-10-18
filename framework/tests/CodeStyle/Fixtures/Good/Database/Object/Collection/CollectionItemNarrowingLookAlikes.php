<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good\Database\Object\Collection;

use Hilos\Database\Entity\Collection\Users as EntityUsers;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Item\User as ObjectUser;
use Hilos\Database\Object\Objects;

/**
 * Negative sample of what the rule is obliged to let through: the whole set of tags, with a
 * current() beside them that is not judged. A single reported line in this file means the rule
 * has grown wider than the document it enforces.
 *
 * @extends Objects<ObjectUser>
 * @method ObjectUser|null current()
 * @method ObjectUser|null first()
 * @method ObjectUser|null last()
 * @method ObjectUser|null get(int|string $key)
 * @method ObjectUser|null offsetGet(mixed $offset)
 */
class CollectionItemNarrowingLookAlikes extends Objects
{
    public const string OBJECT_CLASS = ObjectUser::class;
    public const string ENTITY_COLLECTION_CLASS = EntityUsers::class;
}

/**
 * Negative sample: a real method with the narrow return type counts the way a tag does, and
 * the null branch beside the item is the one extra branch the rule allows.
 *
 * @extends Objects<ObjectUser>
 * @method ObjectUser|null first()
 * @method ObjectUser|null last()
 * @method ObjectUser|null offsetGet(mixed $offset)
 */
class RealGet extends Objects
{
    public const string OBJECT_CLASS = ObjectUser::class;
    public const string ENTITY_COLLECTION_CLASS = EntityUsers::class;

    /**
     * @param int|string|null $key Row key, or null for a missing optional key
     * @return ObjectUser|null Row, or null when missing
     */
    public function get(int|string|null $key): ObjectUser|null
    {
        return null;
    }
}

/**
 * Negative sample: a generic base that names no item is not judged, and neither is a subclass
 * that takes the item from its parent without redeclaring the constant.
 *
 * @template T of Object_
 * @extends Objects<T>
 */
abstract class GenericObjectBase extends Objects
{
}

/** A subclass inheriting the constant is outside the lexical read. */
class InheritsTheItem extends CollectionItemNarrowingLookAlikes
{
}
