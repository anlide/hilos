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
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Country as EntityCountry;
use Hilos\Database\Entity\Item\CountryName as EntityCountryName;
use Hilos\Database\Entity\Item\Language as EntityLanguage;
use Hilos\Database\Entity\Item\LanguageName as EntityLanguageName;
use Hilos\Database\Entity\Item\Locale as EntityLocale;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\Country;
use Hilos\Database\View\Item\Language;
use Hilos\Database\View\Item\Locale;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\MeasurementSystem;
use Hilos\Tables\I18n\AbstractHilosI18nNamesTable;
use Hilos\Tables\I18n\HilosI18nCountryNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguageNamesTable;
use Hilos\Tables\I18n\HilosI18nNameTableRow;

/**
 * The names tables of the i18n section against the live framework schema (HIL-1477).
 *
 * A window is opened on a subject — a language or a country — and holds a row for every language,
 * the subject's own language left out of the language table. A row carries the base name written in
 * its language, or none, and under a base the corrections of the language's locales of a country.
 * The live changes are taken off the source bus exactly as the actions announce them, so the test
 * also holds the tables to the shape the source gives a changed row.
 */
final class HilosI18nNamesTableIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = [
        'hilos_language', 'hilos_country', 'hilos_locale', 'hilos_language_name', 'hilos_country_name',
    ];
    private const string OWNER_AGENT_ID = 'test-agent:i18n-names';

    private ?DbContext $previousDb = null;
    private string|false $previousDefaultLanguage;
    private I18nNamesChangeLog $changes;

    private Language $english;
    private Language $german;
    private Language $french;
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
        $db = new I18nNamesTestDbContext();
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
        $this->french = Hilos::$db->languages->actions->create('fr', 'Français', false);
        $this->britain = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        $this->unitedStates = Hilos::$db->countries->actions->create('us', '$', 'USD');
        self::locale($this->english, null);
        $this->britishEnglish = self::locale($this->english, $this->britain);
        self::locale($this->english, $this->unitedStates);
        self::locale($this->german, $this->britain);
        Hilos::$db->countryNames->actions->createManual($this->britain, $this->english, null, 'United Kingdom');

        $this->changes = new I18nNamesChangeLog();
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

    /** @throws HilosException When fixture writes or table reads fail */
    public function testAnOldCountryFilterDoesNotFollowItsSameCodeReplacement(): void
    {
        $country = Hilos::$db->countries->actions->create('qx', '¤', 'XXX');
        $oldId = $country->id;
        $country->actions->delete();
        $replacement = Hilos::$db->countries->actions->create('qx', '¤', 'XXX');
        $table = new HilosI18nCountryNamesTable();
        $this->assertNotSame($oldId, $replacement->id);
        $this->assertSame([], $table->getPage(self::window($table, $oldId))->rows);
        $this->assertCount(3, $table->getPage(self::window($table, $replacement->id))->rows);
        $this->assertSame([], $table->getPage(new TableQueryDTO(filter: [
            HilosI18nCountryNamesTable::FILTER_COUNTRY => 'qx',
        ]))->rows);
    }

    /** @throws HilosException When the window cannot be read */
    public function testTheWindowOfALanguageHoldsEveryOtherLanguageByCode(): void
    {
        $this->assertSame([1, 3], array_keys($this->rows(new HilosI18nLanguageNamesTable(), 2)));
        $this->assertSame([2, 3], array_keys($this->rows(new HilosI18nLanguageNamesTable(), 1)));
        $this->assertSame([2, 1, 3], array_keys($this->rows(new HilosI18nCountryNamesTable(), 1)));
    }

    /** @throws HilosException When the window cannot be read */
    public function testAWindowWithoutASubjectOrWithAnUnknownOneIsEmpty(): void
    {
        $table = new HilosI18nLanguageNamesTable();

        $this->assertSame([], $table->getPage(new TableQueryDTO())->rows);
        $this->assertSame([], $this->rows($table, 999999));
        $this->assertSame([], $this->rows(new HilosI18nCountryNamesTable(), 999999));
    }

    /** @throws HilosException When a name cannot be written or the window read */
    public function testARowCarriesItsBaseNameNoneOrTheEmptyOneAsWritten(): void
    {
        Hilos::$db->languageNames->actions->createManual($this->german, $this->english, null, 'German');
        Hilos::$db->languageNames->actions->createManual($this->german, $this->french, null, '');

        $rows = $this->rows(new HilosI18nLanguageNamesTable(), 2);

        $this->assertSame('German', $rows[1][HilosI18nNameTableRow::name]);
        $this->assertSame('', $rows[3][HilosI18nNameTableRow::name]);
        $this->assertSame('English', $rows[1][HilosI18nNameTableRow::nativeName]);
        $this->assertNull($this->rows(new HilosI18nLanguageNamesTable(), 3)[1][HilosI18nNameTableRow::name]);
    }

    /**
     * A correction stands only under a base, one per locale of a country, labelled by the country's
     * name in the default language or left to its code.
     *
     * @throws HilosException When a name cannot be written or the window read
     */
    public function testCorrectionsStandUnderABaseForTheLocalesOfACountryOnly(): void
    {
        $this->assertSame([], $this->rows(new HilosI18nLanguageNamesTable(), 2)[1][HilosI18nNameTableRow::corrections]);

        Hilos::$db->languageNames->actions->createManual($this->german, $this->english, null, 'German');
        $correction = Hilos::$db->languageNames->actions->createManual(
            $this->german, $this->english, $this->britishEnglish, 'German (UK)',
        );

        $this->assertSame([
            [
                HilosI18nNameTableRow::localeId => 2,
                HilosI18nNameTableRow::countryId => 1,
                HilosI18nNameTableRow::nameId => $correction->id,
                HilosI18nNameTableRow::localeCode => 'en-GB',
                HilosI18nNameTableRow::countryCode => 'gb',
                HilosI18nNameTableRow::countryName => 'United Kingdom',
                HilosI18nNameTableRow::name => 'German (UK)',
            ],
            [
                HilosI18nNameTableRow::localeId => 3,
                HilosI18nNameTableRow::countryId => 2,
                HilosI18nNameTableRow::nameId => null,
                HilosI18nNameTableRow::localeCode => 'en-US',
                HilosI18nNameTableRow::countryCode => 'us',
                HilosI18nNameTableRow::countryName => null,
                HilosI18nNameTableRow::name => null,
            ],
        ], $this->rows(new HilosI18nLanguageNamesTable(), 2)[1][HilosI18nNameTableRow::corrections]);
    }

    /** @throws HilosException When a name cannot be written or the window read */
    public function testTheCountryTableReadsTheNamesOfItsCountry(): void
    {
        Hilos::$db->countryNames->actions->createManual($this->britain, $this->english, $this->britishEnglish, 'Britain');

        $rows = $this->rows(new HilosI18nCountryNamesTable(), 1);

        $this->assertSame('United Kingdom', $rows[1][HilosI18nNameTableRow::name]);
        $this->assertSame('Britain', $rows[1][HilosI18nNameTableRow::corrections][0][HilosI18nNameTableRow::name]);
        $this->assertNull($rows[2][HilosI18nNameTableRow::name]);
        $this->assertSame([], $rows[2][HilosI18nNameTableRow::corrections]);
        $this->assertNull($this->rows(new HilosI18nCountryNamesTable(), 2)[1][HilosI18nNameTableRow::name]);
    }

    /** @throws HilosException When a name cannot be written or its window rebuilt */
    public function testRecreatedNamesPublishNewIdsWithTheSameTextInOnlyTheirSubjectWindow(): void
    {
        foreach ([new HilosI18nLanguageNamesTable(), new HilosI18nCountryNamesTable()] as $table) {
            $isLanguage = $table instanceof HilosI18nLanguageNamesTable;
            $base = $isLanguage
                ? Hilos::$db->languageNames->actions->createManual($this->german, $this->english, null, 'Same')
                : Hilos::$db->countryNames->actions->createManual($this->unitedStates, $this->french, null, 'Same');
            $writingId = $isLanguage ? 1 : 3;
            $oldId = $base->id;
            self::assertSame($oldId, $this->rows($table, 2)[$writingId][HilosI18nNameTableRow::nameId]);
            $this->changes->taken = [];
            $base->actions->delete();
            $removed = $this->built($table, 2);
            self::assertCount(1, $removed);
            self::assertNull($removed[0]->row->toArray()[HilosI18nNameTableRow::nameId]);
            self::assertNull($removed[0]->row->toArray()[HilosI18nNameTableRow::name]);
            $this->changes->taken = [];
            $replacement = $isLanguage
                ? Hilos::$db->languageNames->actions->createManual($this->german, $this->english, null, 'Same')
                : Hilos::$db->countryNames->actions->createManual($this->unitedStates, $this->french, null, 'Same');
            $created = $this->built($table, 2);
            self::assertCount(1, $created);
            self::assertNotSame($oldId, $replacement->id);
            self::assertSame($replacement->id, $created[0]->row->toArray()[HilosI18nNameTableRow::nameId]);
            self::assertSame('Same', $created[0]->row->toArray()[HilosI18nNameTableRow::name]);
            self::assertSame((string) $writingId, $created[0]->row->getRowKey());
            self::assertSame([], $this->built($table, $isLanguage ? 3 : 1));
        }
    }

    /**
     * One name updates the row of the language it is written in, in the window of its subject and in
     * no other window.
     *
     * @throws HilosException When a name cannot be written or a change built
     */
    public function testANameAndACorrectionUpdateTheRowOfTheirLanguageInTheirSubjectsWindowOnly(): void
    {
        $base = Hilos::$db->languageNames->actions->createManual($this->german, $this->english, null, 'German');
        $this->assertSame([['update', '1', 'German']], $this->mutations(new HilosI18nLanguageNamesTable(), 2));
        $this->assertSame([], $this->mutations(new HilosI18nLanguageNamesTable(), 3));

        $this->changes->taken = [];
        $base->actions->edit('Germanic');
        $this->assertSame([['update', '1', 'Germanic']], $this->mutations(new HilosI18nLanguageNamesTable(), 2));

        $this->changes->taken = [];
        $override = Hilos::$db->languageNames->actions->createManual($this->german, $this->english, $this->britishEnglish, 'UK');
        $mutations = $this->built(new HilosI18nLanguageNamesTable(), 2);
        $this->assertCount(1, $mutations);
        $this->assertSame('UK', $mutations[0]->row?->toArray()[HilosI18nNameTableRow::corrections][0][HilosI18nNameTableRow::name]);

        $this->changes->taken = [];
        $override->actions->delete();
        $mutations = $this->built(new HilosI18nLanguageNamesTable(), 2);
        $this->assertCount(1, $mutations);
        $this->assertNull($mutations[0]->row?->toArray()[HilosI18nNameTableRow::corrections][0][HilosI18nNameTableRow::name]);

        $this->changes->taken = [];
        $base->actions->delete();
        $this->assertSame([['update', '1', null]], $this->mutations(new HilosI18nLanguageNamesTable(), 2));
    }

    /** @throws HilosException When a language cannot be written or a change built */
    public function testALanguageAddsUpdatesAndRemovesItsRow(): void
    {
        $spanish = Hilos::$db->languages->actions->create('es', 'Español', false);
        $this->assertSame([['create', '4', null]], $this->mutations(new HilosI18nLanguageNamesTable(), 2));
        $this->assertSame([], $this->mutations(new HilosI18nLanguageNamesTable(), 4));

        $this->changes->taken = [];
        $spanish->actions->update('Castellano', false);
        $built = $this->built(new HilosI18nCountryNamesTable(), 1);
        $this->assertCount(1, $built);
        $this->assertSame(TableMutationType::Update, $built[0]->type);
        $this->assertSame('Castellano', $built[0]->row?->toArray()[HilosI18nNameTableRow::nativeName]);

        $this->changes->taken = [];
        $spanish->actions->delete();
        $this->assertSame([['delete', '4', null]], $this->mutations(new HilosI18nLanguageNamesTable(), 2));
    }

    /** @throws HilosException When a locale cannot be written or a change built */
    public function testALocaleUpdatesTheRowOfItsLanguage(): void
    {
        Hilos::$db->languageNames->actions->createManual($this->german, $this->french, null, 'allemand');
        $this->changes->taken = [];
        $american = self::locale($this->french, $this->unitedStates);

        $built = $this->built(new HilosI18nLanguageNamesTable(), 2);
        $this->assertSame(['3'], array_map(static fn(TableRowMutationDTO $mutation): string => (string) $mutation->rowKey, $built));
        $this->assertSame(
            'fr-US',
            $built[0]->row?->toArray()[HilosI18nNameTableRow::corrections][0][HilosI18nNameTableRow::localeCode],
        );

        $this->changes->taken = [];
        $american->actions->delete();
        $built = $this->built(new HilosI18nLanguageNamesTable(), 2);
        $this->assertCount(1, $built);
        $this->assertSame([], $built[0]->row?->toArray()[HilosI18nNameTableRow::corrections]);
    }

    /**
     * A country renamed in the default language relabels the row of every language with a locale of it.
     *
     * @throws HilosException When a name cannot be written or a change built
     */
    public function testACountryRenamedInTheDefaultLanguageRelabelsEveryRowWithALocaleOfIt(): void
    {
        Hilos::$db->languageNames->actions->createManual($this->french, $this->english, null, 'French');
        Hilos::$db->languageNames->actions->createManual($this->french, $this->german, null, 'Französisch');
        $this->changes->taken = [];

        Hilos::$db->countryNames->findBase((int) $this->britain->id, (int) $this->english->id)?->actions->edit('UK');

        $built = $this->built(new HilosI18nLanguageNamesTable(), 3);
        $this->assertSame(['1', '2'], array_map(static fn(TableRowMutationDTO $mutation): string => (string) $mutation->rowKey, $built));
        foreach ($built as $mutation) {
            $this->assertSame(
                'UK',
                $mutation->row?->toArray()[HilosI18nNameTableRow::corrections][0][HilosI18nNameTableRow::countryName],
            );
        }

        $this->changes->taken = [];
        Hilos::$db->countryNames->actions->createManual($this->britain, $this->german, null, 'Großbritannien');
        $this->assertSame([], $this->mutations(new HilosI18nLanguageNamesTable(), 3));
        $this->assertSame([['update', '2', 'Großbritannien']], $this->mutations(new HilosI18nCountryNamesTable(), 1));
    }

    /**
     * A deleted default-language name still identifies every correction whose country label disappeared.
     *
     * @throws HilosException When a name cannot be written or a change built
     */
    public function testDeletingACountryBaseNameRelabelsEveryLanguageWithALocaleOfIt(): void
    {
        Hilos::$db->languageNames->actions->createManual($this->french, $this->english, null, 'French');
        Hilos::$db->languageNames->actions->createManual($this->french, $this->german, null, 'Französisch');
        $base = Hilos::$db->countryNames->findBase((int) $this->britain->id, (int) $this->english->id);
        $this->assertNotNull($base);
        $nameId = $base->id;
        $this->changes->taken = [];

        $base->actions->delete();

        $this->assertArrayNotHasKey($nameId, Hilos::$db->countryNames);
        $built = $this->built(new HilosI18nLanguageNamesTable(), 3);
        $this->assertSame(['1', '2'], array_map(static fn(TableRowMutationDTO $mutation): string => (string) $mutation->rowKey, $built));
        foreach ($built as $mutation) {
            $this->assertSame(TableMutationType::Update, $mutation->type);
            $this->assertNull($mutation->row?->toArray()[HilosI18nNameTableRow::corrections][0][HilosI18nNameTableRow::countryName]);
            $this->assertSame(
                $this->britain->id,
                $mutation->row?->toArray()[HilosI18nNameTableRow::corrections][0][HilosI18nNameTableRow::countryId],
            );
        }
        $this->assertSame(
            [['update', '1', null], ['update', '2', null]],
            $this->mutations(new HilosI18nCountryNamesTable(), 1),
        );
    }

    /** @throws HilosException When the subject cannot be removed or a change built */
    public function testRemovingTheSubjectEmptiesItsWindow(): void
    {
        $customCountry = Hilos::$db->countries->actions->create('qx', '¤', 'XQX');
        $this->changes->taken = [];
        $customCountry->actions->delete();

        $this->assertSame(
            [['delete', '2', null], ['delete', '1', null], ['delete', '3', null]],
            $this->mutations(new HilosI18nCountryNamesTable(), $customCountry->id),
        );
        $this->assertSame([], $this->mutations(new HilosI18nCountryNamesTable(), 1));
    }

    /**
     * Every field of a row comes from a column none of the i18n entities holds personal, so a viewer of
     * the admin view mode is shown the whole row.
     *
     * @throws HilosException When a name cannot be written or the window read
     */
    public function testAViewerOfTheAdminViewModeSeesTheWholeRow(): void
    {
        Hilos::$db->languageNames->actions->createManual($this->german, $this->english, null, 'German');
        Hilos::$db->languageNames->actions->createManual($this->german, $this->english, $this->britishEnglish, 'UK');
        $notPersonal = [
            HilosDbContext::languages => EntityLanguage::_piiNotPersonal,
            HilosDbContext::locales => EntityLocale::_piiNotPersonal,
            HilosDbContext::countries => EntityCountry::_piiNotPersonal,
            HilosDbContext::languageNames => EntityLanguageName::_piiNotPersonal,
            HilosDbContext::countryNames => EntityCountryName::_piiNotPersonal,
        ];
        $columnShown = static fn(string $collection, string $field): bool => in_array(
            Hilos::$db->{$collection}->columnForField($field),
            $notPersonal[$collection] ?? [],
            true,
        );

        foreach ([[new HilosI18nLanguageNamesTable(), 2], [new HilosI18nCountryNamesTable(), 1]] as [$table, $subject]) {
            foreach ($this->rows($table, $subject) as $row) {
                $this->assertSame($row, ViewerFields::hide($row, $table->wireFields(), $columnShown));
            }
        }
    }

    /**
     * The rows of a subject's window, by row key.
     *
     * @param AbstractHilosI18nNamesTable $table Table the window is on
     * @param int $subject Code of the subject
     * @return array<string, array<string, mixed>> Row payloads by row key, in window order
     * @throws HilosException When the window cannot be read
     */
    private function rows(AbstractHilosI18nNamesTable $table, int $subject): array
    {
        $rows = [];
        foreach ($table->getPage(self::window($table, $subject))->rows as $row) {
            $rows[(string) $row->getRowKey()] = $row->toArray();
        }

        return $rows;
    }

    /**
     * The mutations the changes taken off the bus make in a subject's window.
     *
     * @param AbstractHilosI18nNamesTable $table Table the window is on
     * @param int $subject Code of the subject
     * @return list<TableRowMutationDTO> Mutations in the order the changes were announced
     * @throws HilosException When a change cannot be built
     */
    private function built(AbstractHilosI18nNamesTable $table, int $subject): array
    {
        $mutations = [];
        foreach ($this->changes->taken as $change) {
            array_push($mutations, ...$table->buildMutationsForWindow($change, self::window($table, $subject)));
        }

        return $mutations;
    }

    /**
     * The same mutations, each told by its type, row key and base name.
     *
     * @param AbstractHilosI18nNamesTable $table Table the window is on
     * @param int $subject Code of the subject
     * @return list<array{0: string, 1: string, 2: ?string}> Type, row key and the base name the row carries
     * @throws HilosException When a change cannot be built
     */
    private function mutations(AbstractHilosI18nNamesTable $table, int $subject): array
    {
        return array_map(
            static fn(TableRowMutationDTO $mutation): array => [
                $mutation->type->value,
                (string) $mutation->rowKey,
                $mutation->row?->toArray()[HilosI18nNameTableRow::name],
            ],
            $this->built($table, $subject),
        );
    }

    /**
     * @param AbstractHilosI18nNamesTable $table Table the window is on
     * @param int $subject Code of the subject
     * @return TableQueryDTO Window opened on the subject
     */
    private static function window(AbstractHilosI18nNamesTable $table, int $subject): TableQueryDTO
    {
        $key = $table instanceof HilosI18nLanguageNamesTable
            ? HilosI18nLanguageNamesTable::FILTER_LANGUAGE
            : HilosI18nCountryNamesTable::FILTER_COUNTRY;

        return new TableQueryDTO(limit: $table->windowSize(), filter: [$key => $subject]);
    }

    /**
     * @param Language $language Language of the locale
     * @param ?Country $country Country of the locale, or null for the base edition
     * @return Locale New locale
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
final class I18nNamesTestDbContext extends HilosDbContext
{
}

/** Keeps the database changes announced since the last reset, as the source bus hands them over. */
final class I18nNamesChangeLog implements SourceChangeSubscriberInterface
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
