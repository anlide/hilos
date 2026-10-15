<?php

declare(strict_types=1);

namespace Hilos\I18n\DTO;

use Hilos\Database\View\Item\Country;

/** Read-only country card payload. */
final readonly class CountryCardSummary
{
    public const string DELETE_KNOWN = Country::DELETE_KNOWN;
    public const string DELETE_LOCALES = Country::DELETE_LOCALES;
    public const string DELETE_NAMES = Country::DELETE_NAMES;

    public const string DEFAULT_LOCALE_MISSING = 'missing';
    public const string DEFAULT_LOCALE_OFF = 'off';
    public const string DEFAULT_LOCALE_ON = 'on';

    public bool $canDelete;
    public string $defaultLocale;

    /**
     * @param ?string $name Nonempty base name in the default language, or null when there is none
     * @param bool $isOwn Whether the code is absent from the built-in catalog
     * @param ?int $defaultLocaleId Selected locale ID, or null
     * @param ?string $defaultLocaleCode Code of the chosen default locale, or null when none is chosen
     * @param ?bool $defaultLocaleEnabled Whether the chosen default locale is switched on; null when none is chosen
     * @param int $localeCount All locales of the country, enabled or not
     * @param int $nameCount Nonempty base names of the country in every language
     * @param list<CountryLocaleChoice> $locales Serialization boundary: country locales, on or off, by code
     * @param ?string $catalogDefaultLocaleCode Built-in catalog suggestion, whether created or not
     * @param ?string $deleteReason Deletion blocker computed by the country, or null
     */
    public function __construct(
        public ?string $name,
        public bool $isOwn,
        public ?int $defaultLocaleId,
        public ?string $defaultLocaleCode,
        ?bool $defaultLocaleEnabled,
        public int $localeCount,
        public int $nameCount,
        public array $locales,
        public ?string $catalogDefaultLocaleCode,
        public ?string $deleteReason,
    ) {
        $this->defaultLocale = match ($defaultLocaleEnabled) {
            null => self::DEFAULT_LOCALE_MISSING,
            false => self::DEFAULT_LOCALE_OFF,
            true => self::DEFAULT_LOCALE_ON,
        };
        $this->canDelete = $this->deleteReason === null;
    }

    /** @return array<string, mixed> Browser payload fields including locale choices */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'isOwn' => $this->isOwn,
            'defaultLocaleId' => $this->defaultLocaleId,
            'defaultLocaleCode' => $this->defaultLocaleCode,
            'defaultLocale' => $this->defaultLocale,
            'localeCount' => $this->localeCount,
            'nameCount' => $this->nameCount,
            'locales' => array_map(static fn (CountryLocaleChoice $locale): array => $locale->toArray(), $this->locales),
            'catalogDefaultLocaleCode' => $this->catalogDefaultLocaleCode,
            'canDelete' => $this->canDelete,
            'deleteReason' => $this->deleteReason,
        ];
    }
}
