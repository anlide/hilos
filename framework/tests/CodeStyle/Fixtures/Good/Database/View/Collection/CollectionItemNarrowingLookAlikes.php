<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good\Database\View\Collection;

use Hilos\Database\Object\Collection\Languages as ObjectLanguages;
use Hilos\Database\Object\Collection\SecondFactorSettings as ObjectSecondFactorSettings;
use Hilos\Database\Object\Collection\Users as ObjectUsers;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\Language;
use Hilos\Database\View\Item\SecondFactorSetting;
use Hilos\Database\View\Item\User;

/**
 * Negative sample of what the rule is obliged to let through: the whole set of tags, with a
 * current() beside them that is not judged. A single reported line in this file means the rule
 * has grown wider than the document it enforces.
 *
 * @extends DbCollection<User, ObjectUsers>
 * @method ObjectUsers|null getObjectCollection()
 * @method User|null current()
 * @method User|null first()
 * @method User|null last()
 * @method User|null offsetGet(mixed $offset)
 */
class CollectionItemNarrowingLookAlikes extends DbCollection
{
    public const string DB_ITEM_CLASS = User::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectUsers::class;
}

/**
 * Negative sample: the constants are read as written, so an import alias names the object
 * collection in the tag exactly as it does in the constant, and a nullable prefix is the same
 * declaration as a null branch.
 *
 * @extends DbCollection<SecondFactorSetting, ObjectSecondFactorSettings>
 * @method ObjectSecondFactorSettings|null getObjectCollection()
 * @method ?SecondFactorSetting first()
 * @method SecondFactorSetting|null last()
 * @method SecondFactorSetting|null offsetGet(mixed $offset)
 */
class AliasedObjectCollection extends DbCollection
{
    public const string DB_ITEM_CLASS = SecondFactorSetting::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSecondFactorSettings::class;
}

/**
 * Negative sample: a real method with the narrow return type counts the way a tag does, and a
 * collection declared inside a body is anonymous, so the constant it carries is not judged.
 *
 * @extends DbCollection<Language, ObjectLanguages>
 * @method ObjectLanguages|null getObjectCollection()
 * @method Language|null first()
 * @method Language|null last()
 */
class RealOffsetGet extends DbCollection
{
    public const string DB_ITEM_CLASS = Language::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectLanguages::class;

    /**
     * @param mixed $offset Language code or primary id
     * @return ?Language Language or null
     */
    public function offsetGet(mixed $offset): ?Language
    {
        return null;
    }

    /**
     * @return object Anonymous collection, outside the read
     */
    public function anonymous(): object
    {
        return new class extends DbCollection {
            public const string DB_ITEM_CLASS = User::class;
        };
    }
}

/**
 * Negative sample: a base whose constant is empty names no item and is not judged, and neither
 * is a subclass that takes the item from its parent without redeclaring the constant.
 *
 * @template T of User
 * @extends DbCollection<T, ObjectUsers>
 */
abstract class UnnamedItemBase extends DbCollection
{
    public const string DB_ITEM_CLASS = '';
}

/** A subclass inheriting the constant is outside the lexical read. */
class InheritsTheItem extends CollectionItemNarrowingLookAlikes
{
}
