<?php

declare(strict_types=1);

namespace Hilos\I18n\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;

/** The language and the region a tab shows, with the display formats of that region. */
final class TabLanguage extends BaseDTO
{
    public const string languageId = 'languageId';
    public const string languageCode = 'languageCode';
    public const string rtl = 'rtl';
    public const string localeId = 'localeId';
    public const string localeCode = 'localeCode';
    public const string formats = 'formats';

    /**
     * @param int $languageId Primary id of the tab's language
     * @param string $languageCode Code of that language
     * @param bool $rtl Whether that language is written right to left
     * @param ?int $localeId Primary id of the tab's locale; null when no stored locale fits and the formats come from the catalog
     * @param ?string $localeCode Code of that locale; null together with its id
     * @param LocaleFormats $formats Display formats of the region
     */
    public function __construct(
        public readonly int $languageId,
        public readonly string $languageCode,
        public readonly bool $rtl,
        public readonly ?int $localeId,
        public readonly ?string $localeCode,
        public readonly LocaleFormats $formats,
    ) {
    }

    /**
     * @param array<string, mixed> $data Wire values
     * @return static Parsed language and region
     * @throws InvalidFormatException When a field is absent or has the wrong type, or the formats name an unknown unit system
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireInt($data, self::languageId),
            self::requireString($data, self::languageCode),
            self::requireBool($data, self::rtl),
            self::optionalInt($data, self::localeId),
            self::optionalString($data, self::localeCode),
            LocaleFormats::fromArray(self::requireArray($data, self::formats)),
        );
    }

    /** @return array<string, mixed> Wire values, the formats nested as {@see LocaleFormats::toArray()} writes them */
    public function toArray(): array
    {
        return [
            self::languageId => $this->languageId,
            self::languageCode => $this->languageCode,
            self::rtl => $this->rtl,
            self::localeId => $this->localeId,
            self::localeCode => $this->localeCode,
            self::formats => $this->formats->toArray(),
        ];
    }
}
