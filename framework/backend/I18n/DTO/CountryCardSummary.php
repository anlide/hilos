<?php

declare(strict_types=1);

namespace Hilos\I18n\DTO;

/** Read-only facts shown on a country's main card. */
final readonly class CountryCardSummary
{
    public const string DELETE_KNOWN = 'known';
    public const string DELETE_LOCALES = 'locales';
    public const string DELETE_NAMES = 'names';

    public bool $canDelete;
    public ?string $deleteReason;

    /**
     * @param ?string $name Nonempty base name in the default language, or null when there is none
     * @param bool $isOwn Whether the code is absent from the built-in catalog
     * @param ?string $defaultLocaleCode Code of the chosen default locale, or null when none is chosen
     * @param bool $hasLocales Whether any locale, enabled or not, refers to the country
     * @param bool $hasNames Whether any name row, base or override, even empty, names the country
     */
    public function __construct(
        public ?string $name,
        public bool $isOwn,
        public ?string $defaultLocaleCode,
        bool $hasLocales,
        bool $hasNames,
    ) {
        $this->deleteReason = match (true) {
            !$isOwn => self::DELETE_KNOWN,
            $hasLocales => self::DELETE_LOCALES,
            $hasNames => self::DELETE_NAMES,
            default => null,
        };
        $this->canDelete = $this->deleteReason === null;
    }

    /** @return array<string, bool|string|null> Browser payload fields */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'isOwn' => $this->isOwn,
            'defaultLocaleCode' => $this->defaultLocaleCode,
            'canDelete' => $this->canDelete,
            'deleteReason' => $this->deleteReason,
        ];
    }
}
