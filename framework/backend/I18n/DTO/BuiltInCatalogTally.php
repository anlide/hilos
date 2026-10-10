<?php

declare(strict_types=1);

namespace Hilos\I18n\DTO;

use Hilos\I18n\Catalog\BuiltInI18nCatalog;

/**
 * Built-in catalog languages and countries count delivered in the page scope.
 */
final readonly class BuiltInCatalogTally
{
    public const string DATA = 'builtInCatalog';
    public const string LANGUAGE_COUNT = 'languageCount';
    public const string COUNTRY_COUNT = 'countryCount';

    /**
     * @param int $languageCount Built-in language count
     * @param int $countryCount Built-in country count
     */
    public function __construct(
        public int $languageCount,
        public int $countryCount,
    ) {
    }

    /**
     * Reads built-in counts directly from the catalog.
     *
     * @return self Built-in catalog tally instance
     */
    public static function fromCatalog(): self
    {
        return new self(
            iterator_count(BuiltInI18nCatalog::languages()),
            iterator_count(BuiltInI18nCatalog::countries()),
        );
    }

    /**
     * @return array{languageCount: int, countryCount: int} Browser payload fields
     */
    public function toArray(): array
    {
        return [
            self::LANGUAGE_COUNT => $this->languageCount,
            self::COUNTRY_COUNT => $this->countryCount,
        ];
    }
}
