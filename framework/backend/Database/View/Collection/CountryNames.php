<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\CountryNamesActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Collection\CountryNames as ObjectCountryNames;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\CountryName as ObjectCountryName;
use Hilos\Database\View\Item\CountryName;
use Hilos\HilosException;

/**
 * Translated country names use primary ids as offsets.
 *
 * @extends DbCollection<CountryName, ObjectCountryNames>
 * @property-read CountryNamesActions $actions
 * @method ObjectCountryNames|null getObjectCollection()
 * @method CountryName|null first()
 * @method CountryName|null last()
 * @method CountryName|null offsetGet(mixed $offset)
 */
class CountryNames extends DbCollection
{
    public const string DB_ITEM_CLASS = CountryName::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectCountryNames::class;

    /**
     * @param ?int $localeId Override locale primary id; null selects no rows
     * @return static Current overrides for the locale, excluding base names
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     * @throws DatabaseException When loading the names fails
     * @throws ObjectGetIdStringNotImplementedException When a name has no object key contract
     * @throws CollectionNotManualException When the filtered collection refuses an item
     */
    public function forLocaleId(?int $localeId): static
    {
        return $localeId === null
            ? static::initEmpty()
            : $this->filter(static fn (CountryName $name): bool => $name->localeId === $localeId);
    }

    /**
     * @param int $languageId Writing language primary id
     * @return bool Whether a locked country name is written in it
     * @throws DatabaseException When the query fails
     */
    public function hasLockedNameInLanguage(int $languageId): bool
    {
        return $this->objectCollection->hasLockedNameInLanguage($languageId);
    }

    /**
     * @param int $languageId Writing language primary id
     * @return static All unlocked base names written in the language, indexed by primary id
     * @throws DatabaseException When the query fails
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When the query is invalid or a loaded object has the wrong type
     * @throws HilosException When the empty collection refuses direct initialization
     * @throws ObjectGetIdStringNotImplementedException When a name has no object key contract
     * @throws CollectionNotManualException When the manual collection refuses an item
     */
    public function unlockedBaseNamesInLanguage(int $languageId): static
    {
        $names = static::initEmpty();
        foreach ($this->objectCollection->unlockedBaseNamesInLanguage($languageId) as $object) {
            $name = $this->wrap($object);
            if ($name !== null) {
                $names->add($name);
            }
        }
        return $names;
    }

    /**
     * @param int $countryId Named country primary id
     * @return bool Whether any name row, base or override, even empty, names it
     * @throws DatabaseException When the query fails
     */
    public function hasForCountry(int $countryId): bool
    {
        return $this->objectCollection->hasForCountry($countryId);
    }

    /**
     * @param int $countryId Named country primary id
     * @return int Number of nonempty base names in every language
     * @throws DatabaseException When the query fails
     */
    public function countBaseNamesForCountry(int $countryId): int
    {
        return $this->objectCollection->countBaseNamesForCountry($countryId);
    }

    /**
     * @param int $countryId Named country id
     * @param int $languageId Writing language id
     * @return ?CountryName Base row, or null
     * @throws DatabaseException When the query fails
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     */
    public function findBase(int $countryId, int $languageId): ?CountryName
    {
        return $this->wrap($this->objectCollection->findBase($countryId, $languageId));
    }

    /**
     * @param int $countryId Named country id
     * @param int $languageId Writing language id
     * @param int $localeId Override locale id
     * @return ?CountryName Override row, or null
     * @throws DatabaseException When the query fails
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     */
    public function findOverride(int $countryId, int $languageId, int $localeId): ?CountryName
    {
        return $this->wrap($this->objectCollection->findOverride($countryId, $languageId, $localeId));
    }

    /**
     * @param int $countryId Named country id
     * @param int $languageId Writing language id
     * @return static Overrides of the base, indexed by primary id
     * @throws DatabaseException When the query fails
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     * @throws HilosException When the empty collection refuses direct initialization
     * @throws ObjectGetIdStringNotImplementedException When a name has no object key contract
     * @throws CollectionNotManualException When the manual collection refuses an item
     */
    public function overridesFor(int $countryId, int $languageId): static
    {
        $names = static::initEmpty();
        foreach ($this->objectCollection->overridesFor($countryId, $languageId) as $object) {
            $name = $this->wrap($object);
            if ($name !== null) {
                $names->add($name);
            }
        }
        return $names;
    }

    /**
     * @param ?ObjectCountryName $object Scalar row, if present
     * @return ?CountryName View row, if present
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     */
    private function wrap(?ObjectCountryName $object): ?CountryName
    {
        if ($object?->id === null) {
            return null;
        }
        /** @var ?CountryName $item */
        $item = $this->getItemForKey($object->id);
        return $item;
    }
}
