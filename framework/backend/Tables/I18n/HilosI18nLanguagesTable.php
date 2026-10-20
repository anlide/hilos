<?php

declare(strict_types=1);

namespace Hilos\Tables\I18n;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\MultiRowViewportTable;
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
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\CountryName as EntityCountryName;
use Hilos\Database\Entity\Item\LanguageName as EntityLanguageName;
use Hilos\Database\Entity\Item\Locale as EntityLocale;
use Hilos\Database\Object\Item\CountryName as ObjectCountryName;
use Hilos\Database\Object\Item\Language as ObjectLanguage;
use Hilos\Database\Object\Item\LanguageName as ObjectLanguageName;
use Hilos\Database\Object\Item\Locale as ObjectLocale;
use Hilos\Database\View\Item\Language;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\DefaultLanguage;

/**
 * Framework table of every language of the system (HIL-1474).
 *
 * Rows are built in memory: the marks are computed, and a language set is dozens of rows.
 * A project activates the table by registering it under {@see self::TABLE} and binding it
 * to the languages list page in {@see Hilos::PAGE_TABLES}.
 */
final class HilosI18nLanguagesTable extends TableDefinition implements MultiRowViewportTable
{
    /** Canonical table key under which a project registers this table in its TableContext. */
    public const string TABLE = 'hilosI18nLanguages';

    /** Wire slot the row payload rides under; must match the frontend language slot. */
    public const string ROW_SLOT = 'language';

