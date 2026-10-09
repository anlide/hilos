<?php

declare(strict_types=1);

namespace Hilos\Tables\I18n;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\Object\Item\Locale as ObjectLocale;

/**
 * Backend row payload of the locales table of one language (HIL-1476).
 *
 * One row is a pair of the language the window is opened on and one country of the system, or the
 * language alone — not a stored locale. The pair is a row whether a locale of it exists or not, and
 * what the locale says, when there is one, rides along: its code and whether it is on.
 *
 * The row key is the pair written the way the code of its locale is written
 * ({@see ObjectLocale::codeFor()}): `ru` for the language alone, `ru-UA` for a country. It rides
 * the payload under {@see self::rowKey}, never `id`: a slot carrying `id` is taken by the frontend
 * normalizer for an entity fragment.
 */
final class HilosI18nLanguageLocalesTableRow extends AbstractTableRow
{
    /** Payload key of the row identity — the pair, written as the code of its locale. */
    public const string rowKey = 'rowKey';

    /** Payload key of the country's code as stored, null on the row of the language alone. */
    public const string countryCode = 'countryCode';

    /** Payload key of the country's base name in the window's language, null when none is written. */
    public const string countryName = 'countryName';

    /** Payload key of the code of the pair's locale, null when the pair has no locale. */
    public const string localeCode = 'localeCode';

    /** Payload key of whether the pair's locale is on, null when the pair has no locale. */
    public const string enabled = 'enabled';

    /**
     * @param string $rowKey The pair, written as the code of its locale
     * @param ?string $countryCode Country's code as stored, null on the row of the language alone
     * @param ?string $countryName Country's base name in the window's language, null when none is written
     * @param ?string $localeCode Code of the pair's locale, null when the pair has no locale
     * @param ?bool $enabled Whether the pair's locale is on, null when the pair has no locale
     */
    public function __construct(
        public string $rowKey,
        public ?string $countryCode,
        public ?string $countryName,
        public ?string $localeCode,
        public ?bool $enabled,
    ) {
    }

    /**
     * @return string The pair, written as the code of its locale
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
            self::countryCode => $this->countryCode,
            self::countryName => $this->countryName,
            self::localeCode => $this->localeCode,
            self::enabled => $this->enabled,
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
        return new static(
            rowKey: self::requireString($data, self::rowKey),
            countryCode: self::optionalString($data, self::countryCode),
            countryName: self::optionalString($data, self::countryName),
            localeCode: self::optionalString($data, self::localeCode),
            enabled: self::optionalBool($data, self::enabled),
        );
    }
}
