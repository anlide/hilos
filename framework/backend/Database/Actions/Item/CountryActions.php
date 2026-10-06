<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\ItemNotFoundForDeleteException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Object\Item\Country as ObjectCountry;
use Hilos\Database\View\Item\Country;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\Exception\I18nRowFrozenException;

/**
 * @extends DbActions<Country, ObjectCountry>
 * @property-read ObjectCountry $object
 */
class CountryActions extends DbActions
{
    /**
     * @param string $currencySymbol Symbol displayed with an amount
     * @param string $currencyCode Three uppercase ISO 4217 letters
     * @param ?int $defaultLocaleId Locale of this country, or null to clear it
     * @throws ItemNotFoundForUpdateException When the row has no persisted id
     * @throws I18nRowFrozenException When the country is switched on
     * @throws ValidationException When the currency or locale is invalid
     * @throws HilosException When ownership or persistence refuses the write
     */
    public function update(string $currencySymbol, string $currencyCode, ?int $defaultLocaleId): void
    {
        $this->ensureCanWrite();
        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Country not found for update (id is null)');
        }
        if ($this->object->enabled) {
            throw new I18nRowFrozenException(
                "Country '{$this->object->code}' is switched on: switch it off, edit it, switch it on",
            );
        }
        if (trim($currencySymbol) === '' || mb_strlen($currencySymbol) > 8) {
            throw new ValidationException('Country currency symbol must have 1..8 characters');
        }
        if (preg_match('/^[A-Z]{3}$/', $currencyCode) !== 1) {
            throw new ValidationException('Country currency code must have three uppercase letters');
        }
        if ($defaultLocaleId !== null) {
            $locale = Hilos::$db->locales[$defaultLocaleId];
            if ($locale === null || $locale->countryId !== $this->object->id) {
                throw new ValidationException('Country default locale must belong to this country');
            }
        }

        $this->object->currencySymbol = $currencySymbol;
        $this->object->currencyCode = $currencyCode;
        $this->object->defaultLocaleId = $defaultLocaleId;
        $this->object->sync();
    }

    /** @throws HilosException When ownership or persistence refuses the write */
    public function switchOn(): void
    {
        $this->ensureCanWrite();
        if ($this->object->enabled) {
            return;
        }
        $this->object->enabled = true;
        $this->object->sync();
    }

    /** @throws HilosException When ownership or persistence refuses the write */
    public function switchOff(): void
    {
        $this->ensureCanWrite();
        if (!$this->object->enabled) {
            return;
        }
        $this->object->enabled = false;
        $this->object->sync();
    }

    /**
     * @throws ItemNotFoundForDeleteException When the row has no persisted id
     * @throws ObjectCollectionNullException When the item is detached from its collection
     * @throws HilosException When ownership, the database or collection refuses removal
     */
    public function delete(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        if ($this->object->id === null) {
            throw new ItemNotFoundForDeleteException('Country not found for delete (id is null)');
        }

        $objectCollection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Object collection is null');
        $idString = $this->object->getIdString();
        $this->object->delete();
        unset($objectCollection[$idString]);
        $this->item->getCollection()?->forgetCachedItem($idString);
    }
}
