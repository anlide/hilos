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
 * The row key is the decimal primary ID of the row's language and rides the payload under {@see self::rowKey},
 * never `id`: a slot carrying `id` is taken by the frontend normalizer for an entity fragment.
 */
final class HilosI18nNameTableRow extends AbstractTableRow
{
    /** Payload key of the writing language's decimal ID. */
    public const string rowKey = 'rowKey';

    public const string inLanguageId = 'inLanguageId';
    public const string nameId = 'nameId';
    public const string localeId = 'localeId';
    public const string countryId = 'countryId';
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
     * @param int $inLanguageId Primary ID of the writing language
     * @param string $code Code of the writing language
     * @param string $nativeName Name the row's language calls itself
     * @param ?int $nameId Primary ID of the base name, null when absent
     * @param ?string $name Base name in the row's language, null when none is written
     * @param list<array{localeId: int, countryId: int, nameId: ?int, localeCode: string,
     *     countryCode: string, countryName: ?string, name: ?string}> $corrections
     *     Locale corrections by locale code, the correction's name null when the locale inherits the base
     */
    public function __construct(
        public int $inLanguageId,
        public string $code,
        public string $nativeName,
        public ?int $nameId,
        public ?string $name,
        public array $corrections,
    ) {
    }

    /**
     * @return string Decimal primary ID of the writing language
     */
    public function getRowKey(): string
    {
        return (string) $this->inLanguageId;
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
            self::rowKey => $this->getRowKey(),
            self::inLanguageId => $this->inLanguageId,
            self::code => $this->code,
            self::nativeName => $this->nativeName,
            self::nameId => $this->nameId,
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
        if (self::requireInt($data, self::inLanguageId) <= 0
            || self::requireString($data, self::rowKey) !== (string) self::requireInt($data, self::inLanguageId)) {
            throw new InvalidFormatException('Invalid writing-language row identity');
        }
        if (!array_key_exists(self::nameId, $data)
            || ($data[self::nameId] !== null && (!is_int($data[self::nameId]) || $data[self::nameId] <= 0))) {
            throw new InvalidFormatException('Invalid base name ID');
        }
        $corrections = [];
        foreach (self::requireArray($data, self::corrections) as $correction) {
            if (!is_array($correction)) {
                throw new InvalidFormatException('A locale correction of a names row must be an object');
            }
            if (!array_key_exists(self::nameId, $correction)
                || ($correction[self::nameId] !== null
                    && (!is_int($correction[self::nameId]) || $correction[self::nameId] <= 0))
                || self::requireInt($correction, self::localeId) <= 0
                || self::requireInt($correction, self::countryId) <= 0) {
                throw new InvalidFormatException('Invalid correction identity');
            }
            $corrections[] = [
                self::localeId => self::requireInt($correction, self::localeId),
                self::countryId => self::requireInt($correction, self::countryId),
                self::nameId => $correction[self::nameId],
                self::localeCode => self::requireString($correction, self::localeCode),
                self::countryCode => self::requireString($correction, self::countryCode),
                self::countryName => self::optionalString($correction, self::countryName),
                self::name => self::optionalString($correction, self::name),
            ];
        }

        return new static(
            inLanguageId: self::requireInt($data, self::inLanguageId),
            code: self::requireString($data, self::code),
            nativeName: self::requireString($data, self::nativeName),
            nameId: $data[self::nameId],
            name: self::optionalString($data, self::name),
            corrections: $corrections,
        );
    }
}
