<?php

declare(strict_types=1);

namespace Hilos\Tables\I18n;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Source\SourceChange;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\CountryName as ObjectCountryName;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * Framework table of the names of one country: how it is called in every language of the system (HIL-1477).
 *
 * The window is opened on the country whose card it stands on, by the {@see self::FILTER_COUNTRY}
 * key the page presets from its address, and has a row for every language.
 *
 * A project activates the table by registering it under {@see self::TABLE} and binding it to the
 * country names page in {@see Hilos::PAGE_TABLES}.
 */
final class HilosI18nCountryNamesTable extends AbstractHilosI18nNamesTable
{
    /** Canonical table key under which a project registers this table in its TableContext. */
    public const string TABLE = 'hilosI18nCountryNames';

    /** Filter-map key: the code of the country the window is opened on. */
    public const string FILTER_COUNTRY = 'country';

    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            self::LANGUAGES_SOURCE,
            self::LOCALES_SOURCE,
            self::COUNTRY_NAMES_SOURCE,
            self::COUNTRIES_SOURCE,
        ],
        BrowserTableConfigKey::ROWS => [
            self::LANGUAGES_ROW,
            self::LOCALES_ROW,
            self::COUNTRY_NAMES_ROW,
            self::COUNTRIES_ROW,
        ],
    ];

    /**
     * @return string Filter-map key holding the country's code
     */
    protected function filterKey(): string
    {
        return self::FILTER_COUNTRY;
    }

    /**
     * @return string The countries collection
     */
    protected function subjectCollection(): string
    {
        return HilosDbContext::countries;
    }

    /**
     * @return string The country names collection
     */
    protected function namesCollection(): string
    {
        return HilosDbContext::countryNames;
    }

    /**
     * @return WireField The name column of the country names
     */
    protected function nameWireField(): WireField
    {
        return WireField::column(HilosDbContext::countryNames, ObjectCountryName::name);
    }

    /**
     * @param string $code Country code
     * @return ?int Primary id of the country, or null when no country has that code
     * @throws HilosException When the countries cannot be read
     */
    protected function subjectId(string $code): ?int
    {
        return Hilos::$db->countries[$code]?->id;
    }

    /**
     * @param int $subjectId The named country
     * @param int $languageId Language the name is written in
     * @return ?string Base name, or null when none is written
     * @throws HilosException When the names cannot be read
     */
    protected function baseName(int $subjectId, int $languageId): ?string
    {
        return Hilos::$db->countryNames->findBase($subjectId, $languageId)?->name;
    }

    /**
     * @param int $subjectId The named country
     * @param int $languageId Language the base name is written in
     * @return array<int, string> Correction by the primary id of its locale
     * @throws HilosException When the names cannot be read
     */
    protected function overrideNames(int $subjectId, int $languageId): array
    {
        $names = [];
        foreach (Hilos::$db->countryNames->overridesFor($subjectId, $languageId) as $override) {
            if ($override->localeId !== null) {
                $names[$override->localeId] = $override->name;
            }
        }

        return $names;
    }

    /**
     * @param SourceChange $change Change of the country names
     * @return ?array{0: int, 1: int} Named country and the language the name is written in, or null when unknown
     * @throws HilosException When the names cannot be read
     */
    protected function nameCoordinates(SourceChange $change): ?array
    {
        $name = Hilos::$db->countryNames[(int) $change->sourceId];
        $countryId = $name?->countryId ?? self::rowId($change->row, ObjectCountryName::countryId);
        $languageId = $name?->languageId ?? self::rowId($change->row, ObjectCountryName::languageId);

        return $countryId === null || $languageId === null ? null : [$countryId, $languageId];
    }
}
