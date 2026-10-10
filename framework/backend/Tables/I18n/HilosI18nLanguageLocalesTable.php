<?php

declare(strict_types=1);

namespace Hilos\Tables\I18n;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\WindowScopedViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\Country as ObjectCountry;
use Hilos\Database\Object\Item\CountryName as ObjectCountryName;
use Hilos\Database\Object\Item\Language as ObjectLanguage;
use Hilos\Database\Object\Item\Locale as ObjectLocale;
use Hilos\Database\View\Item\Country as DbCountry;
use Hilos\Database\View\Item\Language as DbLanguage;
use Hilos\Database\View\Item\Locale as DbLocale;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\DTO\LocaleFormats;

/**
 * Framework table of the locales of one language: a row for the language alone and one for every country (HIL-1476).
 *
 * A row is a pair of the language the window is opened on and a country of the system, enabled or
 * not, or the language alone — not a stored locale. Every pair is a row whether a locale of it
 * exists or not, and carries the country's code, its base name written in the window's language,
 * and, when there is a locale, its code and whether it is on. The row of the language alone comes
 * first and the countries follow by code, because the key of a row is the pair written as the code
 * of its locale ({@see ObjectLocale::codeFor()}) and the window is ordered by it.
 *
 * The language is the window's filter, preset by the page from its address under
 * {@see self::FILTER_LANGUAGE}. A window without a language, or with one that no longer exists, is
 * an empty set. The rows depend on that language, so a change is built for one window at a time
 * ({@see WindowScopedViewportTable}): a locale of the language updates the row of its pair, a new or
 * removed country adds or removes its row, the country's base name in the language relabels its
 * row, and the removal or the return of the language itself empties or refills the window.
 *
 * Every row stands in one window of {@see self::WINDOW_ROWS}; there is no search, no sort to choose
 * and no filter beyond the language. A project activates the table by registering it under
 * {@see self::TABLE} and binding it to the language locales page in {@see Hilos::PAGE_TABLES}.
 */
final class HilosI18nLanguageLocalesTable extends TableDefinition implements WindowScopedViewportTable
{
    /** Canonical table key under which a project registers this table in its TableContext. */
    public const string TABLE = 'hilosI18nLanguageLocales';

    /** Filter-map key: the code of the language the window is opened on. */
    public const string FILTER_LANGUAGE = 'language';

    /** Wire slot the row payload rides under; must match the frontend locales slot. */
    public const string ROW_SLOT = 'locale';

    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            self::COUNTRIES_SOURCE,
            self::LOCALES_SOURCE,
            self::COUNTRY_NAMES_SOURCE,
            self::LANGUAGES_SOURCE,
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => self::COUNTRIES_SOURCE,
                BrowserTableFieldKey::ROW_KEY => ObjectCountry::code,
                BrowserTableFieldKey::FIELDS => [
                    ObjectCountry::code => HilosI18nLanguageLocalesTableRow::countryCode,
                ],
                BrowserTableFieldKey::COMPUTED => [
                    HilosI18nLanguageLocalesTableRow::rowKey,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => self::LOCALES_SOURCE,
                BrowserTableFieldKey::ROW_KEY => ObjectLocale::countryId,
                BrowserTableFieldKey::FIELDS => [
                    ObjectLocale::code => HilosI18nLanguageLocalesTableRow::localeCode,
                    ObjectLocale::enabled => HilosI18nLanguageLocalesTableRow::enabled,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => self::COUNTRY_NAMES_SOURCE,
                BrowserTableFieldKey::ROW_KEY => ObjectCountryName::countryId,
                BrowserTableFieldKey::FIELDS => [
                    ObjectCountryName::name => HilosI18nLanguageLocalesTableRow::countryName,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => self::LANGUAGES_SOURCE,
                BrowserTableFieldKey::ROW_KEY => ObjectLanguage::code,
                BrowserTableFieldKey::FIELDS => [ObjectLanguage::code],
            ],
        ],
    ];

