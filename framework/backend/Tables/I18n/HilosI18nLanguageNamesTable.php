<?php

declare(strict_types=1);

namespace Hilos\Tables\I18n;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Source\SourceChange;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\LanguageName as ObjectLanguageName;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * Framework table of the names of one language: how it is called in every other language of the system (HIL-1477).
 *
 * The window is opened on the language whose card it stands on, by the {@see self::FILTER_LANGUAGE}
 * key the page presets from its address. That language has no row of its own: a language calls
 * itself by its native name, and a name of it written in itself is not a thing this table holds.
 *
 * A project activates the table by registering it under {@see self::TABLE} and binding it to the
 * language names page in {@see Hilos::PAGE_TABLES}.
 */
final class HilosI18nLanguageNamesTable extends AbstractHilosI18nNamesTable
{
    /** Canonical table key under which a project registers this table in its TableContext. */
    public const string TABLE = 'hilosI18nLanguageNames';

    /** Filter-map key: the code of the language the window is opened on. */
    public const string FILTER_LANGUAGE = 'language';

    public const array BROWSER = [
        BrowserTableConfigKey::SOURCES => [
            self::LANGUAGES_SOURCE,
            self::LOCALES_SOURCE,
            self::LANGUAGE_NAMES_SOURCE,
            self::COUNTRY_NAMES_SOURCE,
            self::COUNTRIES_SOURCE,
        ],
        BrowserTableConfigKey::ROWS => [
            self::LANGUAGES_ROW,
            self::LOCALES_ROW,
            [
                BrowserTableFieldKey::SOURCE => self::LANGUAGE_NAMES_SOURCE,
                BrowserTableFieldKey::ROW_KEY => ObjectLanguageName::inLanguageId,
                BrowserTableFieldKey::MANY => true,
                BrowserTableFieldKey::FIELDS => [ObjectLanguageName::inLanguageId],
            ],
            self::COUNTRY_NAMES_ROW,
            self::COUNTRIES_ROW,
        ],
    ];

    /** Source of the names of languages, the names this table shows. */
    private const array LANGUAGE_NAMES_SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::DB,
        BrowserSourceKey::KEY => HilosDbContext::languageNames,
    ];

    /**
     * @return string Filter-map key holding the language's code
     */
    protected function filterKey(): string
    {
        return self::FILTER_LANGUAGE;
    }

    /**
     * @return string The languages collection
     */
    protected function subjectCollection(): string
    {
        return HilosDbContext::languages;
    }

    /**
     * @return string The language names collection
     */
    protected function namesCollection(): string
    {
        return HilosDbContext::languageNames;
    }

    /**
     * @return WireField The name column of the language names
     */
    protected function nameWireField(): WireField
    {
        return WireField::column(HilosDbContext::languageNames, ObjectLanguageName::name);
    }

    /**
     * @param string $code Language code
     * @return ?int Primary id of the language, or null when no language has that code
     * @throws HilosException When the languages cannot be read
     */
    protected function subjectId(string $code): ?int
    {
        return Hilos::$db->languages[$code]?->id;
    }

    /**
     * @param int $subjectId The named language
     * @param int $languageId Language the name is written in
     * @return ?string Base name, or null when none is written
     * @throws HilosException When the names cannot be read
     */
    protected function baseName(int $subjectId, int $languageId): ?string
    {
        return Hilos::$db->languageNames->findBase($subjectId, $languageId)?->name;
    }

    /**
     * @param int $subjectId The named language
     * @param int $languageId Language the base name is written in
     * @return array<int, string> Correction by the primary id of its locale
     * @throws HilosException When the names cannot be read
     */
    protected function overrideNames(int $subjectId, int $languageId): array
    {
        $names = [];
        foreach (Hilos::$db->languageNames->overridesFor($subjectId, $languageId) as $override) {
            if ($override->localeId !== null) {
                $names[$override->localeId] = $override->name;
            }
        }

        return $names;
    }

    /**
     * @param SourceChange $change Change of the language names
     * @return ?array{0: int, 1: int} Named language and the language the name is written in, or null when unknown
     * @throws HilosException When the names cannot be read
     */
    protected function nameCoordinates(SourceChange $change): ?array
    {
        $name = Hilos::$db->languageNames[(int) $change->sourceId];
        $languageId = $name?->languageId ?? self::rowId($change->row, ObjectLanguageName::languageId);
        $inLanguageId = $name?->inLanguageId ?? self::rowId($change->row, ObjectLanguageName::inLanguageId);

        return $languageId === null || $inLanguageId === null ? null : [$languageId, $inLanguageId];
    }

    /**
     * The language the window is opened on has no row: it calls itself by its native name.
     *
     * @param int $subjectId The named language
     * @param int $languageId Primary id of the row's language
     * @return bool Whether the row is the named language's own
     */
    protected function excludesRowLanguage(int $subjectId, int $languageId): bool
    {
        return $languageId === $subjectId;
    }
}
