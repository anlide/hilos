<?php

declare(strict_types=1);

namespace Hilos\I18n\Catalog;

/** A locale and its built-in display formats. */
final readonly class LocaleDefinition
{
    /**
     * @param string $code Canonical language or language-country code
     * @param string $languageCode Lowercase language code
     * @param ?string $countryCode Lowercase country code, or null for a countryless locale
     * @param string $dateFormat Date display template
     * @param string $timeFormat Time display template
     * @param string $numberFormat Number display template
     * @param string $phoneFormat Phone display template
     * @param string $addressFormat Address display template
     * @param string $measurementSystem 'metric' or 'imperial'
     * @param string $collation Built-in collation code 'und'
     */
    public function __construct(
        public string $code,
        public string $languageCode,
        public ?string $countryCode,
        public string $dateFormat,
        public string $timeFormat,
        public string $numberFormat,
        public string $phoneFormat,
        public string $addressFormat,
        public string $measurementSystem,
        public string $collation,
    ) {
    }
}