    /** Source of the countries, the anchor of every row but the language's own. */
    private const array COUNTRIES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::countries,
    ];

    /** Source of the locales, whose code and switch a row of a pair carries. */
    private const array LOCALES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::locales,
    ];

    /** Source of the country names, the label of a country's row. */
    private const array COUNTRY_NAMES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::countryNames,
    ];

    /** Source of the languages, whose removal and return empty and refill the window. */
    private const array LANGUAGES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::languages,
    ];

    /**
     * Rows the first window carries: the built-in catalog knows fifty-one countries, so every row of
     * a real installation stands on one page, and a pager appears only past a hundred.
     */
    private const int WINDOW_ROWS = 100;

    /** What stands between the language and the country in a row key, as in the code of a locale. */
    private const string PAIR_SEPARATOR = '-';

    /**
     * @return int Rows the first window carries
     */
    public function windowSize(): int
    {
        return self::WINDOW_ROWS;
    }

    /**
     * @return TableSortOrderDTO The window ordered by the pair: the language alone first, then the countries by code
     */
    public function defaultSort(): TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(HilosI18nLanguageLocalesTableRow::rowKey));
    }

    /**
     * Builds the row mutations one change makes in the window of one language.
     *
     * @param SourceChange $change Source change that may affect this table
     * @param TableQueryDTO $window Query the window was served by; its filter names the language
     * @return list<TableRowMutationDTO> Row mutations for this window, empty when the change does not touch it
     * @throws HilosException When an i18n collection cannot be read
     */
    public function buildMutationsForWindow(SourceChange $change, TableQueryDTO $window): array
    {
        $languageCode = self::languageCodeOf($window);
        if ($languageCode === null) {
            return [];
        }

        $language = Hilos::$db->languages[$languageCode];
        if ($language === null) {
            // The language left: nothing of the window stands on anything any more. Asked only for the
            // removal of the window's own language, so a window opened on a code that never existed is
            // not sent a removal of every row on every write.
            $removed = $change->sourceKey === HilosDbContext::languages
                && $change->mutationType === TableMutationType::Delete
                && ($change->row[ObjectLanguage::code] ?? null) === $languageCode;

            return $removed ? $this->removeEveryRow($languageCode) : [];
        }

        return match ($change->sourceKey) {
            HilosDbContext::locales => $this->localeMutations($change, $language),
            HilosDbContext::countries => $this->countryMutations($change, $language),
            HilosDbContext::countryNames => $this->countryNameMutations($change, $language),
            HilosDbContext::languages => $this->languageMutations($change, $language),
            default => [],
        };
    }

    /**
     * Serializes one locales row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Locales row from this table's window or mutation
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [
                self::ROW_SLOT => $row->toArray(),
            ],
        ];
    }

    /**
     * Answers whether the window of a language holds the row of one pair.
     *
     * @param string|int $rowKey The pair, written as the code of its locale
     * @param TableQueryDTO $query Window query whose filter names the language
     * @return bool Whether the pair is of the window's language and stands in the system
     * @throws HilosException When an i18n collection cannot be read
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): bool
    {
        [$languageCode] = self::pairOf((string) $rowKey);
        if ($languageCode !== self::languageCodeOf($query)) {
            return false;
        }

        return $this->findRow($rowKey) !== null;
    }

    /**
     * Reads the row of one pair straight from its key, without walking the window.
     *
     * The table's whole set is no set at all — every window is opened on a language — so the
     * default walk over an unfiltered window would find nothing. The key names the language and
     * the country itself.
     *
     * @param string|int $rowKey The pair, written as the code of its locale
     * @return ?HilosI18nLanguageLocalesTableRow The row, or null when the language or the country does not exist
     * @throws HilosException When an i18n collection cannot be read
     */
    public function findRow(string|int $rowKey): ?HilosI18nLanguageLocalesTableRow
    {
        [$languageCode, $countryCode] = self::pairOf((string) $rowKey);
        $language = Hilos::$db->languages[$languageCode];
        if ($language === null) {
            return null;
        }
        if ($countryCode === null) {
            return $this->ownRow($language);
        }

        $country = Hilos::$db->countries[$countryCode];

        return $country === null ? null : $this->countryRow($language, $country);
    }

    /**
     * Declares where each field of a locales row comes from, for a viewer of the admin view mode (HIL-1250).
     *
     * Every field is a column of an i18n collection, none of them personal (HIL-1468), and the key is
     * put together of two such codes, so a viewer sees the whole row.
     *
     * @return array<string, WireField> Row field name to where it comes from
     */
    public function wireFields(): array
    {
        return [
            HilosI18nLanguageLocalesTableRow::rowKey => WireField::notPersonal(),
            HilosI18nLanguageLocalesTableRow::countryCode => WireField::column(HilosDbContext::countries, ObjectCountry::code),
            HilosI18nLanguageLocalesTableRow::countryName => WireField::column(
                HilosDbContext::countryNames,
                ObjectCountryName::name,
            ),
            HilosI18nLanguageLocalesTableRow::localeCode => WireField::column(HilosDbContext::locales, ObjectLocale::code),
            HilosI18nLanguageLocalesTableRow::enabled => WireField::column(HilosDbContext::locales, ObjectLocale::enabled),
            HilosI18nLanguageLocalesTableRow::formats => WireField::each([
                LocaleFormats::date => WireField::column(HilosDbContext::locales, ObjectLocale::dateFormat),
                LocaleFormats::time => WireField::column(HilosDbContext::locales, ObjectLocale::timeFormat),
                LocaleFormats::number => WireField::column(HilosDbContext::locales, ObjectLocale::numberFormat),
                LocaleFormats::phone => WireField::column(HilosDbContext::locales, ObjectLocale::phoneFormat),
                LocaleFormats::address => WireField::column(HilosDbContext::locales, ObjectLocale::addressFormat),
                LocaleFormats::measurement => WireField::column(HilosDbContext::locales, ObjectLocale::measurementSystem),
                LocaleFormats::collation => WireField::column(HilosDbContext::locales, ObjectLocale::collation),
            ]),
            HilosI18nLanguageLocalesTableRow::catalogFormats => WireField::each([
                LocaleFormats::date => WireField::notPersonal(),
                LocaleFormats::time => WireField::notPersonal(),
                LocaleFormats::number => WireField::notPersonal(),
                LocaleFormats::phone => WireField::notPersonal(),
                LocaleFormats::address => WireField::notPersonal(),
                LocaleFormats::measurement => WireField::notPersonal(),
                LocaleFormats::collation => WireField::notPersonal(),
            ]),
        ];
    }

    /**
     * Configures the row shape of the locales table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosI18nLanguageLocalesTableRow::class);
    }

    /**
     * @return array<string, string> The pair key, the only order the window runs in
     */
    protected function sortableFields(): array
    {
        return [HilosI18nLanguageLocalesTableRow::rowKey => HilosI18nLanguageLocalesTableRow::rowKey];
    }

    /**
     * Serves the window of one language: its own row and a row for every country of the system.
     *
     * Three reads whatever the number of countries: the language's locales, its base names of the
     * countries and the countries themselves.
     *
     * @param TableQueryDTO $query Window query whose filter names the language
     * @return TableSnapshotDTO Locales window, empty when the window names no existing language
     * @throws HilosException When an i18n collection cannot be read
     * @throws TableSearchNotSupportedException When a term arrives, this table declaring no searchable fields
     * @throws TableSearchFieldUnknownException When a declared search field is carried by no row of the set
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $languageCode = self::languageCodeOf($query);
        $language = $languageCode === null ? null : Hilos::$db->languages[$languageCode];
        $rows = [];
        if ($language !== null) {
            $languageId = (int) $language->id;
            $ownLocale = null;
            $localesByCountry = [];
            foreach (Hilos::$db->locales->whereColumnIs(ObjectLocale::languageId, $languageId) as $locale) {
                if ($locale->countryId === null) {
                    $ownLocale = $locale;
                } else {
                    $localesByCountry[$locale->countryId] = $locale;
                }
            }
            $namesByCountry = [];
            foreach (Hilos::$db->countryNames->whereColumnIs(ObjectCountryName::languageId, $languageId) as $name) {
                if ($name->localeId === null) {
                    $namesByCountry[$name->countryId] = $name->name;
                }
            }

            $rows[] = self::row($language->code, null, null, $ownLocale)->toArray();
            foreach ($this->countriesByCode() as $country) {
                $countryId = (int) $country->id;
                $rows[] = self::row(
                    $language->code,
                    $country->code,
                    $namesByCountry[$countryId] ?? null,
                    $localesByCountry[$countryId] ?? null,
                )->toArray();
            }
        }

        return $this->filterInMemory($rows, $query);
    }

    /**
     * Builds the mutation a change of one locale makes in the window of a language.
     *
     * A locale created, edited, switched or removed updates the row of its pair, which carries what
     * the locale says now, or nothing once it is gone; a locale of another language is not here.
     *
     * @param SourceChange $change Change of the locales collection
     * @param DbLanguage $language The window's language
     * @return list<TableRowMutationDTO> The update of the pair's row, or nothing
     * @throws HilosException When an i18n collection cannot be read
     */
    private function localeMutations(SourceChange $change, DbLanguage $language): array
    {
        $locale = Hilos::$db->locales[(int) $change->sourceId];
        $languageId = $locale !== null ? $locale->languageId : self::rowId($change->row, ObjectLocale::languageId);
        if ($languageId !== (int) $language->id) {
            return [];
        }

        $countryId = $locale !== null ? $locale->countryId : self::rowId($change->row, ObjectLocale::countryId);
        if ($countryId === null) {
            return [$this->mutation(TableMutationType::Update, (string) $language->code, $this->ownRow($language))];
        }

        $country = Hilos::$db->countries[$countryId];
        if ($country === null) {
            return [];
        }

        $row = $this->countryRow($language, $country);

        return [$this->mutation(TableMutationType::Update, $row->rowKey, $row)];
    }

    /**
     * Builds the mutation a change of one country makes in the window of a language.
     *
     * A country is a row of every language, so a new one adds its row and a removed one takes it
     * away; an edit of a country changes nothing a row carries, the code never being edited.
     *
     * @param SourceChange $change Change of the countries collection
     * @param DbLanguage $language The window's language
     * @return list<TableRowMutationDTO> The country's row created or removed, or nothing
     * @throws HilosException When an i18n collection cannot be read
     */
    private function countryMutations(SourceChange $change, DbLanguage $language): array
    {
        if ($change->mutationType === TableMutationType::Delete) {
            $countryCode = $change->row[ObjectCountry::code] ?? null;

            return is_string($countryCode)
                ? [$this->mutation(TableMutationType::Delete, ObjectLocale::codeFor($language->code, $countryCode))]
                : [];
        }
        if ($change->mutationType !== TableMutationType::Create) {
            return [];
        }

        $country = Hilos::$db->countries[(int) $change->sourceId];
        if ($country === null) {
            return [];
        }

        $row = $this->countryRow($language, $country);

        return [$this->mutation(TableMutationType::Create, $row->rowKey, $row)];
    }

    /**
     * Builds the mutation a change of one country name makes in the window of a language.
     *
     * Only the base name written in the window's language labels a row; a name in another language
     * and the correction of a locale are somebody else's text.
     *
     * @param SourceChange $change Change of the country names collection
     * @param DbLanguage $language The window's language
     * @return list<TableRowMutationDTO> The update of the named country's row, or nothing
     * @throws HilosException When an i18n collection cannot be read
     */
    private function countryNameMutations(SourceChange $change, DbLanguage $language): array
    {
        $name = Hilos::$db->countryNames[(int) $change->sourceId];
        $languageId = $name !== null ? $name->languageId : self::rowId($change->row, ObjectCountryName::languageId);
        $localeId = $name !== null ? $name->localeId : self::rowId($change->row, ObjectCountryName::localeId);
        $countryId = $name !== null ? $name->countryId : self::rowId($change->row, ObjectCountryName::countryId);
        if ($languageId !== (int) $language->id || $localeId !== null || $countryId === null) {
            return [];
        }

        $country = Hilos::$db->countries[$countryId];
        if ($country === null) {
            return [];
        }

        $row = $this->countryRow($language, $country);

        return [$this->mutation(TableMutationType::Update, $row->rowKey, $row)];
    }

    /**
     * Builds the mutations a change of one language makes in the window of a language that stands.
     *
     * Only the window's own language counts, and while it stands only its return does: the
     * language created again under the same code brings every row back.
     *
     * @param SourceChange $change Change of the languages collection
     * @param DbLanguage $language The window's language
     * @return list<TableRowMutationDTO> A creation per row when the language came back, or nothing
     * @throws HilosException When an i18n collection cannot be read
     */
    private function languageMutations(SourceChange $change, DbLanguage $language): array
    {
        if ($change->mutationType !== TableMutationType::Create || (int) $change->sourceId !== (int) $language->id) {
            return [];
        }

        $mutations = [$this->mutation(TableMutationType::Create, (string) $language->code, $this->ownRow($language))];
        foreach ($this->countriesByCode() as $country) {
            $row = $this->countryRow($language, $country);
            $mutations[] = $this->mutation(TableMutationType::Create, $row->rowKey, $row);
        }

        return $mutations;
    }

    /**
     * Removes every row of a language that is gone.
     *
     * @param string $languageCode Code of the window's language
     * @return list<TableRowMutationDTO> A removal of its own row and of the row of every country
     * @throws HilosException When the countries cannot be read
     */
    private function removeEveryRow(string $languageCode): array
    {
        $mutations = [$this->mutation(TableMutationType::Delete, $languageCode)];
        foreach ($this->countriesByCode() as $country) {
            $mutations[] = $this->mutation(TableMutationType::Delete, ObjectLocale::codeFor($languageCode, $country->code));
        }

        return $mutations;
    }

    /**
     * Builds the row of the language alone.
     *
     * @param DbLanguage $language The window's language
     * @return HilosI18nLanguageLocalesTableRow The row, with the locale of no country when there is one
     * @throws HilosException When the locales cannot be read
     */
    private function ownRow(DbLanguage $language): HilosI18nLanguageLocalesTableRow
    {
        return self::row($language->code, null, null, Hilos::$db->locales[(string) $language->code]);
    }

    /**
     * Builds the row of one country in the window of a language.
     *
     * @param DbLanguage $language The window's language
     * @param DbCountry $country The row's country
     * @return HilosI18nLanguageLocalesTableRow The row, with the pair's locale when there is one
     * @throws HilosException When an i18n collection cannot be read
     */
    private function countryRow(DbLanguage $language, DbCountry $country): HilosI18nLanguageLocalesTableRow
    {
        return self::row(
            $language->code,
            $country->code,
            Hilos::$db->countryNames->findBase((int) $country->id, (int) $language->id)?->name,
            Hilos::$db->locales[ObjectLocale::codeFor($language->code, $country->code)],
        );
    }

    /**
     * Reads every country of the system, by code.
     *
     * @return list<DbCountry> Countries ordered by code
     * @throws HilosException When the countries cannot be read
     */
    private function countriesByCode(): array
    {
        $countries = Hilos::$db->countries->queryPageItems(new TableQueryDTO())[TableConstants::RESULT_KEY_ROWS];
        usort($countries, static fn(DbCountry $left, DbCountry $right): int => strcmp($left->code, $right->code));

        return $countries;
    }

    /**
     * Puts one row together.
     *
     * @param string $languageCode Code of the window's language
     * @param ?string $countryCode Code of the row's country, null on the language's own row
     * @param ?string $countryName Base name of the country in the window's language, null when none is written
     * @param ?DbLocale $locale The pair's locale, null when the pair has none
     * @return HilosI18nLanguageLocalesTableRow The row
     */
    private static function row(
        string $languageCode,
        ?string $countryCode,
        ?string $countryName,
        ?DbLocale $locale,
    ): HilosI18nLanguageLocalesTableRow {
        $catalog = BuiltInI18nCatalog::locale(ObjectLocale::codeFor($languageCode, $countryCode));

        return new HilosI18nLanguageLocalesTableRow(
            rowKey: ObjectLocale::codeFor($languageCode, $countryCode),
            countryCode: $countryCode,
            countryName: $countryName,
            localeCode: $locale?->code,
            enabled: $locale?->enabled,
            formats: $locale === null ? null : LocaleFormats::ofLocale($locale),
            catalogFormats: $catalog === null ? null : LocaleFormats::ofCatalog($catalog),
        );
    }

    /**
     * Splits a row key back into the language and the country it pairs.
     *
     * @param string $rowKey The pair, written as the code of its locale
     * @return array{0: string, 1: ?string} Language code, and the country code as stored or null for the language alone
     */
    private static function pairOf(string $rowKey): array
    {
        $parts = explode(self::PAIR_SEPARATOR, $rowKey, 2);

        return [$parts[0], isset($parts[1]) ? strtolower($parts[1]) : null];
    }

    /**
     * Reads the code of the language the window is opened on.
     *
     * @param TableQueryDTO $query Window query
     * @return ?string Language code, or null when the window names none
     */
    private static function languageCodeOf(TableQueryDTO $query): ?string
    {
        $code = $query->filter[self::FILTER_LANGUAGE] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * Reads a primary id out of a changed row, which the source keys by object field.
     *
     * Read only where the row is gone and the change is all that is left of it: a removal carries
     * the row as it stood, while an edit carries only the fields that moved.
     *
     * @param array<string, mixed> $row Changed row as the source carries it
     * @param string $field Object field holding the id
     * @return ?int The id, or null when the row does not carry it
     */
    private static function rowId(array $row, string $field): ?int
    {
        $value = $row[$field] ?? null;
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return is_int($value) ? $value : null;
    }
}
