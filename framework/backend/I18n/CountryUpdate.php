<?php

declare(strict_types=1);

namespace Hilos\I18n;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Item\Country;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\Exception\I18nRowFrozenException;
use Hilos\Pages\I18n\Details\DTO\AbstractHilosI18nCountryUpdateActionDTO;

/** Passes the two page actions' IDs to the existing country write door. */
final class CountryUpdate
{
    /**
     * @param AbstractHilosI18nCountryUpdateActionDTO $dto Country fields from the card or table row
     * @return Country Updated country
     * @throws TableActionException When the country no longer exists
     * @throws ValidationException When the locale no longer exists or the currency or locale is invalid
     * @throws I18nRowFrozenException When the country is switched on
     * @throws ItemNotFoundForUpdateException When the country has no persisted id
     * @throws DatabaseException When a country lookup fails
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded row has the wrong type
     * @throws HilosException When ownership or persistence refuses the write
     */
    public static function apply(AbstractHilosI18nCountryUpdateActionDTO $dto): Country
    {
        $country = Hilos::$db->countries[$dto->countryId]
            ?? throw new TableActionException('Unknown country: ' . $dto->countryId);
        $country->actions->update($dto->currencySymbol, $dto->currencyCode, $dto->defaultLocaleId);

        return $country;
    }
}
