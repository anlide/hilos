<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\LocalesActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\Locales as ObjectLocales;
use Hilos\Database\View\Item\Locale;

/**
 * Locales support code strings and primary-id integers as offsets.
 *
 * @extends DbCollection<Locale, ObjectLocales>
 * @property-read LocalesActions $actions
 */
class Locales extends DbCollection
{
    public const string DB_ITEM_CLASS = Locale::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectLocales::class;

    /**
     * @param int $languageId Language primary id
     * @return int Number of its locales, regardless of enabled state
     * @throws DatabaseException When the query fails
     */
    public function countForLanguage(int $languageId): int
    {
        return $this->objectCollection->countForLanguage($languageId);
    }

    /**
     * @param int $countryId Country primary id
     * @return bool Whether any locale refers to it, regardless of enabled state
     * @throws DatabaseException When the query fails
     */
    public function hasForCountry(int $countryId): bool
    {
        return $this->objectCollection->hasForCountry($countryId);
    }

    /**
     * @param mixed $offset Locale code or primary id
     * @return bool Whether the locale exists
     * @throws DatabaseException When the lookup fails
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->offsetGet($offset) !== null;
    }

    /**
     * @param mixed $offset Locale code or primary id
     * @return ?Locale Locale or null
     * @throws DatabaseException When the lookup fails
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     */
    public function offsetGet(mixed $offset): ?Locale
    {
        if (is_string($offset)) {
            $object = $this->objectCollection->findByCode($offset);
            return $this->getItemForKey($object?->id);
        }
        if (!is_int($offset)) {
            return null;
        }

        /** @var ?Locale $locale */
        $locale = parent::offsetGet($offset);
        return $locale;
    }
}
