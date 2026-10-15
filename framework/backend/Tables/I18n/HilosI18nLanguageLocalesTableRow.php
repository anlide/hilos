<?php

declare(strict_types=1);

namespace Hilos\Tables\I18n;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\I18n\DTO\LocaleFormats;

/** One parent-ID pair, with a nullable locale ID when no locale exists. */
final class HilosI18nLanguageLocalesTableRow extends AbstractTableRow
{
    /** Payload key of the row identity — the pair of parent IDs. */
    public const string rowKey = 'rowKey';
    public const string languageId = 'languageId';
    public const string countryId = 'countryId';
    public const string localeId = 'localeId';

    /** Payload key of the country's code as stored, null on the row of the language alone. */
    public const string countryCode = 'countryCode';

    /** Payload key of the country's base name in the window's language, null when none is written. */
    public const string countryName = 'countryName';

    /** Payload key of the code of the pair's locale, null when the pair has no locale. */
    public const string localeCode = 'localeCode';

    /** Payload key of whether the pair's locale is on, null when the pair has no locale. */
    public const string enabled = 'enabled';

    /** Payload key of whether the country selects the pair's locale as its default. */
    public const string isCountryDefault = 'isCountryDefault';

    /** Payload key of the pair's stored formats, null when the pair has no locale. */
    public const string formats = 'formats';

    /** Payload key of built-in formats, null when the catalog does not know the pair. */
    public const string catalogFormats = 'catalogFormats';

    /**
     * @param string $rowKey Parent-ID pair
     * @param int $languageId Language primary ID
     * @param ?int $countryId Country primary ID, or null
     * @param ?int $localeId Existing locale primary ID, or null
     * @param ?string $countryCode Country's code as stored, null on the row of the language alone
     * @param ?string $countryName Country's base name in the window's language, null when none is written
     * @param ?string $localeCode Code of the pair's locale, null when the pair has no locale
     * @param ?bool $enabled Whether the pair's locale is on, null when the pair has no locale
     * @param bool $isCountryDefault Whether the country selects the pair's locale as its default
     * @param ?LocaleFormats $formats Stored formats, null when the pair has no locale
     * @param ?LocaleFormats $catalogFormats Built-in formats, null when the catalog does not know the pair
     */
    public function __construct(
        public string $rowKey,
        public int $languageId,
        public ?int $countryId,
        public ?int $localeId,
        public ?string $countryCode,
        public ?string $countryName,
        public ?string $localeCode,
        public ?bool $enabled,
        public bool $isCountryDefault,
        public ?LocaleFormats $formats,
        public ?LocaleFormats $catalogFormats,
    ) {
    }

    /**
     * @return string The pair of parent IDs
     */
    public function getRowKey(): string
    {
        return $this->rowKey;
    }

    /**
     * @return string Payload key the row key travels under
     */
    public static function keyField(): string
    {
        return self::rowKey;
    }

    /**
     * Serializes the row to the locales table payload shape.
     *
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::languageId => $this->languageId,
            self::countryId => $this->countryId,
            self::localeId => $this->localeId,
            self::countryCode => $this->countryCode,
            self::countryName => $this->countryName,
            self::localeCode => $this->localeCode,
            self::enabled => $this->enabled,
            self::isCountryDefault => $this->isCountryDefault,
            self::formats => $this->formats?->toArray(),
            self::catalogFormats => $this->catalogFormats?->toArray(),
        ];
    }

    /**
     * Builds a locales row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     * @return static Reconstructed locales table row
     * @throws InvalidFormatException When the payload is missing the row key or carries a field of the wrong type
     */
    public static function fromArray(array $data): static
    {
        foreach ([self::countryId, self::localeId] as $field) {
            if (!array_key_exists($field, $data)
                || ($data[$field] !== null && (!is_int($data[$field]) || $data[$field] <= 0))) {
                throw new InvalidFormatException('Invalid nullable ID: ' . $field);
            }
        }
        if (self::requireInt($data, self::languageId) <= 0) {
            throw new InvalidFormatException('Invalid language ID');
        }
        $formats = self::optionalArray($data, self::formats);
        $catalogFormats = self::optionalArray($data, self::catalogFormats);

        return new static(
            rowKey: self::requireString($data, self::rowKey),
            languageId: self::requireInt($data, self::languageId),
            countryId: $data[self::countryId],
            localeId: $data[self::localeId],
            countryCode: self::optionalString($data, self::countryCode),
            countryName: self::optionalString($data, self::countryName),
            localeCode: self::optionalString($data, self::localeCode),
            enabled: self::optionalBool($data, self::enabled),
            isCountryDefault: self::requireBool($data, self::isCountryDefault),
            formats: $formats === null ? null : LocaleFormats::fromArray($formats),
            catalogFormats: $catalogFormats === null ? null : LocaleFormats::fromArray($catalogFormats),
        );
    }
}
