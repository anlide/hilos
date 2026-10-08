<?php

declare(strict_types=1);

namespace Hilos\Tables\I18n;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\WindowScopedViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
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
use Hilos\Database\View\Item\Language as DbLanguage;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\DefaultLanguage;

/**
 * One table of names on two places of the i18n section: the names of a language and the names of a country (HIL-1477).
 *
 * A row is a language of the system, enabled or not, and carries what the subject — the language or
 * the country the window is opened on — is called in it: the base name, or nothing written, and under
 * it the corrections of the editions of that language, its locales of a country. The subject is the
 * window's filter, preset by the page from its address, because a route param never reaches a table
 * query by itself. A window without a subject, or with one that no longer exists, is an empty set.
 *
 * The same language is a different row in the window of each subject, so a change is built for one
 * window at a time ({@see WindowScopedViewportTable}): a renamed name updates the row of the language
 * it is written in, a new, edited or removed language adds, updates or removes its row, a locale
 * updates the row of its language, and a country renamed in the default language relabels every row
 * whose language has a locale of that country. The removal of the subject itself empties the window.
 *
 * Corrections stand only under a base name: a correction without a base cannot exist (HIL-1468), and
 * the frontend reads an empty list as a row with nothing to expand. A locale with no country is the
 * base edition of its language and is not a correction.
 *
 * Rows are ordered by language code and served in one window of {@see self::WINDOW_ROWS}; there is no
 * search, no sort and no filter beyond the subject.
 */
abstract class AbstractHilosI18nNamesTable extends TableDefinition implements WindowScopedViewportTable
{
    /** Wire slot the row payload rides under; must match the frontend names slot. */
    public const string ROW_SLOT = 'language';

