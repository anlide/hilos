<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\LanguagesActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\Languages as ObjectLanguages;
use Hilos\Database\View\Item\Language;

/**
 * Languages support code strings and primary-id integers as offsets.
 *
 * @extends DbCollection<Language, ObjectLanguages>
 * @property-read LanguagesActions $actions
 */
class Languages extends DbCollection
{
    public const string DB_ITEM_CLASS = Language::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectLanguages::class;

    /**
     * @param mixed $offset Language code or primary id
     * @return bool Whether the language exists
     * @throws DatabaseException When the lookup fails
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->offsetGet($offset) !== null;
    }

    /**
     * @param mixed $offset Language code or primary id
     * @return ?Language Language or null
     * @throws DatabaseException When the lookup fails
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     */
    public function offsetGet(mixed $offset): ?Language
    {
        if (is_string($offset)) {
            $object = $this->objectCollection->findByCode($offset);
            return $this->getItemForKey($object?->id);
        }
        if (!is_int($offset)) {
            return null;
        }

        /** @var ?Language $language */
        $language = parent::offsetGet($offset);
        return $language;
    }
}
