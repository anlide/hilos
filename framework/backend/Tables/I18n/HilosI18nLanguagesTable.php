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
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
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
use Hilos\Database\Object\Item\Language as ObjectLanguage;
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
final class HilosI18nLanguagesTable extends TableDefinition implements ViewportTable
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
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => self::LANGUAGES_SOURCE,
                BrowserTableFieldKey::ROW_KEY => ObjectLanguage::code,
                BrowserTableFieldKey::FIELDS => [
                    ObjectLanguage::code => HilosI18nLanguagesTableRow::code,
                    ObjectLanguage::nativeName => HilosI18nLanguagesTableRow::nativeName,
                    ObjectLanguage::rtl => HilosI18nLanguagesTableRow::rtl,
                    ObjectLanguage::enabled => HilosI18nLanguagesTableRow::enabled,
                ],
                BrowserTableFieldKey::COMPUTED => [
                    HilosI18nLanguagesTableRow::isDefault,
                    HilosI18nLanguagesTableRow::isOwn,
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
     * A deletion names the code the removed row published. A create or an update is read
     * back by the row's id, and a row that is already gone is ignored.
     *
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Language row mutation, or null when the change does not affect this table
     * @throws DatabaseException When a language cannot be read
     * @throws LogicException When the language collection is not configured
     * @throws InvalidArgumentException When a loaded language has the wrong type
     * @throws EnvException When the default language setting is absent
     * @throws EnvInvalidValueException When its configured code is unknown
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== HilosDbContext::languages) {
            return null;
        }

        if ($change->mutationType === TableMutationType::Delete) {
            $code = $change->row[ObjectLanguage::code] ?? null;

            return is_string($code) ? $this->mutation(TableMutationType::Delete, $code) : null;
        }

        $language = Hilos::$db->languages[(int) $change->sourceId];
        if ($language === null) {
            return null;
        }

        return $this->mutation(
            $change->mutationType,
            $language->code,
            $this->rowForLanguage($language, DefaultLanguage::code()),
        );
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
     * The two marks are computed from the environment and the built-in catalog.
     *
     * @return array<string, WireField> Row field to where it comes from
     */
    public function wireFields(): array
    {
        return [
            HilosI18nLanguagesTableRow::code => WireField::column(HilosDbContext::languages, ObjectLanguage::code),
            HilosI18nLanguagesTableRow::nativeName => WireField::column(
                HilosDbContext::languages,
                ObjectLanguage::nativeName,
            ),
            HilosI18nLanguagesTableRow::rtl => WireField::column(HilosDbContext::languages, ObjectLanguage::rtl),
            HilosI18nLanguagesTableRow::enabled => WireField::column(HilosDbContext::languages, ObjectLanguage::enabled),
            HilosI18nLanguagesTableRow::isDefault => WireField::notPersonal(),
            HilosI18nLanguagesTableRow::isOwn => WireField::notPersonal(),
        ];
    }

    /**
     * Whether the language is in the set this window would show.
     *
     * The set is one row, searched the same way the window is, so a new language under an
     * active search is announced only when the search would keep it.
     *
     * @param string|int $rowKey Language code
     * @param TableQueryDTO $query Window query whose search describes the set
     * @return ?bool Whether the language is in the set; this table always knows
     * @throws DatabaseException When a language cannot be read
     * @throws LogicException When the language collection is not configured
     * @throws InvalidArgumentException When a loaded language has the wrong type
     * @throws EnvException When the default language setting is absent
     * @throws EnvInvalidValueException When its configured code is unknown
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        $language = Hilos::$db->languages[(string) $rowKey];
        if ($language === null) {
            return false;
        }

        return $this->containsRowInMemory(
            [$this->rowForLanguage($language, DefaultLanguage::code())->toArray()],
            $language->code,
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
     */
    private function rowForLanguage(Language $language, string $defaultCode): HilosI18nLanguagesTableRow
    {
        return new HilosI18nLanguagesTableRow(
            code: $language->code,
            nativeName: $language->nativeName,
            rtl: $language->rtl,
            enabled: $language->enabled,
            isDefault: $language->code === $defaultCode,
            isOwn: BuiltInI18nCatalog::language($language->code) === null,
        );
    }
}
