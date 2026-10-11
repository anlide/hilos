<?php

declare(strict_types=1);

namespace Hilos\I18n\DTO;

/** Read-only facts shown on a country's main card. */
final readonly class CountryCardSummary
{
    public const string DELETE_KNOWN = 'known';
    public const string DELETE_LOCALES = 'locales';
    public const string DELETE_NAMES = 'names';

    public const string DEFAULT_LOCALE_MISSING = 'missing';
    public const string DEFAULT_LOCALE_OFF = 'off';
    public const string DEFAULT_LOCALE_ON = 'on';

    public bool $canDelete;
    public ?string $deleteReason;
    public string $defaultLocale;

    /**
     * @param ?string $name Nonempty base name in the default language, or null when there is none
     * @param bool $isOwn Whether the code is absent from the built-in catalog
     * @param ?string $defaultLocaleCode Code of the chosen default locale, or null when none is chosen
     * @param ?bool $defaultLocaleEnabled Whether the chosen default locale is switched on; null when none is chosen
     * @param int $localeCount All locales of the country, enabled or not
     * @param int $nameCount Nonempty base names of the country in every language
     * @param bool $hasNames Whether any name row, base or override, even empty, names the country
     */
    public function __construct(
        public ?string $name,
        public bool $isOwn,
        public ?string $defaultLocaleCode,
        ?bool $defaultLocaleEnabled,
        public int $localeCount,
        public int $nameCount,
        bool $hasNames,
    ) {
        $this->defaultLocale = match ($defaultLocaleEnabled) {
            null => self::DEFAULT_LOCALE_MISSING,
            false => self::DEFAULT_LOCALE_OFF,
            true => self::DEFAULT_LOCALE_ON,
        };
        $this->deleteReason = match (true) {
            !$isOwn => self::DELETE_KNOWN,
            $localeCount > 0 => self::DELETE_LOCALES,
            $hasNames => self::DELETE_NAMES,
            default => null,
        };
        $this->canDelete = $this->deleteReason === null;
    }

    /** @return array<string, bool|int|string|null> Browser payload fields */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'isOwn' => $this->isOwn,
            'defaultLocaleCode' => $this->defaultLocaleCode,
            'defaultLocale' => $this->defaultLocale,
            'localeCount' => $this->localeCount,
            'nameCount' => $this->nameCount,
            'canDelete' => $this->canDelete,
            'deleteReason' => $this->deleteReason,
        ];
    }
}
