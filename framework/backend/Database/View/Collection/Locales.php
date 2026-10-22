<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\LocalesActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Collection\Locales as ObjectLocales;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Item\Locale;

/**
 * Locales support code strings and primary-id integers as offsets.
 *
 * @extends DbCollection<Locale, ObjectLocales>
 * @property-read LocalesActions $actions
 * @method ObjectLocales|null getObjectCollection()
 * @method Locale|null first()
 * @method Locale|null last()
 */
class Locales extends DbCollection
{
    public const string DB_ITEM_CLASS = Locale::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectLocales::class;

    /**
     * @param int $languageId Language primary id
     * @return int Number of its locales, regardless of enabled state
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the entity column is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function countForLanguage(int $languageId): int
    {
        return $this->objectCollection->countForLanguage($languageId);
    }

    /**
     * @param int $languageId Language primary id
     * @return ?bool Whether its countryless locale is enabled; null when absent
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the entity column is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function countrylessEnabled(int $languageId): ?bool
    {
        return $this->objectCollection->countrylessEnabled($languageId);
    }

    /**
     * @param ?int $countryId Parent country's primary id; null selects an empty collection
     * @return static All locales of that country, regardless of enabled state, without an ordering promise
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     * @throws DatabaseException When the global locale collection cannot be loaded
     * @throws ObjectGetIdStringNotImplementedException When a locale cannot name its id
     * @throws CollectionNotManualException When the filtered collection refuses a locale
     */
    public function forCountryId(?int $countryId): static
    {
        return $countryId === null
            ? static::initEmpty()
            : $this->filter(static fn (Locale $locale): bool => $locale->countryId === $countryId);
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
        if (is_string($offset)) {
            $object = $this->objectCollection->findByCode($offset);
            return $this->getItemForKey($object?->id) !== null;
        }
        if (!is_int($offset)) {
            return false;
        }

        return $this->getItemForKey($offset) !== null;
    }

    /**
     * @param mixed $offset Locale code or primary id of a row the caller requires
     * @return Locale Locale at the code or id
     * @throws ItemNotFoundException When no locale holds the code or id, `null` or another unsupported offset included
     * @throws DatabaseException When the lookup fails
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     */
    public function offsetGet(mixed $offset): Locale
    {
        if (is_string($offset)) {
            $object = $this->objectCollection->findByCode($offset);
            return $this->getItemForKey($object?->id) ?? throw new ItemNotFoundException(static::class, $offset);
        }
        if (!is_int($offset)) {
            throw new ItemNotFoundException(static::class, $offset);
        }

        /** @var Locale $locale */
        $locale = parent::offsetGet($offset);
        return $locale;
    }
}
