<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Entity\Item\Locale as EntityLocale;
use Hilos\Database\Object\Collection\Locales as ObjectLocales;
use Hilos\Database\View\Collection\Locales as DbCollectionLocales;
use Hilos\Database\View\Item\Country;
use Hilos\Database\View\Item\Language;
use Hilos\Database\View\Item\Locale;
use Hilos\HilosException;
use Hilos\I18n\MeasurementSystem;

/**
 * @extends DbActions<Locale, ObjectLocales>
 * @property-read DbCollectionLocales $collection
 * @property-read ObjectLocales $objectCollection
 */
class LocalesActions extends DbActions
{
    /**
     * @param Language $language The locale's language
     * @param ?Country $country Its country, or null for a countryless language locale
     * @param string $dateFormat Date template
     * @param string $timeFormat Time template
     * @param string $numberFormat Number template
     * @param string $phoneFormat Phone template
     * @param string $addressFormat Address template
     * @param MeasurementSystem $measurementSystem Unit system
     * @param string $collation Sorting template
     * @return Locale New switched-off locale
     * @throws ValidationException When a format is empty or too wide
     * @throws HilosException When ownership, the database or collection refuses the write
     */
    public function create(
        Language $language,
        ?Country $country,
        string $dateFormat,
        string $timeFormat,
        string $numberFormat,
        string $phoneFormat,
        string $addressFormat,
        MeasurementSystem $measurementSystem,
        string $collation,
    ): Locale {
        $this->ensureCanCreateInSet((string)$language->id);

        if (trim($dateFormat) === '' || mb_strlen($dateFormat) > EntityLocale::DATE_FORMAT_MAX_CHARS
            || trim($timeFormat) === '' || mb_strlen($timeFormat) > EntityLocale::TIME_FORMAT_MAX_CHARS
            || trim($numberFormat) === '' || mb_strlen($numberFormat) > EntityLocale::NUMBER_FORMAT_MAX_CHARS
            || trim($phoneFormat) === '' || mb_strlen($phoneFormat) > EntityLocale::PHONE_FORMAT_MAX_CHARS
            || trim($addressFormat) === '' || mb_strlen($addressFormat) > EntityLocale::ADDRESS_FORMAT_MAX_CHARS
            || trim($collation) === '' || mb_strlen($collation) > EntityLocale::COLLATION_MAX_CHARS) {
            throw new ValidationException('Locale formats must be nonempty and fit their columns');
        }

        $objectClass = $this->objectCollection::OBJECT_CLASS;
        $locale = $objectClass::create();
        $locale->code = $country === null ? $language->code : $language->code . '-' . strtoupper($country->code);
        $locale->languageId = $language->id;
        $locale->countryId = $country?->id;
        $locale->dateFormat = $dateFormat;
        $locale->timeFormat = $timeFormat;
        $locale->numberFormat = $numberFormat;
        $locale->phoneFormat = $phoneFormat;
        $locale->addressFormat = $addressFormat;
        $locale->measurementSystem = $measurementSystem->value;
        $locale->collation = $collation;
        $locale->enabled = false;
        $locale->sync();
        $this->addObjectToCollection($locale);

        return $this->createDbItemFromObject($locale);
    }
}