    /**
     * Rows the first window carries.
     *
     * The built-in catalog holds fifty languages, and one window holds them all.
     */
    private const int WINDOW_ROWS = 100;

    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            self::LANGUAGES_SOURCE,
            [BrowserSourceKey::TYPE => BrowserSourceType::DB, BrowserSourceKey::KEY => HilosDbContext::locales],
            [BrowserSourceKey::TYPE => BrowserSourceType::DB, BrowserSourceKey::KEY => HilosDbContext::languageNames],
            [BrowserSourceKey::TYPE => BrowserSourceType::DB, BrowserSourceKey::KEY => HilosDbContext::countryNames],
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => self::LANGUAGES_SOURCE,
                BrowserTableFieldKey::ROW_KEY => ObjectLanguage::id,
                BrowserTableFieldKey::FIELDS => [
                    ObjectLanguage::id => HilosI18nLanguagesTableRow::languageId,
                    ObjectLanguage::code => HilosI18nLanguagesTableRow::code,
                    ObjectLanguage::nativeName => HilosI18nLanguagesTableRow::nativeName,
                    ObjectLanguage::rtl => HilosI18nLanguagesTableRow::rtl,
                    ObjectLanguage::enabled => HilosI18nLanguagesTableRow::enabled,
                ],
                BrowserTableFieldKey::COMPUTED => [
                    HilosI18nLanguagesTableRow::isDefault,
                    HilosI18nLanguagesTableRow::isOwn,
                    HilosI18nLanguagesTableRow::canDelete,
                    HilosI18nLanguagesTableRow::deleteReason,
                ],
            ],
        ],
    ];

    private const array LANGUAGES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::languages,
    ];

    /**
     * @return int Rows the first window carries
     */
    public function windowSize(): int
    {
        return self::WINDOW_ROWS;
    }

    /**
     * @return ?TableSortOrderDTO First window ordered by language code ascending
     */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(HilosI18nLanguagesTableRow::code));
    }

    /**
     * A change of the languages collection redraws that language; anything else is ignored.
     *
     * A deletion names the removed row's primary ID. A create or an update is read
     * back by the row's id, and a row that is already gone is ignored.
     *
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Language row mutation, or null when the change does not affect this table
     * @throws DatabaseException When a language cannot be read
     * @throws LogicException When the language collection is not configured
     * @throws InvalidArgumentException When a loaded language has the wrong type
     * @throws EnvException When the default language setting is absent
     * @throws EnvInvalidValueException When its configured code is unknown
     * @throws InvalidFormatException When a projected language ID is not positive
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== HilosDbContext::languages) {
            return null;
        }

        if ($change->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, (string) $change->sourceId);
        }

        if (!isset(Hilos::$db->languages[(int) $change->sourceId])) {
            return null;
        }
        $language = Hilos::$db->languages[(int) $change->sourceId];

        return $this->mutation(
            $change->mutationType,
            (string) $language->id,
            $this->rowForLanguage($language, DefaultLanguage::code()),
        );
    }

    /**
     * Dependent rows redraw every language they reference, by primary id in either row vocabulary.
     * Previous coordinates also release languages a dependency moved away from.
     *
     * @param SourceChange $change Source fact from the language or its dependencies
     * @return list<TableRowMutationDTO> Current language rows, without duplicates or vanished languages
     * @throws DatabaseException When a source or summary cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded item has the wrong type
     * @throws EnvException When the default language setting is absent
     * @throws EnvInvalidValueException When its configured code is unknown
     * @throws InvalidFormatException When a projected language ID is not positive
     */
    public function buildMutationsForSourceEvent(SourceChange $change): array
    {
        if ($change->kind !== SourceChange::KIND_DB) {
            return [];
        }
        if ($change->sourceKey === HilosDbContext::languages) {
            $mutation = $this->buildMutationForSourceEvent($change);

            return $mutation === null ? [] : [$mutation];
        }

        $fields = match ($change->sourceKey) {
            HilosDbContext::locales => [ObjectLocale::languageId => EntityLocale::language_id],
            HilosDbContext::countryNames => [ObjectCountryName::languageId => EntityCountryName::language_id],
            HilosDbContext::languageNames => [
                ObjectLanguageName::languageId => EntityLanguageName::language_id,
                ObjectLanguageName::inLanguageId => EntityLanguageName::in_language_id,
            ],
            default => [],
        };
        if ($fields === []) {
            return [];
        }

        $source = $change->mutationType === TableMutationType::Delete ? null : match ($change->sourceKey) {
            HilosDbContext::locales => Hilos::$db->locales[(int) $change->sourceId] ?? null,
            HilosDbContext::countryNames => Hilos::$db->countryNames[(int) $change->sourceId] ?? null,
            HilosDbContext::languageNames => Hilos::$db->languageNames[(int) $change->sourceId] ?? null,
        };
        $languageIds = [];
        foreach ([$source?->toArray() ?? [], $change->row, $change->previous] as $row) {
            foreach ($fields as $field => $column) {
                $id = $row[$field] ?? $row[$column] ?? null;
                if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                    $languageIds[(int) $id] = true;
                }
            }
        }

        $mutations = [];
        foreach (array_keys($languageIds) as $languageId) {
            if (isset(Hilos::$db->languages[$languageId])) {
                $language = Hilos::$db->languages[$languageId];
                $mutations[] = $this->mutation(
                    TableMutationType::Update,
                    (string) $language->id,
                    $this->rowForLanguage($language, DefaultLanguage::code()),
                );
            }
        }

        return $mutations;
    }

    /**
     * @param AbstractTableRow $row Language row from this table's window or mutation
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
     * The four stored fields are columns of hilos_language, none of them personal.
     * The marks and deletion verdict are computed, nonpersonal facts.
     *
     * @return array<string, WireField> Row field to where it comes from
     */
    public function wireFields(): array
    {
        return [
            HilosI18nLanguagesTableRow::rowKey => WireField::column(HilosDbContext::languages, ObjectLanguage::id),
            HilosI18nLanguagesTableRow::languageId => WireField::column(HilosDbContext::languages, ObjectLanguage::id),
            HilosI18nLanguagesTableRow::code => WireField::column(HilosDbContext::languages, ObjectLanguage::code),
            HilosI18nLanguagesTableRow::nativeName => WireField::column(
                HilosDbContext::languages,
                ObjectLanguage::nativeName,
            ),
            HilosI18nLanguagesTableRow::rtl => WireField::column(HilosDbContext::languages, ObjectLanguage::rtl),
            HilosI18nLanguagesTableRow::enabled => WireField::column(HilosDbContext::languages, ObjectLanguage::enabled),
            HilosI18nLanguagesTableRow::isDefault => WireField::notPersonal(),
            HilosI18nLanguagesTableRow::isOwn => WireField::notPersonal(),
            HilosI18nLanguagesTableRow::canDelete => WireField::notPersonal(),
            HilosI18nLanguagesTableRow::deleteReason => WireField::notPersonal(),
        ];
    }

    /**
     * Whether the language is in the set this window would show.
     *
     * The set is one row, searched the same way the window is, so a new language under an
     * active search is announced only when the search would keep it.
     *
     * @param string|int $rowKey Decimal language ID
     * @param TableQueryDTO $query Window query whose search describes the set
     * @return ?bool Whether the language is in the set; this table always knows
     * @throws DatabaseException When a language cannot be read
     * @throws LogicException When the language collection is not configured
     * @throws InvalidArgumentException When a loaded language has the wrong type
     * @throws EnvException When the default language setting is absent
     * @throws EnvInvalidValueException When its configured code is unknown
     * @throws InvalidFormatException When a projected language ID is not positive
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        $id = filter_var($rowKey, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || (string) $id !== (string) $rowKey) {
            return false;
        }
        if (!isset(Hilos::$db->languages[$id])) {
            return false;
        }
        $language = Hilos::$db->languages[$id];

        return $this->containsRowInMemory(
            [$this->rowForLanguage($language, DefaultLanguage::code())->toArray()],
            (string) $language->id,
            $this->scopeSearch($query),
        );
    }

    /**
     * Every language, searched and ordered in memory.
     *
     * The default-language code is read once for the whole set.
     *
     * @param TableQueryDTO $query Window query, its search already scoped
     * @return TableSnapshotDTO Languages window
     * @throws DatabaseException When the languages cannot be read
     * @throws LogicException When the language collection is not configured
     * @throws InvalidArgumentException When a loaded language has the wrong type
     * @throws EnvException When the default language setting is absent
     * @throws EnvInvalidValueException When its configured code is unknown
     * @throws InvalidFormatException When a projected language ID is not positive
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $defaultCode = DefaultLanguage::code();
        $result = Hilos::$db->languages->queryPageItems(new TableQueryDTO());

        return $this->filterInMemory(
            array_map(
                fn (Language $language): array => $this->rowForLanguage($language, $defaultCode)->toArray(),
                $result[TableConstants::RESULT_KEY_ROWS],
            ),
            $query,
        );
    }

    /**
     * @return array<string, string> Sortable language fields, each ordered by itself
     */
    protected function sortableFields(): array
    {
        return [
            HilosI18nLanguagesTableRow::code => HilosI18nLanguagesTableRow::code,
            HilosI18nLanguagesTableRow::nativeName => HilosI18nLanguagesTableRow::nativeName,
            HilosI18nLanguagesTableRow::rtl => HilosI18nLanguagesTableRow::rtl,
            HilosI18nLanguagesTableRow::enabled => HilosI18nLanguagesTableRow::enabled,
        ];
    }

    /**
     * @return array<string, string> Code and native name, searched as substrings in memory
     */
    protected function searchableFields(): array
    {
        return [
            HilosI18nLanguagesTableRow::code => HilosI18nLanguagesTableRow::code,
            HilosI18nLanguagesTableRow::nativeName => HilosI18nLanguagesTableRow::nativeName,
        ];
    }

    /**
     * Configures the row shape used by the languages table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosI18nLanguagesTableRow::class);
    }

    /**
     * @param Language $language Language to project
     * @param string $defaultCode Installation default, read once for the query
     * @return HilosI18nLanguagesTableRow Language table row
     * @throws DatabaseException When the summary query fails
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When the language no longer exists
     * @throws EnvException When the default language setting is absent
     * @throws EnvInvalidValueException When its configured code is unknown
     * @throws InvalidFormatException When a projected language ID is not positive
     */
    private function rowForLanguage(Language $language, string $defaultCode): HilosI18nLanguagesTableRow
    {
        $summary = Hilos::$db->languages->cardSummary($language->id);

        return new HilosI18nLanguagesTableRow(
            languageId: $language->id,
            code: $language->code,
            nativeName: $language->nativeName,
            rtl: $language->rtl,
            enabled: $language->enabled,
            isDefault: $language->code === $defaultCode,
            isOwn: BuiltInI18nCatalog::language($language->code) === null,
            canDelete: $summary->canDelete,
            deleteReason: $summary->deleteReason,
        );
    }
}
