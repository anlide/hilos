<?php

declare(strict_types=1);

namespace Hilos\I18n\Catalog;

/** A built-in country name in one language, without a project override. */
final readonly class CountryNameDefinition
{
    /**
     * @param string $countryCode Lowercase country code
     * @param string $languageCode Lowercase language code
     * @param string $name Base name in that language
     */
    public function __construct(
        public string $countryCode,
        public string $languageCode,
        public string $name,
    ) {
    }
}
