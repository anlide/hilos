<?php

declare(strict_types=1);

namespace Hilos\Tables\I18n;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/**
 * Backend row payload of the names tables of the i18n section (HIL-1477).
 *
 * One row is one language of the system as it stands in a window opened on a subject — a
 * language or a country: what the subject is called in that language, and how the editions of
 * that language, its locales of a country, correct the name. The same language is a different row
 * in the window of each subject, which is why the tables build their changes per window
 * ({@see AbstractHilosI18nNamesTable}).
 *
 * The row key is the code of the row's language and rides the payload under {@see self::rowKey},
 * never `id`: a slot carrying `id` is taken by the frontend normalizer for an entity fragment.
 */
final class HilosI18nNameTableRow extends AbstractTableRow
{
    /** Payload key of the row identity — the code of the row's language. */
    public const string rowKey = 'rowKey';

    public const string code = 'code';
    public const string nativeName = 'nativeName';

    /** Payload key of the base name, null when none is written and '' when an empty one is. */
    public const string name = 'name';

    /** Payload key of the list of locale corrections, empty when there is no base name. */
    public const string corrections = 'corrections';

    /** Correction key of the locale's code. */
    public const string localeCode = 'localeCode';

    /** Correction key of the code of the locale's country. */
    public const string countryCode = 'countryCode';

    /** Correction key of the country's name in the default language, null when it has none. */
    public const string countryName = 'countryName';

    /**
     * @param string $code Code of the row's language, which is also the row key
     * @param string $nativeName Name the row's language calls itself
     * @param ?string $name Base name in the row's language, null when none is written
     * @param list<array{localeCode: string, countryCode: string, countryName: ?string, name: ?string}> $corrections
     *     Locale corrections by locale code, the correction's name null when the locale inherits the base
     */
    public function __construct(
        public string $code,
        public string $nativeName,
        public ?string $name,
        public array $corrections,
    ) {
    }

    /**
     * @return string Code of the row's language
     */
    public function getRowKey(): string
    {
        return $this->code;
    }

    /**
     * @return string Payload key the row key travels under
     */
    public static function keyField(): string
    {
        return self::rowKey;
    }

    /**
     * Serializes the row to the names table payload shape.
     *
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->code,
            self::code => $this->code,
            self::nativeName => $this->nativeName,
            self::name => $this->name,
            self::corrections => $this->corrections,
        ];
    }

    /**
     * Builds a names row from raw table payload.
     *
     * @param array<string, mixed> $data Raw row payload
     * @return static Reconstructed names table row
     * @throws InvalidFormatException When the payload is missing a field the row is built from
     */
    public static function fromArray(array $data): static
    {
        $corrections = [];
        foreach (self::requireArray($data, self::corrections) as $correction) {
            if (!is_array($correction)) {
                throw new InvalidFormatException('A locale correction of a names row must be an object');
            }
            $corrections[] = [
                self::localeCode => self::requireString($correction, self::localeCode),
                self::countryCode => self::requireString($correction, self::countryCode),
                self::countryName => self::optionalString($correction, self::countryName),
                self::name => self::optionalString($correction, self::name),
            ];
        }

        return new static(
            code: self::requireString($data, self::code),
            nativeName: self::requireString($data, self::nativeName),
            name: self::optionalString($data, self::name),
            corrections: $corrections,
        );
    }
}
