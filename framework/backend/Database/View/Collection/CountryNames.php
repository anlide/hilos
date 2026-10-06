<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\CountryNamesActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\CountryNames as ObjectCountryNames;
use Hilos\Database\Object\Item\CountryName as ObjectCountryName;
use Hilos\Database\View\Item\CountryName;

/**
 * Translated country names use primary ids as offsets.
 *
 * @extends DbCollection<CountryName, ObjectCountryNames>
 * @property-read CountryNamesActions $actions
 */
class CountryNames extends DbCollection
{
    public const string DB_ITEM_CLASS = CountryName::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectCountryNames::class;

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
     * @return list<CountryName> Overrides of the base
     * @throws DatabaseException When the query fails
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     */
    public function overridesFor(int $countryId, int $languageId): array
    {
        $names = [];
        foreach ($this->objectCollection->overridesFor($countryId, $languageId) as $object) {
            $name = $this->wrap($object);
            if ($name !== null) {
                $names[] = $name;
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
