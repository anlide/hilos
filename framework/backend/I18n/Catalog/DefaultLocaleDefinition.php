<?php

declare(strict_types=1);

namespace Hilos\I18n\Catalog;

/** A source-country hint for a default locale, whether or not either row exists. */
final readonly class DefaultLocaleDefinition
{
    /**
     * @param string $countryCode Lowercase source country code
     * @param string $localeCode Canonical suggested locale code
     */
    public function __construct(
        public string $countryCode,
        public string $localeCode,
    ) {
    }
}
