<?php

declare(strict_types=1);

namespace Hilos\I18n\DTO;

/** Read-only facts shown on a language's main card. */
final readonly class LanguageCardSummary
{
    public const string DELETE_DEFAULT = 'default';
    public const string DELETE_LOCALES = 'locales';
    public const string DELETE_NAMES = 'names';

    public bool $canDelete;
    public ?string $deleteReason;

    /**
     * @param bool $isDefault Whether this is the installation default
     * @param bool $isOwn Whether the code is absent from the built-in catalog
     * @param int $localeCount All locales, including disabled ones
     * @param int $nameCount Nonempty base names written in other languages
     * @param bool $hasManualLanguageName Whether a locked name references this language
     * @param bool $hasLockedCountryName Whether a locked country name is written in this language
     */
    public function __construct(
        public bool $isDefault,
        public bool $isOwn,
        public int $localeCount,
        public int $nameCount,
        bool $hasManualLanguageName,
        bool $hasLockedCountryName,
    ) {
        $this->deleteReason = match (true) {
            $isDefault => self::DELETE_DEFAULT,
            $localeCount > 0 => self::DELETE_LOCALES,
            $hasManualLanguageName, $hasLockedCountryName => self::DELETE_NAMES,
            default => null,
        };
        $this->canDelete = $this->deleteReason === null;
    }

    /** @return array<string, bool|int|string|null> Browser payload fields */
    public function toArray(): array
    {
        return [
            'isDefault' => $this->isDefault,
            'isOwn' => $this->isOwn,
            'localeCount' => $this->localeCount,
            'nameCount' => $this->nameCount,
            'canDelete' => $this->canDelete,
            'deleteReason' => $this->deleteReason,
        ];
    }
}
