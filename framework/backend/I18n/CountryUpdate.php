<?php

declare(strict_types=1);

namespace Hilos\I18n;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\Exception\I18nRowFrozenException;
use Hilos\Pages\I18n\Details\DTO\AbstractHilosI18nCountryUpdateActionDTO;

/** Resolves the two page actions' codes into the existing country write door. */
final class CountryUpdate
{
    /**
     * @param AbstractHilosI18nCountryUpdateActionDTO $dto Country fields from the card or table row
     * @throws TableActionException When the country no longer exists
     * @throws ValidationException When the locale no longer exists or the currency or locale is invalid
     * @throws I18nRowFrozenException When the country is switched on
     * @throws ItemNotFoundForUpdateException When the country has no persisted id
     * @throws DatabaseException When a code lookup fails
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded row has the wrong type
     * @throws HilosException When ownership or persistence refuses the write
     */
    public static function apply(AbstractHilosI18nCountryUpdateActionDTO $dto): void
    {
        $country = Hilos::$db->countries[$dto->countryCode]
            ?? throw new TableActionException('Unknown country: ' . $dto->countryCode);
        $defaultLocaleId = null;
        if ($dto->defaultLocaleCode !== null) {
            $defaultLocaleId = (Hilos::$db->locales[$dto->defaultLocaleCode]
                ?? throw new ValidationException("Locale '{$dto->defaultLocaleCode}' no longer exists."))->id;
        }

        $country->actions->update($dto->currencySymbol, $dto->currencyCode, $defaultLocaleId);
    }
}