    /** Source of the languages, the anchor of every row. */
    protected const array LANGUAGES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::languages,
    ];

    /** Source of the locales, whose countries are the corrections of a row. */
    protected const array LOCALES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::locales,
    ];

    /** Source of the country names: the subject's names in the country table, a correction's label in both. */
    protected const array COUNTRY_NAMES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::countryNames,
    ];

    /** Source of the countries, whose codes stand in a correction. */
    protected const array COUNTRIES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::countries,
    ];

    /** Row of the anchor: one row per language, keyed by its code. */
    protected const array LANGUAGES_ROW = [
        BrowserTableFieldKey::SOURCE => self::LANGUAGES_SOURCE,
        BrowserTableFieldKey::ROW_KEY => ObjectLanguage::code,
        BrowserTableFieldKey::FIELDS => [ObjectLanguage::code, ObjectLanguage::nativeName],
    ];

    /** Locales joined to the row of their language. */
    protected const array LOCALES_ROW = [
        BrowserTableFieldKey::SOURCE => self::LOCALES_SOURCE,
        BrowserTableFieldKey::ROW_KEY => ObjectLocale::languageId,
        BrowserTableFieldKey::MANY => true,
        BrowserTableFieldKey::FIELDS => [ObjectLocale::languageId],
    ];

    /** Country names joined to the row of the language they are written in. */
    protected const array COUNTRY_NAMES_ROW = [
        BrowserTableFieldKey::SOURCE => self::COUNTRY_NAMES_SOURCE,
        BrowserTableFieldKey::ROW_KEY => ObjectCountryName::languageId,
        BrowserTableFieldKey::MANY => true,
        BrowserTableFieldKey::FIELDS => [ObjectCountryName::languageId],
    ];

    /** Countries, read for the code of a correction's locale. */
    protected const array COUNTRIES_ROW = [
        BrowserTableFieldKey::SOURCE => self::COUNTRIES_SOURCE,
        BrowserTableFieldKey::ROW_KEY => ObjectCountry::id,
        BrowserTableFieldKey::FIELDS => [ObjectCountry::code],
    ];

    /**
     * Rows the first window carries: the built-in catalog knows fifty languages, so every row of a
     * real installation stands on one page, and a pager appears only past a hundred.
     */
    private const int WINDOW_ROWS = 100;

    /**
     * @return int Rows the first window carries
     */
    public function windowSize(): int
    {
        return self::WINDOW_ROWS;
    }

    /**
     * Builds the row mutations one change makes in the window of one subject.
     *
     * @param SourceChange $change Source change that may affect this table
     * @param TableQueryDTO $window Query the window was served by; its filter names the subject
     * @return list<TableRowMutationDTO> Row mutations for this window, empty when the change does not touch it
     * @throws HilosException When an i18n collection or the default language cannot be read
     */
    public function buildMutationsForWindow(SourceChange $change, TableQueryDTO $window): array
    {
        $subjectId = $this->subjectIdOf($window);
        if ($subjectId === null) {
            // The subject left: nothing of the window stands on anything any more. Asked only for a
            // change of the subject's own collection, so a window opened on a code that never
            // existed is not sent a removal of every row on every write.
            return $change->sourceKey === $this->subjectCollection() ? $this->removeEveryRow() : [];
        }

        if ($change->sourceKey === HilosDbContext::languages) {
            return $this->languageMutations($change, $subjectId);
        }

        $languageIds = [];
        if ($change->sourceKey === $this->namesCollection()) {
            $coordinates = $this->nameCoordinates($change);
            if ($coordinates !== null && $coordinates[0] === $subjectId) {
                $languageIds[] = $coordinates[1];
            }
        }
        if ($change->sourceKey === HilosDbContext::countryNames) {
            array_push($languageIds, ...$this->relabelledLanguageIds($change));
        }
        if ($change->sourceKey === HilosDbContext::locales) {
            $languageId = Hilos::$db->locales[(int) $change->sourceId]?->languageId
                ?? self::rowId($change->row, ObjectLocale::languageId);
            if ($languageId !== null) {
                $languageIds[] = $languageId;
            }
        }

        return $this->rowUpdates($subjectId, array_values(array_unique($languageIds)));
    }

    /**
     * Serializes one names row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Names row from this table's window or mutation
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
     * Answers whether the window of a subject holds the row of one language.
     *
     * @param string|int $rowKey Code of the row's language
     * @param TableQueryDTO $query Window query whose filter names the subject
     * @return bool Whether the row is in the subject's set
     * @throws HilosException When an i18n collection cannot be read
     * @throws TableSearchNotSupportedException When a term arrives, this table declaring no searchable fields
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): bool
    {
        $subjectId = $this->subjectIdOf($query);
        $language = Hilos::$db->languages[(string) $rowKey];
        if ($subjectId === null || $language === null || $this->excludesRowLanguage($subjectId, (int) $language->id)) {
            return false;
        }

        return $this->containsRowInMemory(
            [[HilosI18nNameTableRow::rowKey => $language->code]],
            $rowKey,
            $this->scopeSearch($query),
        );
    }

    /**
     * Declares where each field of a names row comes from, for a viewer of the admin view mode (HIL-1250).
     *
     * Every field is a column of an i18n collection, none of them personal (HIL-1468), so a viewer
     * sees the whole row.
     *
     * @return array<string, WireField> Row field name to where it comes from
     */
    public function wireFields(): array
    {
        $name = $this->nameWireField();

        return [
            HilosI18nNameTableRow::rowKey => WireField::column(HilosDbContext::languages, ObjectLanguage::code),
            HilosI18nNameTableRow::code => WireField::column(HilosDbContext::languages, ObjectLanguage::code),
            HilosI18nNameTableRow::nativeName => WireField::column(HilosDbContext::languages, ObjectLanguage::nativeName),
            HilosI18nNameTableRow::name => $name,
            HilosI18nNameTableRow::corrections => WireField::each([
                HilosI18nNameTableRow::localeCode => WireField::column(HilosDbContext::locales, ObjectLocale::code),
                HilosI18nNameTableRow::countryCode => WireField::column(HilosDbContext::countries, ObjectCountry::code),
                HilosI18nNameTableRow::countryName => WireField::column(HilosDbContext::countryNames, ObjectCountryName::name),
                HilosI18nNameTableRow::name => $name,
            ]),
        ];
    }

    /**
     * Names the filter key the window's subject travels under.
     *
     * @return string Filter-map key holding the subject's code
     */
    abstract protected function filterKey(): string;

    /**
     * Names the collection the subject is a row of.
     *
     * @return string Database collection key of the subjects
     */
    abstract protected function subjectCollection(): string;

    /**
     * Names the collection the subject's names are rows of.
     *
     * @return string Database collection key of the names
     */
    abstract protected function namesCollection(): string;

    /**
     * Declares the column a name of the subject is read from, for the admin view mode.
     *
     * @return WireField The name column of {@see self::namesCollection()}
     */
    abstract protected function nameWireField(): WireField;

    /**
     * Finds the subject a code names.
     *
     * @param string $code Code of the subject
     * @return ?int Primary id of the subject, or null when no subject has that code
     * @throws HilosException When the subjects cannot be read
     */
    abstract protected function subjectId(string $code): ?int;

    /**
     * Reads the base name of the subject written in one language.
     *
     * @param int $subjectId Primary id of the subject
     * @param int $languageId Language the name is written in
     * @return ?string Base name, '' for a stored empty one, or null when none is written
     * @throws HilosException When the names cannot be read
     */
    abstract protected function baseName(int $subjectId, int $languageId): ?string;

    /**
     * Reads the locale corrections of the subject's base name in one language.
     *
     * @param int $subjectId Primary id of the subject
     * @param int $languageId Language the base name is written in
     * @return array<int, string> Correction by the primary id of its locale
     * @throws HilosException When the names cannot be read
     */
    abstract protected function overrideNames(int $subjectId, int $languageId): array;

    /**
     * Reads which subject and which language a change of the names collection is about.
     *
     * @param SourceChange $change Change of {@see self::namesCollection()}
     * @return ?array{0: int, 1: int} Subject id and the id of the language the name is written in,
     *     or null when the change does not say
     * @throws HilosException When the names cannot be read
     */
    abstract protected function nameCoordinates(SourceChange $change): ?array;

    /**
     * Whether the window of a subject leaves the row of a language out.
     *
     * @param int $subjectId Primary id of the subject
     * @param int $languageId Primary id of the row's language
     * @return bool Whether that row is not in the subject's set
     */
    protected function excludesRowLanguage(int $subjectId, int $languageId): bool
    {
        return false;
    }

    /**
     * Configures the row shape of the names tables.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosI18nNameTableRow::class);
    }

    /**
     * Serves the window of one subject: a row for every language of the system, by code.
     *
     * @param TableQueryDTO $query Window query whose filter names the subject
     * @return TableSnapshotDTO Names window, empty when the window names no existing subject
     * @throws HilosException When an i18n collection or the default language cannot be read
     * @throws TableSearchNotSupportedException When a term arrives, this table declaring no searchable fields
     * @throws TableSearchFieldUnknownException When a declared search field is carried by no row
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $subjectId = $this->subjectIdOf($query);
        $rows = [];
        if ($subjectId !== null) {
            $defaultLanguageId = $this->defaultLanguageId();
            foreach ($this->languagesByCode() as $language) {
                if (!$this->excludesRowLanguage($subjectId, (int) $language->id)) {
                    $rows[] = $this->rowFor($subjectId, $language, $defaultLanguageId)->toArray();
                }
            }
        }

        return $this->filterInMemory($rows, $query);
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
    protected static function rowId(array $row, string $field): ?int
    {
        $value = $row[$field] ?? null;
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return is_int($value) ? $value : null;
    }

    /**
     * Finds the subject the window is opened on.
     *
     * @param TableQueryDTO $query Window query
     * @return ?int Primary id of the subject, or null when the window names none that exists
     * @throws HilosException When the subjects cannot be read
     */
    private function subjectIdOf(TableQueryDTO $query): ?int
    {
        $code = $query->filter[$this->filterKey()] ?? null;

        return is_string($code) && $code !== '' ? $this->subjectId($code) : null;
    }

    /**
     * Builds the mutation a change of one language makes in the window of a subject.
     *
     * @param SourceChange $change Change of the languages collection
     * @param int $subjectId Primary id of the subject
     * @return list<TableRowMutationDTO> The language's row created, updated or removed, or nothing
     * @throws HilosException When an i18n collection or the default language cannot be read
     */
    private function languageMutations(SourceChange $change, int $subjectId): array
    {
        $languageId = (int) $change->sourceId;
        if ($this->excludesRowLanguage($subjectId, $languageId)) {
            return [];
        }

        if ($change->mutationType === TableMutationType::Delete) {
            $code = $change->row[ObjectLanguage::code] ?? null;

            return is_string($code) ? [$this->mutation(TableMutationType::Delete, $code)] : [];
        }

        $language = Hilos::$db->languages[$languageId];
        if ($language === null) {
            return [];
        }

        $type = $change->mutationType === TableMutationType::Create ? TableMutationType::Create : TableMutationType::Update;

        return [$this->mutation($type, $language->code, $this->rowFor($subjectId, $language, $this->defaultLanguageId()))];
    }

    /**
     * Rebuilds the rows of several languages in the window of a subject.
     *
     * @param int $subjectId Primary id of the subject
     * @param list<int> $languageIds Languages whose rows changed
     * @return list<TableRowMutationDTO> An update per row the window holds
     * @throws HilosException When an i18n collection or the default language cannot be read
     */
    private function rowUpdates(int $subjectId, array $languageIds): array
    {
        $mutations = [];
        $defaultLanguageId = $languageIds === [] ? null : $this->defaultLanguageId();
        foreach ($languageIds as $languageId) {
            $language = Hilos::$db->languages[$languageId];
            if ($language !== null && !$this->excludesRowLanguage($subjectId, $languageId)) {
                $mutations[] = $this->mutation(
                    TableMutationType::Update,
                    $language->code,
                    $this->rowFor($subjectId, $language, $defaultLanguageId),
                );
            }
        }

        return $mutations;
    }

    /**
     * Names the languages whose corrections carry the country a change of the country names relabels.
     *
     * A correction is labelled with the base name of its country in the default language, so only
     * that name moves a label; any other name of a country is somebody else's text.
     *
     * @param SourceChange $change Change of the country names collection
     * @return list<int> Languages with a locale of the renamed country
     * @throws HilosException When an i18n collection or the default language cannot be read
     */
    private function relabelledLanguageIds(SourceChange $change): array
    {
        $name = Hilos::$db->countryNames[(int) $change->sourceId];
        $countryId = $name?->countryId ?? self::rowId($change->row, ObjectCountryName::countryId);
        $languageId = $name?->languageId ?? self::rowId($change->row, ObjectCountryName::languageId);
        $localeId = $name !== null ? $name->localeId : self::rowId($change->row, ObjectCountryName::localeId);
        if ($countryId === null || $localeId !== null || $languageId === null || $languageId !== $this->defaultLanguageId()) {
            return [];
        }

        $languageIds = [];
        foreach (Hilos::$db->locales->whereColumnIs(ObjectLocale::countryId, $countryId) as $locale) {
            $languageIds[] = $locale->languageId;
        }

        return $languageIds;
    }

    /**
     * Removes the row of every language: the subject of the window is gone.
     *
     * @return list<TableRowMutationDTO> A removal per language of the system
     * @throws HilosException When the languages cannot be read
     */
    private function removeEveryRow(): array
    {
        return array_map(
            fn(DbLanguage $language): TableRowMutationDTO => $this->mutation(TableMutationType::Delete, $language->code),
            $this->languagesByCode(),
        );
    }

    /**
     * Builds the row of one language in the window of a subject.
     *
     * @param int $subjectId Primary id of the subject
     * @param DbLanguage $language The row's language
     * @param ?int $defaultLanguageId Primary id of the default language, null when it is not stored
     * @return HilosI18nNameTableRow The row
     * @throws HilosException When an i18n collection cannot be read
     */
    private function rowFor(int $subjectId, DbLanguage $language, ?int $defaultLanguageId): HilosI18nNameTableRow
    {
        $languageId = (int) $language->id;
        $name = $this->baseName($subjectId, $languageId);

        return new HilosI18nNameTableRow(
            code: $language->code,
            nativeName: $language->nativeName,
            name: $name,
            corrections: $name === null ? [] : $this->corrections($subjectId, $languageId, $defaultLanguageId),
        );
    }

    /**
     * Lists the corrections of a base name: one per locale of the language that has a country.
     *
     * @param int $subjectId Primary id of the subject
     * @param int $languageId Language the base name is written in
     * @param ?int $defaultLanguageId Primary id of the default language, null when it is not stored
     * @return list<array{localeCode: string, countryCode: string, countryName: ?string, name: ?string}> Corrections
     *     by locale code, the name null where the locale inherits the base
     * @throws HilosException When an i18n collection cannot be read
     */
    private function corrections(int $subjectId, int $languageId, ?int $defaultLanguageId): array
    {
        $overrides = $this->overrideNames($subjectId, $languageId);
        $corrections = [];
        foreach (Hilos::$db->locales->whereColumnIs(ObjectLocale::languageId, $languageId) as $locale) {
            $country = $locale->countryId === null ? null : Hilos::$db->countries[$locale->countryId];
            if ($country === null) {
                continue;
            }

            $corrections[] = [
                HilosI18nNameTableRow::localeCode => $locale->code,
                HilosI18nNameTableRow::countryCode => $country->code,
                HilosI18nNameTableRow::countryName => $defaultLanguageId === null
                    ? null
                    : Hilos::$db->countryNames->findBase((int) $country->id, $defaultLanguageId)?->name,
                HilosI18nNameTableRow::name => $overrides[(int) $locale->id] ?? null,
            ];
        }
        usort(
            $corrections,
            static fn(array $left, array $right): int => strcmp(
                $left[HilosI18nNameTableRow::localeCode],
                $right[HilosI18nNameTableRow::localeCode],
            ),
        );

        return $corrections;
    }

    /**
     * Reads every language of the system, by code.
     *
     * @return list<DbLanguage> Languages ordered by code
     * @throws HilosException When the languages cannot be read
     */
    private function languagesByCode(): array
    {
        $languages = Hilos::$db->languages->queryPageItems(new TableQueryDTO())[TableConstants::RESULT_KEY_ROWS];
        usort($languages, static fn(DbLanguage $left, DbLanguage $right): int => strcmp($left->code, $right->code));

        return $languages;
    }

    /**
     * Finds the stored default language, whose names label the countries of the corrections.
     *
     * @return ?int Primary id of the default language, or null when it is not stored yet
     * @throws HilosException When the environment or the languages cannot be read
     */
    private function defaultLanguageId(): ?int
    {
        return Hilos::$db->languages[DefaultLanguage::code()]?->id;
    }
}
