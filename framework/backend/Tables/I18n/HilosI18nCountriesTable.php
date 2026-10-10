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
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\Country as ObjectCountry;
use Hilos\Database\Object\Item\CountryName as ObjectCountryName;
use Hilos\Database\Object\Item\Language as ObjectLanguage;
use Hilos\Database\Object\Item\Locale as ObjectLocale;
use Hilos\Database\View\Item\Country;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\DefaultLanguage;

/**
 * Framework table of every country of the system (HIL-1475).
 *
 * Rows are built in memory: the name is read from another table, the "own" mark is computed,
 * and a country set is dozens of rows. The first window is the framework's own twenty-five,
 * so fifty-one known countries fill three pages. A project activates the table by registering
 * it under {@see self::TABLE} and binding it to the countries list page in {@see Hilos::PAGE_TABLES}.
 */
final class HilosI18nCountriesTable extends TableDefinition implements ViewportTable
{
    /** Canonical table key under which a project registers this table in its TableContext. */
    public const string TABLE = 'hilosI18nCountries';

    /** Wire slot the row payload rides under; must match the frontend country slot. */
    public const string ROW_SLOT = 'country';

    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            self::COUNTRIES_SOURCE,
            self::COUNTRY_NAMES_SOURCE,
            self::LOCALES_SOURCE,
            self::LANGUAGES_SOURCE,
        ],
        BrowserTableConfigKey::ROWS => [
            [
                BrowserTableFieldKey::SOURCE => self::COUNTRIES_SOURCE,
                BrowserTableFieldKey::ROW_KEY => ObjectCountry::code,
                BrowserTableFieldKey::FIELDS => [
                    ObjectCountry::code => HilosI18nCountriesTableRow::code,
                    ObjectCountry::currencySymbol => HilosI18nCountriesTableRow::currencySymbol,
                    ObjectCountry::currencyCode => HilosI18nCountriesTableRow::currencyCode,
                    ObjectCountry::enabled => HilosI18nCountriesTableRow::enabled,
                ],
                BrowserTableFieldKey::COMPUTED => [
                    HilosI18nCountriesTableRow::name,
                    HilosI18nCountriesTableRow::defaultLocaleCode,
                    HilosI18nCountriesTableRow::isOwn,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => self::COUNTRY_NAMES_SOURCE,
                BrowserTableFieldKey::ROW_KEY => ObjectCountryName::countryId,
                BrowserTableFieldKey::MANY => true,
                BrowserTableFieldKey::FIELDS => [
                    ObjectCountryName::countryId,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => self::LOCALES_SOURCE,
                BrowserTableFieldKey::ROW_KEY => ObjectLocale::id,
                BrowserTableFieldKey::FIELDS => [
                    ObjectLocale::id,
                ],
            ],
            [
                BrowserTableFieldKey::SOURCE => self::LANGUAGES_SOURCE,
                BrowserTableFieldKey::ROW_KEY => ObjectLanguage::code,
                BrowserTableFieldKey::FIELDS => [
                    ObjectLanguage::code,
                ],
            ],
        ],
    ];

    private const array COUNTRIES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::countries,
    ];

    private const array COUNTRY_NAMES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::countryNames,
    ];

    private const array LOCALES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::locales,
    ];

    private const array LANGUAGES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::languages,
    ];

    /**
     * @return ?TableSortOrderDTO First window ordered by country code ascending
     */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(HilosI18nCountriesTableRow::code));
    }

    /**
     * A country change redraws that country. A base name in the default language redraws its
     * country. A locale, a language, and every other name are ignored: a locale code never
     * changes, a default locale is not removed, and the default language changes only with the
     * environment and a restart.
     *
     * A deletion of a country names the code the removed row published. A create or an update
     * is read back by the row's id, and a row that is already gone is ignored.
     *
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Country row mutation, or null when the change does not affect this table
     * @throws DatabaseException When a country, a name or a locale cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded row has the wrong type
     * @throws EnvException When the default language setting is absent
     * @throws EnvInvalidValueException When its configured code is unknown
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey === HilosDbContext::countries) {
            return $this->mutationForCountry($change);
        }
        if ($change->sourceKey === HilosDbContext::countryNames) {
            return $this->mutationForCountryName($change);
        }

        return null;
    }

    /**
     * @param AbstractTableRow $row Country row from this table's window or mutation
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
     * The stored country fields are columns of hilos_country, the name is a column of
     * hilos_country_name, and the locale code is a column of hilos_locale. None of them is
     * personal. The "own" mark is computed from the built-in catalog.
     *
     * @return array<string, WireField> Row field to where it comes from
     */
    public function wireFields(): array
    {
        return [
            HilosI18nCountriesTableRow::code => WireField::column(HilosDbContext::countries, ObjectCountry::code),
            HilosI18nCountriesTableRow::currencySymbol => WireField::column(
                HilosDbContext::countries,
                ObjectCountry::currencySymbol,
            ),
            HilosI18nCountriesTableRow::currencyCode => WireField::column(
                HilosDbContext::countries,
                ObjectCountry::currencyCode,
            ),
            HilosI18nCountriesTableRow::enabled => WireField::column(HilosDbContext::countries, ObjectCountry::enabled),
            HilosI18nCountriesTableRow::name => WireField::column(HilosDbContext::countryNames, ObjectCountryName::name),
            HilosI18nCountriesTableRow::defaultLocaleCode => WireField::column(
                HilosDbContext::locales,
                ObjectLocale::code,
            ),
            HilosI18nCountriesTableRow::isOwn => WireField::notPersonal(),
        ];
    }

    /**
     * Whether the country is in the set this window would show.
     *
     * The set is one row, searched the same way the window is, so a new country under an
     * active search is announced only when the search would keep it.
     *
     * @param string|int $rowKey Country code
     * @param TableQueryDTO $query Window query whose search describes the set
     * @return ?bool Whether the country is in the set; this table always knows
     * @throws DatabaseException When a country, a name or a locale cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded row has the wrong type
     * @throws EnvException When the default language setting is absent
     * @throws EnvInvalidValueException When its configured code is unknown
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        $country = Hilos::$db->countries[(string) $rowKey];
        if ($country === null) {
            return false;
        }

        return $this->containsRowInMemory(
            [$this->rowForCountry($country, $this->defaultLanguageId())->toArray()],
            $country->code,
            $this->scopeSearch($query),
        );
    }

    /**
     * Every country, searched and ordered in memory.
     *
     * The default language is read once, and every base name in that language is read once,
     * rather than once per country.
     *
     * @param TableQueryDTO $query Window query, its search already scoped
     * @return TableSnapshotDTO Countries window
     * @throws DatabaseException When the countries, names or locales cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded row has the wrong type
     * @throws EnvException When the default language setting is absent
     * @throws EnvInvalidValueException When its configured code is unknown
     * @throws ObjectGetIdStringNotImplementedException When a matched name cannot name its id
     * @throws CollectionNotManualException When a name collection refuses a matched item
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $defaultLanguageId = $this->defaultLanguageId();
        $baseNames = $this->baseNames($defaultLanguageId);
        $result = Hilos::$db->countries->queryPageItems(new TableQueryDTO());

        return $this->filterInMemory(
            array_map(
                fn (Country $country): array => $this->rowForCountry($country, $defaultLanguageId, $baseNames)->toArray(),
                $result[TableConstants::RESULT_KEY_ROWS],
            ),
            $query,
        );
    }

    /**
     * @return array<string, string> Sortable country fields, each ordered by itself
     */
    protected function sortableFields(): array
    {
        return [
            HilosI18nCountriesTableRow::code => HilosI18nCountriesTableRow::code,
            HilosI18nCountriesTableRow::name => HilosI18nCountriesTableRow::name,
            HilosI18nCountriesTableRow::currencyCode => HilosI18nCountriesTableRow::currencyCode,
            HilosI18nCountriesTableRow::defaultLocaleCode => HilosI18nCountriesTableRow::defaultLocaleCode,
            HilosI18nCountriesTableRow::enabled => HilosI18nCountriesTableRow::enabled,
        ];
    }

    /**
     * @return array<string, string> Code, name and currency code, searched as substrings in memory
     */
    protected function searchableFields(): array
    {
        return [
            HilosI18nCountriesTableRow::code => HilosI18nCountriesTableRow::code,
            HilosI18nCountriesTableRow::name => HilosI18nCountriesTableRow::name,
            HilosI18nCountriesTableRow::currencyCode => HilosI18nCountriesTableRow::currencyCode,
        ];
    }

    /**
     * Configures the row shape used by the countries table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosI18nCountriesTableRow::class);
    }

    /**
     * @param SourceChange $change Country collection change
     * @return ?TableRowMutationDTO Country row mutation, or null when the country is already gone
     * @throws DatabaseException When a country, a name or a locale cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded row has the wrong type
     * @throws EnvException When the default language setting is absent
     * @throws EnvInvalidValueException When its configured code is unknown
     */
    private function mutationForCountry(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->mutationType === TableMutationType::Delete) {
            $code = $change->row[ObjectCountry::code] ?? null;

            return is_string($code) ? $this->mutation(TableMutationType::Delete, $code) : null;
        }

        $country = Hilos::$db->countries[(int) $change->sourceId];
        if ($country === null) {
            return null;
        }

        return $this->mutation(
            $change->mutationType,
            $country->code,
            $this->rowForCountry($country, $this->defaultLanguageId()),
        );
    }

    /**
     * A base name in the default language redraws its country. Any other name does not.
     *
     * @param SourceChange $change Country-name collection change
     * @return ?TableRowMutationDTO Update of the named country, or null when the name is not that base
     * @throws DatabaseException When a country, a name or a locale cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded row has the wrong type
     * @throws EnvException When the default language setting is absent
     * @throws EnvInvalidValueException When its configured code is unknown
     */
    private function mutationForCountryName(SourceChange $change): ?TableRowMutationDTO
    {
        $facts = $this->nameFacts($change);
        $defaultLanguageId = $this->defaultLanguageId();
        if (
            $facts === null
            || $defaultLanguageId === null
            || $facts[ObjectCountryName::languageId] !== $defaultLanguageId
            || $facts[ObjectCountryName::localeId] !== null
        ) {
            return null;
        }

        $country = Hilos::$db->countries[$facts[ObjectCountryName::countryId]];
        if ($country === null) {
            return null;
        }

        return $this->mutation(
            TableMutationType::Update,
            $country->code,
            $this->rowForCountry($country, $defaultLanguageId),
        );
    }

    /**
     * @param Country $country Country to project
     * @param ?int $defaultLanguageId Id of the installation default language, read once for the query
     * @param ?array<int, string> $baseNames Base names by country id, when the query already read them
     * @return HilosI18nCountriesTableRow Country table row
     * @throws DatabaseException When a name or a locale cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded row has the wrong type
     */
    private function rowForCountry(Country $country, ?int $defaultLanguageId, ?array $baseNames = null): HilosI18nCountriesTableRow
    {
        return new HilosI18nCountriesTableRow(
            code: $country->code,
            name: $this->nameOf($country, $defaultLanguageId, $baseNames),
            currencySymbol: $country->currencySymbol,
            currencyCode: $country->currencyCode,
            defaultLocaleCode: $country->defaultLocaleId === null
                ? null
                : Hilos::$db->locales[$country->defaultLocaleId]?->code,
            enabled: $country->enabled,
            isOwn: BuiltInI18nCatalog::country($country->code) === null,
        );
    }

    /**
     * The same rule as the country card summary: the base name in the default language, and an
     * empty string is the same as no name.
     *
     * @param Country $country Country the name belongs to
     * @param ?int $defaultLanguageId Id of the installation default language
     * @param ?array<int, string> $baseNames Base names by country id, when the query already read them
     * @return ?string Non-empty base name, or null
     * @throws DatabaseException When the name cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded name has the wrong type
     */
    private function nameOf(Country $country, ?int $defaultLanguageId, ?array $baseNames): ?string
    {
        if ($country->id === null) {
            return null;
        }
        if ($baseNames !== null) {
            return $baseNames[$country->id] ?? null;
        }
        if ($defaultLanguageId === null) {
            return null;
        }

        $name = Hilos::$db->countryNames->findBase($country->id, $defaultLanguageId)?->name;

        return $name === '' ? null : $name;
    }

    /**
     * @param ?int $defaultLanguageId Id of the installation default language
     * @return array<int, string> Non-empty base names in that language, by country id
     * @throws DatabaseException When the names cannot be read
     * @throws LogicException When a collection is not configured
     * @throws InvalidArgumentException When a loaded name has the wrong type
     * @throws ObjectGetIdStringNotImplementedException When a matched name cannot name its id
     * @throws CollectionNotManualException When the name collection refuses a matched item
     */
    private function baseNames(?int $defaultLanguageId): array
    {
        if ($defaultLanguageId === null) {
            return [];
        }

        $names = [];
        foreach (Hilos::$db->countryNames->whereColumnIs(ObjectCountryName::languageId, $defaultLanguageId) as $countryName) {
            if ($countryName->localeId !== null || $countryName->name === '') {
                continue;
            }
            $names[$countryName->countryId] = $countryName->name;
        }

        return $names;
    }

    /**
     * @return ?int Id of the installation default language, or null when that language has no row
     * @throws DatabaseException When the language cannot be read
     * @throws LogicException When the language collection is not configured
     * @throws InvalidArgumentException When a loaded language has the wrong type
     * @throws EnvException When the default language setting is absent
     * @throws EnvInvalidValueException When its configured code is unknown
     */
    private function defaultLanguageId(): ?int
    {
        return Hilos::$db->languages[DefaultLanguage::code()]?->id;
    }

    /**
     * Country, language and locale of the announced name, from the row the change published
     * or, where that row is silent, from the name still stored.
     *
     * @param SourceChange $change Country-name collection change
     * @return ?array{countryId: int, languageId: int, localeId: ?int} Facts of the name, or null when they cannot be read
     * @throws DatabaseException When the stored name cannot be read
     * @throws LogicException When the name collection is not configured
     * @throws InvalidArgumentException When a loaded name has the wrong type
     */
    private function nameFacts(SourceChange $change): ?array
    {
        $row = $change->row;
        $countryId = self::rowInt($row, ObjectCountryName::countryId);
        $languageId = self::rowInt($row, ObjectCountryName::languageId);
        $localeKnown = array_key_exists(ObjectCountryName::localeId, $row)
            && ($row[ObjectCountryName::localeId] === null || is_int($row[ObjectCountryName::localeId]));
        $localeId = $localeKnown && is_int($row[ObjectCountryName::localeId] ?? null)
            ? $row[ObjectCountryName::localeId]
            : null;

        if ($countryId === null || $languageId === null || !$localeKnown) {
            $stored = Hilos::$db->countryNames[(int) $change->sourceId];
            if ($stored === null) {
                return null;
            }
            $countryId ??= $stored->countryId;
            $languageId ??= $stored->languageId;
            if (!$localeKnown) {
                $localeId = $stored->localeId;
            }
        }

        return [
            ObjectCountryName::countryId => $countryId,
            ObjectCountryName::languageId => $languageId,
            ObjectCountryName::localeId => $localeId,
        ];
    }

    /**
     * @param array<string, mixed> $row Announced row
     * @param string $key Field to read
     * @return ?int Integer stored under the key, or null when the key is absent or holds something else
     */
    private static function rowInt(array $row, string $key): ?int
    {
        if (!array_key_exists($key, $row) || !is_int($row[$key])) {
            return null;
        }

        return $row[$key];
    }
}
