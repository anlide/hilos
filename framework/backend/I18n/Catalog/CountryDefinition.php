<?php

declare(strict_types=1);

namespace Hilos\I18n\Catalog;

/** A country and its currency shipped in the built-in catalog. */
final readonly class CountryDefinition
{
    /**
     * @param string $code Lowercase country code
     * @param string $currencySymbol Display symbol from the hleb catalog
     * @param string $currencyCode Current ISO 4217 currency code from CLDR 48.2
     */
    public function __construct(
        public string $code,
        public string $currencySymbol,
        public string $currencyCode,
    ) {
    }
}
