<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Object\Collection\Countries as ObjectCountries;
use Hilos\Database\View\Collection\Countries as DbCollectionCountries;
use Hilos\Database\View\Item\Country;
use Hilos\HilosException;

/**
 * @extends DbActions<Country, ObjectCountries>
 * @property-read DbCollectionCountries $collection
 * @property-read ObjectCountries $objectCollection
 */
class CountriesActions extends DbActions
{
    /**
     * @param string $code Two lowercase ISO 3166-1 letters
     * @param string $currencySymbol Symbol displayed with an amount
     * @param string $currencyCode Three uppercase ISO 4217 letters
     * @return Country New switched-off country
     * @throws ValidationException When the code or currency is malformed
     * @throws HilosException When ownership, the database or collection refuses the write
     */
    public function create(string $code, string $currencySymbol, string $currencyCode): Country
    {
        $this->ensureCanCreate();

        if (preg_match('/^[a-z]{2}$/', $code) !== 1) {
            throw new ValidationException('Country code must have two lowercase letters');
        }
        if (trim($currencySymbol) === '' || mb_strlen($currencySymbol) > 8) {
            throw new ValidationException('Country currency symbol must have 1..8 characters');
        }
        if (preg_match('/^[A-Z]{3}$/', $currencyCode) !== 1) {
            throw new ValidationException('Country currency code must have three uppercase letters');
        }

        $objectClass = $this->objectCollection::OBJECT_CLASS;
        $country = $objectClass::create();
        $country->code = $code;
        $country->currencySymbol = $currencySymbol;
        $country->currencyCode = $currencyCode;
        $country->defaultLocaleId = null;
        $country->enabled = false;
        $country->sync();
        $this->addObjectToCollection($country);

        return $this->createDbItemFromObject($country);
    }
}
