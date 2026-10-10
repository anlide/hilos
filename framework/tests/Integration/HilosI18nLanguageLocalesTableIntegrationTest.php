<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\AdminViewMode\ViewerFields;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Country as EntityCountry;
use Hilos\Database\Entity\Item\CountryName as EntityCountryName;
use Hilos\Database\Entity\Item\Language as EntityLanguage;
use Hilos\Database\Entity\Item\Locale as EntityLocale;
use Hilos\Database\Object\Item\Locale as ObjectLocale;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\Country;
use Hilos\Database\View\Item\Language;
use Hilos\Database\View\Item\Locale;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\MeasurementSystem;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\DTO\LocaleFormats;
use Hilos\Tables\I18n\HilosI18nLanguageLocalesTable;
use Hilos\Tables\I18n\HilosI18nLanguageLocalesTableRow;

/**
 * The locales table of a language against the live framework schema (HIL-1476).
 *
 * A window is opened on a language and holds the row of the language alone and a row for every
 * country, a pair of the language and the country whether a locale of it exists or not. The live
 * changes are taken off the source bus exactly as the actions announce them, so the test also holds
 * the table to the shape the source gives a changed row.
 */
final class HilosI18nLanguageLocalesTableIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = [
        'hilos_language', 'hilos_country', 'hilos_locale', 'hilos_language_name', 'hilos_country_name',
    ];
    private const string OWNER_AGENT_ID = 'test-agent:i18n-locales';

    private ?DbContext $previousDb = null;
    private string|false $previousDefaultLanguage;
    private I18nLocalesChangeLog $changes;

    private Language $english;
    private Language $german;
    private Country $britain;
    private Country $unitedStates;
    private Locale $britishEnglish;

    /**
     * @throws DatabaseException When a migration stub or schema refresh fails
     * @throws HilosException When the context or the reference rows cannot be built
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDefaultLanguage = getenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=en');
        self::runStubs(down: true);
        self::runStubs(down: false);
        Schema::reset();
        Schema::initialize();

        $this->previousDb = Hilos::$db;
        $db = new I18nLocalesTestDbContext();
        $db->configure();
        Hilos::$db = $db;

        foreach ([
            HilosDbContext::languages,
            HilosDbContext::countries,
            HilosDbContext::locales,
            HilosDbContext::languageNames,
            HilosDbContext::countryNames,
        ] as $collection) {
            TruthSourceRegistry::register($collection, TruthSourceKeys::all(), self::OWNER_AGENT_ID);
        }
        ExecutionContext::setCurrentAgentId(self::OWNER_AGENT_ID);
        SourceChangeBus::reset();

        $this->english = Hilos::$db->languages->actions->create('en', 'English', false);
        $this->german = Hilos::$db->languages->actions->create('de', 'Deutsch', false);
        $this->unitedStates = Hilos::$db->countries->actions->create('us', '$', 'USD');
        $this->britain = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        self::locale($this->english, null)->actions->switchOn();
        $this->britishEnglish = self::locale($this->english, $this->britain);
        self::locale($this->german, $this->unitedStates);
        Hilos::$db->countryNames->actions->createManual($this->britain, $this->english, null, 'United Kingdom');
        Hilos::$db->countryNames->actions->createManual($this->britain, $this->german, null, 'Vereinigtes Königreich');

        $this->changes = new I18nLocalesChangeLog();
        SourceChangeBus::subscribe($this->changes);
    }

    /** @throws DatabaseException When dropping the stub tables fails */
    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        TruthSourceRegistry::unregisterAgent(self::OWNER_AGENT_ID);
        SourceChangeBus::reset();
        Hilos::$db = $this->previousDb;
        self::runStubs(down: true);
        Schema::reset();
        if ($this->previousDefaultLanguage === false) {
            putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        } else {
            putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=' . $this->previousDefaultLanguage);
        }
        parent::tearDown();
    }

    public function testTheCodeOfALocaleIsTheLanguageOrTheLanguageAndTheUpperCasedCountry(): void
    {
        $this->assertSame('ru', ObjectLocale::codeFor('ru', null));
        $this->assertSame('ru-UA', ObjectLocale::codeFor('ru', 'ua'));
    }

    /**
     * The language alone comes first, then every country by code, whether the pair has a locale or not.
     *
     * @throws HilosException When the window cannot be read
     */
    public function testTheWindowOfALanguageHoldsItsOwnRowAndARowForEveryCountry(): void
    {
        $this->assertSame(['en', 'en-GB', 'en-US'], array_keys($this->rows('en')));
        $this->assertSame(['de', 'de-GB', 'de-US'], array_keys($this->rows('de')));
    }

    /** @throws HilosException When the window cannot be read */
    public function testAWindowWithoutALanguageOrWithAnUnknownOneIsEmpty(): void
    {
        $this->assertSame([], (new HilosI18nLanguageLocalesTable())->getPage(new TableQueryDTO())->rows);
        $this->assertSame([], $this->rows('xx'));
    }

    /**
     * A row carries the code and the switch of its locale, or nothing where the pair has no locale.
     *
     * @throws HilosException When the window cannot be read
     */
    public function testARowCarriesItsLocaleOrNone(): void
    {
        $english = $this->rows('en');
        $this->assertSame([
            HilosI18nLanguageLocalesTableRow::rowKey => 'en',
            HilosI18nLanguageLocalesTableRow::countryCode => null,
            HilosI18nLanguageLocalesTableRow::countryName => null,
            HilosI18nLanguageLocalesTableRow::localeCode => 'en',
            HilosI18nLanguageLocalesTableRow::enabled => true,
            HilosI18nLanguageLocalesTableRow::formats => LocaleFormats::ofLocale(Hilos::$db->locales['en'])->toArray(),
            HilosI18nLanguageLocalesTableRow::catalogFormats => LocaleFormats::ofCatalog(
                BuiltInI18nCatalog::locale('en'),
            )->toArray(),
        ], $english['en']);
        $this->assertSame('en-GB', $english['en-GB'][HilosI18nLanguageLocalesTableRow::localeCode]);
        $this->assertFalse($english['en-GB'][HilosI18nLanguageLocalesTableRow::enabled]);
        $this->assertNull($english['en-US'][HilosI18nLanguageLocalesTableRow::localeCode]);
        $this->assertNull($english['en-US'][HilosI18nLanguageLocalesTableRow::enabled]);
        $this->assertNull($english['en-US'][HilosI18nLanguageLocalesTableRow::formats]);
        $this->assertSame(
            LocaleFormats::ofCatalog(BuiltInI18nCatalog::locale('en-US'))->toArray(),
            $english['en-US'][HilosI18nLanguageLocalesTableRow::catalogFormats],
        );
        $this->assertSame('us', $english['en-US'][HilosI18nLanguageLocalesTableRow::countryCode]);

        $german = $this->rows('de');
        $this->assertNull($german['de'][HilosI18nLanguageLocalesTableRow::localeCode]);
        $this->assertNull($german['de-GB'][HilosI18nLanguageLocalesTableRow::catalogFormats]);
        $this->assertSame('de-US', $german['de-US'][HilosI18nLanguageLocalesTableRow::localeCode]);
    }

    /**
     * A country is labelled by its base name in the window's language; a correction of a locale is not a label.
     *
     * @throws HilosException When a name cannot be written or the window read
     */
    public function testARowIsLabelledByTheBaseNameOfItsCountryInTheWindowsLanguage(): void
    {
        Hilos::$db->countryNames->actions->createManual($this->britain, $this->english, $this->britishEnglish, 'Britain');

        $this->assertSame('United Kingdom', $this->rows('en')['en-GB'][HilosI18nLanguageLocalesTableRow::countryName]);
        $this->assertSame('Vereinigtes Königreich', $this->rows('de')['de-GB'][HilosI18nLanguageLocalesTableRow::countryName]);
        $this->assertNull($this->rows('en')['en-US'][HilosI18nLanguageLocalesTableRow::countryName]);
    }

    /** @throws HilosException When an i18n collection cannot be read */
    public function testARowIsFoundByItsKeyAndHeldOnlyByTheWindowOfItsLanguage(): void
    {
        $table = new HilosI18nLanguageLocalesTable();

        $this->assertSame($this->rows('en')['en-GB'], $table->findRow('en-GB')?->toArray());
        $this->assertSame($this->rows('en')['en'], $table->findRow('en')?->toArray());
        $this->assertNull($table->findRow('en-FR'));
        $this->assertNull($table->findRow('xx'));

        $this->assertTrue($table->containsRow('en-US', self::window('en')));
        $this->assertTrue($table->containsRow('en', self::window('en')));
        $this->assertFalse($table->containsRow('en-US', self::window('de')));
        $this->assertFalse($table->containsRow('en-FR', self::window('en')));
    }

    /**
     * A locale created, switched, edited or removed updates the row of its pair in the window of its language only.
     *
     * @throws HilosException When a locale cannot be written or a change built
     */
    public function testALocaleUpdatesTheRowOfItsPairInItsLanguagesWindowOnly(): void
    {
        $american = self::locale($this->english, $this->unitedStates);
        $this->assertSame([['update', 'en-US', 'en-US', false]], $this->mutations('en'));
        $this->assertSame([], $this->mutations('de'));

        $this->changes->taken = [];
        $american->actions->switchOn();
        $this->assertSame([['update', 'en-US', 'en-US', true]], $this->mutations('en'));

        $this->changes->taken = [];
        $american->actions->switchOff();
        $this->assertSame([['update', 'en-US', 'en-US', false]], $this->mutations('en'));

        $this->changes->taken = [];
        $american->actions->update(
            'DD.MM.YYYY', 'HH:mm:ss', '1,000.00', '+XX-XXXX-XXXX',
            'Street, House, City, Index', MeasurementSystem::METRIC, 'und',
        );
        $this->assertSame('DD.MM.YYYY', $this->rows('en')['en-US'][HilosI18nLanguageLocalesTableRow::formats][LocaleFormats::date]);
        $this->assertSame([['update', 'en-US', 'en-US', false]], $this->mutations('en'));

        $this->changes->taken = [];
        $american->actions->delete();
        $this->assertSame([['update', 'en-US', null, null]], $this->mutations('en'));

        $this->changes->taken = [];
        Hilos::$db->locales['en']?->actions->switchOff();
        $this->assertSame([['update', 'en', 'en', false]], $this->mutations('en'));
    }

    /**
     * A new country is a new row in the window of every language, each labelled in its language; a removed one leaves.
     *
     * @throws HilosException When a country or a name cannot be written or a change built
     */
    public function testACountryAddsAndRemovesItsRowInEveryWindow(): void
    {
        $ireland = Hilos::$db->countries->actions->create('ie', '€', 'EUR');
        $this->assertSame([['create', 'en-IE', null, null]], $this->mutations('en'));
        $this->assertSame([['create', 'de-IE', null, null]], $this->mutations('de'));

        $this->changes->taken = [];
        Hilos::$db->countryNames->actions->createManual($ireland, $this->german, null, 'Irland');
        $this->assertSame([], $this->built('en'));
        $built = $this->built('de');
        $this->assertCount(1, $built);
        $this->assertSame('Irland', $built[0]->row?->toArray()[HilosI18nLanguageLocalesTableRow::countryName]);

        $this->changes->taken = [];
        $ireland->actions->update('€', 'EUR', null);
        $this->assertSame([], $this->built('en'));

        $this->changes->taken = [];
        Hilos::$db->countryNames->findBase((int) $ireland->id, (int) $this->german->id)?->actions->delete();
        $built = $this->built('de');
        $this->assertCount(1, $built);
        $this->assertNull($built[0]->row?->toArray()[HilosI18nLanguageLocalesTableRow::countryName]);

        $this->changes->taken = [];
        $ireland->actions->delete();
        $this->assertSame([['delete', 'de-IE', null, null]], $this->mutations('de'));
    }

    /**
     * A name of a country in another language, or a correction of a locale, changes no row.
     *
     * @throws HilosException When a name cannot be written or a change built
     */
    public function testOnlyTheBaseNameInTheWindowsLanguageRelabelsARow(): void
    {
        Hilos::$db->countryNames->actions->createManual($this->britain, $this->english, $this->britishEnglish, 'Britain');
        $this->assertSame([], $this->built('en'));

        $this->changes->taken = [];
        Hilos::$db->countryNames->findBase((int) $this->britain->id, (int) $this->english->id)?->actions->edit('UK');
        $built = $this->built('en');
        $this->assertCount(1, $built);
        $this->assertSame('en-GB', $built[0]->rowKey);
        $this->assertSame('UK', $built[0]->row?->toArray()[HilosI18nLanguageLocalesTableRow::countryName]);
        $this->assertSame([], $this->built('de'));
    }

    /**
     * The removal of the window's language takes every row away, and its return brings them back.
     *
     * @throws HilosException When a language cannot be written or a change built
     */
    public function testRemovingTheLanguageEmptiesItsWindowAndItsReturnRefillsIt(): void
    {
        $spanish = Hilos::$db->languages->actions->create('es', 'Español', false);
        $this->assertSame(
            [['create', 'es', null, null], ['create', 'es-GB', null, null], ['create', 'es-US', null, null]],
            $this->mutations('es'),
        );
        $this->assertSame([], $this->mutations('en'));

        $this->changes->taken = [];
        $spanish->actions->update('Castellano', false);
        $this->assertSame([], $this->built('es'));

        $this->changes->taken = [];
        $spanish->actions->delete();
        $this->assertSame(
            [['delete', 'es', null, null], ['delete', 'es-GB', null, null], ['delete', 'es-US', null, null]],
            $this->mutations('es'),
        );
        $this->assertSame([], $this->mutations('en'));
        $this->assertSame([], $this->mutations('xx'));
    }

    /**
     * Every field of a row comes from a column none of the i18n entities holds personal, or from two such codes,
     * so a viewer of the admin view mode is shown the whole row.
     *
     * @throws HilosException When the window cannot be read
     */
    public function testAViewerOfTheAdminViewModeSeesTheWholeRow(): void
    {
        $table = new HilosI18nLanguageLocalesTable();
        $notPersonal = [
            HilosDbContext::languages => EntityLanguage::_piiNotPersonal,
            HilosDbContext::locales => EntityLocale::_piiNotPersonal,
            HilosDbContext::countries => EntityCountry::_piiNotPersonal,
            HilosDbContext::countryNames => EntityCountryName::_piiNotPersonal,
        ];
        $columnShown = static fn(string $collection, string $field): bool => in_array(
            Hilos::$db->{$collection}->columnForField($field),
            $notPersonal[$collection] ?? [],
            true,
        );

        foreach ($this->rows('en') as $row) {
            $this->assertSame($row, ViewerFields::hide($row, $table->wireFields(), $columnShown));
        }
    }

    /**
     * The rows of a language's window, by row key, in the order the window opens in.
     *
     * @param string $language Code of the language
     * @return array<string, array<string, mixed>> Row payloads by row key, in window order
     * @throws HilosException When the window cannot be read
     */
    private function rows(string $language): array
    {
        $rows = [];
        foreach ((new HilosI18nLanguageLocalesTable())->getPage(self::window($language))->rows as $row) {
            $rows[(string) $row->getRowKey()] = $row->toArray();
        }

        return $rows;
    }

    /**
     * The mutations the changes taken off the bus make in a language's window.
     *
     * @param string $language Code of the language
     * @return list<TableRowMutationDTO> Mutations in the order the changes were announced
     * @throws HilosException When a change cannot be built
     */
    private function built(string $language): array
    {
        $table = new HilosI18nLanguageLocalesTable();
        $mutations = [];
        foreach ($this->changes->taken as $change) {
            array_push($mutations, ...$table->buildMutationsForWindow($change, self::window($language)));
        }

        return $mutations;
    }

    /**
     * The same mutations, each told by its type, row key and what the row says of its locale.
     *
     * @param string $language Code of the language
     * @return list<array{0: string, 1: string, 2: ?string, 3: ?bool}> Type, row key, locale code and its switch
     * @throws HilosException When a change cannot be built
     */
    private function mutations(string $language): array
    {
        return array_map(
            static fn(TableRowMutationDTO $mutation): array => [
                $mutation->type->value,
                (string) $mutation->rowKey,
                $mutation->row?->toArray()[HilosI18nLanguageLocalesTableRow::localeCode],
                $mutation->row?->toArray()[HilosI18nLanguageLocalesTableRow::enabled],
            ],
            $this->built($language),
        );
    }

    /**
     * @param string $language Code of the language
     * @return TableQueryDTO Window opened on the language, in the order the table opens it
     */
    private static function window(string $language): TableQueryDTO
    {
        $table = new HilosI18nLanguageLocalesTable();

        return new TableQueryDTO(
            sort: $table->defaultSort(),
            limit: $table->windowSize(),
            filter: [HilosI18nLanguageLocalesTable::FILTER_LANGUAGE => $language],
        );
    }

    /**
     * @param Language $language Language of the locale
     * @param ?Country $country Country of the locale, or null for the base edition
     * @return Locale New locale, switched off
     * @throws HilosException When the locale cannot be written
     */
    private static function locale(Language $language, ?Country $country): Locale
    {
        return Hilos::$db->locales->actions->create(
            $language, $country, 'YYYY-MM-DD', 'HH:mm:ss', '1,000.00', '+XX-XXXX-XXXX',
            'Street, House, City, Index', MeasurementSystem::METRIC, 'und',
        );
    }

    /** @throws DatabaseException When a stub fails */
    private static function runStubs(bool $down): void
    {
        // external-boundary: the up stub carries no suffix.
        $suffix = $down ? '_down' : '';
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

/** The framework's reference catalogs with no project extension. */
final class I18nLocalesTestDbContext extends HilosDbContext
{
}

/** Keeps the database changes announced since the last reset, as the source bus hands them over. */
final class I18nLocalesChangeLog implements SourceChangeSubscriberInterface
{
    /** @var list<SourceChange> Changes in the order they were announced */
    public array $taken = [];

    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->kind === SourceChange::KIND_DB) {
            $this->taken[] = $change;
        }
    }
}
