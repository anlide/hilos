<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\CountriesActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\Countries as ObjectCountries;
use Hilos\Database\View\Item\Country;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\I18n\DTO\CountryCardSummary;

/**
 * Countries support code strings and primary-id integers as offsets.
 *
 * @extends DbCollection<Country, ObjectCountries>
 * @property-read CountriesActions $actions
 */
class Countries extends DbCollection
{
    public const string DB_ITEM_CLASS = Country::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectCountries::class;

    /**
     * @param int $countryId Country primary id
     * @return CountryCardSummary Current read-only card facts
     * @throws DatabaseException When a country or dependent row query fails
     * @throws InvalidArgumentException When the country no longer exists
     * @throws LogicException When collection classes are not configured
     * @throws EnvException When the default language setting is absent
     * @throws EnvInvalidValueException When its configured code is unknown
     */
    public function cardSummary(int $countryId): CountryCardSummary
    {
        return $this->objectCollection->cardSummary($countryId);
    }

    /**
     * @param mixed $offset Country code or primary id
     * @return bool Whether the country exists
     * @throws DatabaseException When the lookup fails
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->offsetGet($offset) !== null;
    }

    /**
     * @param mixed $offset Country code or primary id
     * @return ?Country Country or null
     * @throws DatabaseException When the lookup fails
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     */
    public function offsetGet(mixed $offset): ?Country
    {
        if (is_string($offset)) {
            $object = $this->objectCollection->findByCode($offset);
            return $this->getItemForKey($object?->id);
        }
        if (!is_int($offset)) {
            return null;
        }

        /** @var ?Country $country */
        $country = parent::offsetGet($offset);
        return $country;
    }
}
