<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Backup\Anonymization\AnonymizationCoverageValidator;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Country as EntityCountry;
use Hilos\Database\Entity\Item\CountryName as EntityCountryName;
use Hilos\Database\Entity\Item\I18nReflow as EntityI18nReflow;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\Country;
use Hilos\Database\View\Item\Language;
use Hilos\Database\View\Item\Locale;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\I18nTestCommandConstants;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\MeasurementSystem;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;

/**
 * The reflow's write doors against the live framework schema (HIL-1472): what each takes in
 * from the built-in catalog, what it leaves alone, and the one-row record of the fingerprint.
 */
final class I18nCatalogTakeInIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = [
        'hilos_language', 'hilos_country', 'hilos_locale', 'hilos_language_name', 'hilos_country_name',
        'hilos_i18n_reflow',
    ];
    private const string OWNER_AGENT_ID = 'test-agent:i18n-library';
    private const int CATALOG_COUNTRIES = 51;

    private ?DbContext $previousDb = null;
    private string|false $previousDefaultLanguage;
    private I18nTakeInChangeRecorder $changes;

    /**
     * @throws DatabaseException When a migration stub or schema refresh fails
     * @throws HilosException When the context cannot be configured
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDefaultLanguage = getenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        // These cases exercise ordinary reference rows, not the configured default.
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=es');
        self::runStubs(down: true);
        self::runStubs(down: false);
        Schema::reset();
        Schema::initialize();

        $this->previousDb = Hilos::$db;
        $db = new I18nCatalogTakeInTestDbContext();
        $db->configure();
        Hilos::$db = $db;

        foreach ([
            HilosDbContext::languages,
            HilosDbContext::countries,
            HilosDbContext::locales,
            HilosDbContext::languageNames,
            HilosDbContext::countryNames,
            HilosDbContext::i18nReflows,
        ] as $collection) {
            TruthSourceRegistry::register($collection, TruthSourceKeys::all(), self::OWNER_AGENT_ID);
        }
        ExecutionContext::setCurrentAgentId(self::OWNER_AGENT_ID);
        SourceChangeBus::reset();
        $this->changes = new I18nTakeInChangeRecorder();
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

    /** @throws HilosException When default language provision or name cleanup fails */
    public function testStartupImportsNamesOnlyForALanguageCreatedOnThatStart(): void
    {
        Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        Hilos::$db->i18nReflows->actions->record(BuiltInI18nCatalog::fingerprint());
        $agent = new I18nLibraryAgent();

        $agent->onStart();

        $this->assertArrayHasKey('es', Hilos::$db->languages);
        $this->assertTrue(Hilos::$db->languages['es']->enabled);
        $this->assertArrayHasKey('es', Hilos::$db->locales);
        $this->assertTrue(Hilos::$db->locales['es']->enabled);
        $base = Hilos::$db->countryNames->findBase(Hilos::$db->countries['gb']->id, Hilos::$db->languages['es']->id);
        $this->assertNotNull($base);
        $this->assertSame(BuiltInI18nCatalog::countryName('gb', 'es'), $base->name);
        $base->actions->delete();
        $before = $this->changes->count;

        $agent->onStart();

        $this->assertSame($before, $this->changes->count, 'An existing default does not import names again');
        $this->assertNull(Hilos::$db->countryNames->findBase(Hilos::$db->countries['gb']->id, Hilos::$db->languages['es']->id));
    }

    /** @throws HilosException When fixture preparation or cleanup fails */
    public function testCountryNamesCleanupContinuesPastMissingCountryLanguageAndLocale(): void
    {
        Hilos::$db->countries->actions->takeFromCatalog();
        $this->assertTrue($this->command(CliCommands::I18N_TEST_COUNTRY_NAMES_SEED)->isOk());
        $this->assertArrayNotHasKey('en', Hilos::$db->languages);
        Hilos::$db->countryNames->findBase(Hilos::$db->countries['qj']->id, Hilos::$db->languages['qk']->id)->actions->delete();
        Hilos::$db->countries['qj']->actions->delete();
        Hilos::$db->locales['qk-GB']->actions->delete();

        $this->assertTrue($this->command(CliCommands::I18N_TEST_COUNTRY_NAMES_CLEAR)->isOk());

        $this->assertArrayNotHasKey('qj', Hilos::$db->countries);
        $this->assertArrayNotHasKey('qk', Hilos::$db->languages);
        foreach (['qk-GB', 'qk-US', 'qk-FR'] as $code) {
            $this->assertArrayNotHasKey($code, Hilos::$db->locales);
        }
        $this->assertArrayHasKey('gb', Hilos::$db->countries);
        $before = $this->changes->count;
        $this->assertTrue($this->command(CliCommands::I18N_TEST_COUNTRY_NAMES_CLEAR)->isOk());
        $this->assertSame($before, $this->changes->count, 'Repeated cleanup writes nothing');
    }

    /** @throws HilosException When fixture preparation or cleanup fails */
    public function testLanguageNamesCleanupStillSwitchesOffAndDeletesTheRemainingLocale(): void
    {
        Hilos::$db->countries->actions->takeFromCatalog();
        $this->assertTrue($this->command(CliCommands::I18N_TEST_LANGUAGE_NAMES_SEED)->isOk());
        Hilos::$db->languageNames->findBase(Hilos::$db->languages['qv']->id, Hilos::$db->languages['qw']->id)->actions->delete();
        Hilos::$db->locales['qv-GB']->actions->delete();
        Hilos::$db->languages['qv']->actions->delete();
        Hilos::$db->locales['qw-US']->actions->delete();
        Hilos::$db->locales['qw-GB']->actions->switchOn();

        $this->assertTrue($this->command(CliCommands::I18N_TEST_LANGUAGE_NAMES_CLEAR)->isOk());

        $this->assertArrayNotHasKey('qv', Hilos::$db->languages);
        $this->assertArrayNotHasKey('qw', Hilos::$db->languages);
        foreach (['qv-GB', 'qw-GB', 'qw-US'] as $code) {
            $this->assertArrayNotHasKey($code, Hilos::$db->locales);
        }
        $this->assertArrayHasKey('gb', Hilos::$db->countries);
        $before = $this->changes->count;
        $this->assertTrue($this->command(CliCommands::I18N_TEST_LANGUAGE_NAMES_CLEAR)->isOk());
        $this->assertSame($before, $this->changes->count, 'Repeated cleanup writes nothing');
    }

    /** @throws HilosException When the command cannot be answered */
    public function testLocaleOnAnswersMissingLanguageWithTheExistingCommandError(): void
    {
        $reply = $this->command(CliCommands::I18N_TEST_LOCALE_ON, 'en');

        $this->assertFalse($reply->isOk());
        $this->assertSame("Language 'en' does not exist", $reply->payload[CommandConstants::FIELD_MESSAGE]);
        $this->assertArrayNotHasKey('en', Hilos::$db->languages);
        $this->assertArrayNotHasKey('en', Hilos::$db->locales);
        $this->assertSame(0, $this->changes->count);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testCountriesArriveSwitchedOffAndOnlySwitchedOffOnesAreRefreshed(): void
    {
        $german = Hilos::$db->languages->actions->create('de', 'Deutsch', false);
        $germany = Hilos::$db->countries->actions->create('de', '$', 'USD');
        $germanyLocale = self::createLocale($german, $germany);
        $germany->actions->update('$', 'USD', $germanyLocale->id);
        $france = Hilos::$db->countries->actions->create('fr', '$', 'USD');
        $france->actions->switchOn();
        $own = Hilos::$db->countries->actions->create('zz', 'Z', 'ZZZ');

        Hilos::$db->countries->actions->takeFromCatalog();

        $this->assertSame(self::CATALOG_COUNTRIES + 1, EntityCountry::count());
        foreach (BuiltInI18nCatalog::countries() as $definition) {
            $country = EntityCountry::get([EntityCountry::code => $definition->code])->first();
            $this->assertNotNull($country, $definition->code);
            if ($definition->code === 'fr') {
                continue;
            }
            $this->assertSame($definition->currencySymbol, $country->currency_symbol, $definition->code);
            $this->assertSame($definition->currencyCode, $country->currency_code, $definition->code);
            if ($definition->code !== 'de') {
                $this->assertFalse($country->enabled, $definition->code);
                $this->assertNull($country->default_locale_id, $definition->code);
            }
        }
        $us = Hilos::$db->countries['us'];
        $this->assertSame('$', $us->currencySymbol);
        $this->assertSame('USD', $us->currencyCode);
        $this->assertSame($germanyLocale->id, Hilos::$db->countries['de']->defaultLocaleId);
        $this->assertSame('€', Hilos::$db->countries['de']->currencySymbol);
        $this->assertTrue(Hilos::$db->countries['fr']->enabled);
        $this->assertSame('$', Hilos::$db->countries['fr']->currencySymbol);
        $this->assertSame('USD', Hilos::$db->countries['fr']->currencyCode);
        $this->assertSame('Z', Hilos::$db->countries[$own->id]->currencySymbol);
        $this->assertSame('ZZZ', Hilos::$db->countries[$own->id]->currencyCode);

        $before = $this->changes->count;
        Hilos::$db->countries->actions->takeFromCatalog();
        $this->assertSame($before, $this->changes->count, 'A second take-in writes nothing');
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testLanguagesAndLocalesAreRefreshedButNeverCreated(): void
    {
        $english = Hilos::$db->languages->actions->create('en', 'Inglés', true);
        $arabic = Hilos::$db->languages->actions->create('ar', 'Arabic', false);
        $arabic->actions->switchOn();
        $own = Hilos::$db->languages->actions->create('xx', 'Own', true);
        $britain = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        $englishLocale = self::createLocale($english, null);
        $britishLocale = self::createLocale($english, $britain);
        $britishLocale->actions->switchOn();
        $ownLocale = self::createLocale($own, null);

        Hilos::$db->languages->actions->refreshFromCatalog();
        Hilos::$db->locales->actions->refreshFromCatalog();

        $this->assertSame('English', Hilos::$db->languages['en']->nativeName);
        $this->assertFalse(Hilos::$db->languages['en']->rtl);
        $this->assertSame('Arabic', Hilos::$db->languages['ar']->nativeName);
        $this->assertFalse(Hilos::$db->languages['ar']->rtl);
        $this->assertSame('Own', Hilos::$db->languages[$own->id]->nativeName);
        $this->assertArrayNotHasKey('fr', Hilos::$db->languages);
        $this->assertCount(3, iterator_to_array(Hilos::$db->languages));

        $catalogEnglish = BuiltInI18nCatalog::locale('en');
        $this->assertNotNull($catalogEnglish);
        $refreshed = Hilos::$db->locales[$englishLocale->id];
        $this->assertSame($catalogEnglish->dateFormat, $refreshed->dateFormat);
        $this->assertSame($catalogEnglish->timeFormat, $refreshed->timeFormat);
        $this->assertSame($catalogEnglish->numberFormat, $refreshed->numberFormat);
        $this->assertSame($catalogEnglish->phoneFormat, $refreshed->phoneFormat);
        $this->assertSame($catalogEnglish->addressFormat, $refreshed->addressFormat);
        $this->assertSame($catalogEnglish->measurementSystem, $refreshed->measurementSystem->value);
        $this->assertSame($catalogEnglish->collation, $refreshed->collation);
        $this->assertSame('YYYY-MM-DD', Hilos::$db->locales['en-GB']->dateFormat);
        $this->assertSame('YYYY-MM-DD', Hilos::$db->locales[$ownLocale->id]->dateFormat);
        $this->assertArrayNotHasKey('en-US', Hilos::$db->locales);
        $this->assertArrayNotHasKey('ar', Hilos::$db->locales);

        $before = $this->changes->count;
        Hilos::$db->languages->actions->refreshFromCatalog();
        Hilos::$db->locales->actions->refreshFromCatalog();
        $this->assertSame($before, $this->changes->count, 'A second refresh writes nothing');
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testNamesAreTakenInOnlyWhereUnlockedAndNeverInOverrides(): void
    {
        $english = Hilos::$db->languages->actions->create('en', 'English', false);
        $own = Hilos::$db->languages->actions->create('xx', 'Own', false);
        Hilos::$db->countries->actions->takeFromCatalog();
        $ownCountry = Hilos::$db->countries->actions->create('zz', 'Z', 'ZZZ');
        $this->assertArrayHasKey('gb', Hilos::$db->countries);
        $this->assertArrayHasKey('de', Hilos::$db->countries);
        $britain = Hilos::$db->countries['gb'];
        $germany = Hilos::$db->countries['de'];
        $stale = Hilos::$db->countryNames->actions->createCatalogBase($britain, $english, 'Britain');
        $locked = Hilos::$db->countryNames->actions->createManual($germany, $english, null, 'Deutschland');
        $override = Hilos::$db->countryNames->actions->createManual(
            $britain,
            $english,
            self::createLocale($english, $britain),
            'UK',
        );

        Hilos::$db->countryNames->actions->takeAllFromCatalog($english);
        Hilos::$db->countryNames->actions->takeAllFromCatalog($own);
        Hilos::$db->countryNames->actions->takeFromCatalog($ownCountry, $english);

        foreach (BuiltInI18nCatalog::countries() as $definition) {
            $this->assertArrayHasKey($definition->code, Hilos::$db->countries, $definition->code);
            $country = Hilos::$db->countries[$definition->code];
            $base = Hilos::$db->countryNames->findBase((int)$country->id, (int)$english->id);
            $this->assertNotNull($base, $definition->code);
            if ($definition->code === 'de') {
                continue;
            }
            $this->assertSame(BuiltInI18nCatalog::countryName($definition->code, 'en'), $base->name, $definition->code);
            $this->assertFalse($base->locked, $definition->code);
        }
        $this->assertSame($stale->id, Hilos::$db->countryNames->findBase((int)$britain->id, (int)$english->id)?->id);
        $this->assertSame('United Kingdom', Hilos::$db->countryNames[$stale->id]->name);
        $this->assertSame('Deutschland', Hilos::$db->countryNames[$locked->id]->name);
        $this->assertTrue(Hilos::$db->countryNames[$locked->id]->locked);
        $this->assertSame('UK', Hilos::$db->countryNames[$override->id]->name);
        $this->assertSame(0, EntityCountryName::count([EntityCountryName::language_id => $own->id]));
        $this->assertSame(0, EntityCountryName::count([EntityCountryName::country_id => $ownCountry->id]));
        $this->assertSame(self::CATALOG_COUNTRIES + 1, EntityCountryName::count());

        $before = $this->changes->count;
        Hilos::$db->countryNames->actions->takeAllFromCatalog($english);
        $this->assertSame($before, $this->changes->count, 'A second take-in writes nothing');
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testTheRecordKeepsOneRowAndRefusesAnythingButAFingerprint(): void
    {
        $this->assertNull(Hilos::$db->i18nReflows->recordedFingerprint());

        Hilos::$db->i18nReflows->actions->record(str_repeat('0', 64));
        Hilos::$db->i18nReflows->actions->record(BuiltInI18nCatalog::fingerprint());

        $this->assertSame(BuiltInI18nCatalog::fingerprint(), Hilos::$db->i18nReflows->recordedFingerprint());
        $this->assertSame(1, EntityI18nReflow::count());
        $this->assertSame(
            BuiltInI18nCatalog::fingerprint(),
            EntityI18nReflow::get([EntityI18nReflow::id => EntityI18nReflow::ROW_ID])->first()?->fingerprint,
        );

        foreach (['', str_repeat('A', 64), str_repeat('0', 63), str_repeat('0', 65), str_repeat('g', 64)] as $fingerprint) {
            try {
                Hilos::$db->i18nReflows->actions->record($fingerprint);
                $this->fail("Expected fingerprint '{$fingerprint}' to be refused");
            } catch (ValidationException) {
                $this->assertSame(BuiltInI18nCatalog::fingerprint(), Hilos::$db->i18nReflows->recordedFingerprint());
            }
        }

        try {
            Database::sqlRun(
                'INSERT INTO `' . EntityI18nReflow::_table . '` (`id`, `fingerprint`) VALUES (2, ?)',
                [str_repeat('1', 64)],
            );
            $this->fail('The table must refuse a second row');
        } catch (DatabaseException) {
            $this->assertSame(1, EntityI18nReflow::count());
        }
    }

    /** @throws HilosException When a PII verdict or live schema read fails */
    public function testEveryRecordColumnHasANonpersonalVerdict(): void
    {
        $registry = PiiRegistry::collect();
        $index = DatabaseConnectionDefaults::PRIMARY_INDEX;
        $schemas = LiveSchemaReader::read($index);
        AnonymizationCoverageValidator::validateLiveSchema($registry, [
            $index => [EntityI18nReflow::_table => $schemas[EntityI18nReflow::_table]],
        ]);
        $this->assertSame([], $registry->strategiesFor($index, EntityI18nReflow::_table));
        $this->assertEqualsCanonicalizing(
            [EntityI18nReflow::id, EntityI18nReflow::fingerprint],
            $registry->notPersonalColumns($index, EntityI18nReflow::_table),
        );
    }

    /**
     * @param string $command Library fixture command
     * @param ?string $code Optional fixture code
     * @return CommandReplyDTO The command's single correlated reply
     * @throws HilosException When the command cannot be answered
     */
    private function command(string $command, ?string $code = null): CommandReplyDTO
    {
        $previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        Hilos::$sr->dbSyncBroadcastEnabled = false;
        try {
            new I18nLibraryAgent()->onSignalCommand(
                new CommandRequestDTO('i18n-optional-read', $command, $code === null ? [] : [I18nTestCommandConstants::FIELD_CODE => $code]),
                'cli',
                $command,
            );
            $signal = Hilos::$sr->getNextQueuedSignal();
            $this->assertNotNull($signal);
            $this->assertSame(SignalTypeConstants::COMMAND_REPLY, $signal->signalType->getType());
            $this->assertInstanceOf(CommandReplyDTO::class, $signal->data);
            $this->assertSame('i18n-optional-read', $signal->data->correlationId);
            $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'A fixture command replies exactly once');

            return $signal->data;
        } finally {
            Hilos::$sr = $previousRouter;
        }
    }

    /**
     * The formats are test data that differ from every catalog locale.
     *
     * @param Language $language Locale's language
     * @param ?Country $country Its country, or null for a countryless locale
     * @return Locale New locale
     * @throws HilosException When the locale cannot be created
     */
    private static function createLocale(Language $language, ?Country $country): Locale
    {
        return Hilos::$db->locales->actions->create(
            $language, $country, 'YYYY-MM-DD', 'HH:mm:ss', '1,000.00', '+XX-XXXX-XXXX',
            'Street, House, City, Index', MeasurementSystem::IMPERIAL, 'und',
        );
    }

    /**
     * @param bool $down Drop tables when true, create them when false
     * @throws DatabaseException When a stub statement fails
     */
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

/** The framework's reference catalogs and the reflow record with no project extension. */
final class I18nCatalogTakeInTestDbContext extends HilosDbContext
{
}

/** Counts announcements to prove a take-in of an unchanged catalog writes nothing. */
final class I18nTakeInChangeRecorder implements SourceChangeSubscriberInterface
{
    public int $count = 0;

    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->count++;
    }
}
