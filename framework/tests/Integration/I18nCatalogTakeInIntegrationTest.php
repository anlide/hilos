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
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
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
use Hilos\I18n\LocaleTemplates;
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

    public function testCreateLanguageImportsCatalogNamesAndReturnsItsPrimaryId(): void
    {
        Hilos::$db->countries->actions->takeFromCatalog();
        $before = $this->changes->count;

        $reply = $this->command(CliCommands::I18N_TEST_LANGUAGE_CREATE, ['code' => 'fr', 'nativeName' => 'Fixture français']);

        self::assertTrue($reply->isOk());
        $id = $reply->payload['id'];
        self::assertIsInt($id);
        self::assertGreaterThan(0, $id);
        self::assertSame(['id' => $id, 'code' => 'fr', 'enabled' => false], $reply->payload);
        self::assertSame('Fixture français', Hilos::$db->languages[$id]->nativeName);
        self::assertFalse(Hilos::$db->languages[$id]->rtl);
        self::assertArrayNotHasKey('fr', Hilos::$db->locales);
        $stored = Database::sql('SELECT * FROM hilos_language WHERE id = ?', [$id])->rows();
        self::assertSame('Fixture français', $stored[0]['native_name']);
        self::assertSame(0, (int)$stored[0]['rtl']);
        self::assertSame(0, (int)$stored[0]['enabled']);
        self::assertCount(self::CATALOG_COUNTRIES, Database::sql('SELECT * FROM hilos_country_name WHERE language_id = ?', [$id])->rows());
        foreach (Hilos::$db->countries as $country) {
            $name = Hilos::$db->countryNames->findBase($country->id, $id);
            self::assertNotNull($name);
            self::assertSame(BuiltInI18nCatalog::countryName($country->code, 'fr'), $name->name);
            self::assertFalse($name->locked);
        }
        self::assertSame($before + self::CATALOG_COUNTRIES + 1, $this->changes->count);
        self::assertFalse($this->changes->sawOpenTransaction, 'Source reactions run only after commit');

        $before = $this->changes->count;
        $own = $this->command(CliCommands::I18N_TEST_LANGUAGE_CREATE, [
            'code' => 'qx', 'nativeName' => 'Own language', 'rtl' => true, 'enabled' => true,
        ]);
        self::assertTrue($own->isOk());
        self::assertSame(['id' => $own->payload['id'], 'code' => 'qx', 'enabled' => true], $own->payload);
        self::assertTrue(Hilos::$db->languages[$own->payload['id']]->rtl);
        self::assertSame([], Database::sql('SELECT * FROM hilos_country_name WHERE language_id = ?', [$own->payload['id']])->rows());
        self::assertSame($before + 2, $this->changes->count);
    }

    public function testCreateKnownAndOwnCountriesKeepsTheRequestedCurrencyAndDefaultSwitch(): void
    {
        foreach ([['gb', '£', 'GBP', false], ['qx', '¤', 'QXX', true]] as [$code, $symbol, $currency, $enabled]) {
            $payload = ['code' => $code, 'currencySymbol' => $symbol, 'currencyCode' => $currency];
            if ($enabled) {
                $payload['enabled'] = true;
            }
            $reply = $this->command(CliCommands::I18N_TEST_COUNTRY_CREATE, $payload);
            self::assertTrue($reply->isOk());
            $id = $reply->payload['id'];
            self::assertIsInt($id);
            self::assertGreaterThan(0, $id);
            self::assertSame(['id' => $id, 'code' => $code, 'enabled' => $enabled], $reply->payload);
            $stored = Database::sql('SELECT * FROM hilos_country WHERE id = ?', [$id])->rows()[0];
            self::assertSame($symbol, $stored['currency_symbol']);
            self::assertSame($currency, $stored['currency_code']);
            self::assertNull($stored['default_locale_id']);
            self::assertSame((int)$enabled, (int)$stored['enabled']);
        }
        self::assertFalse($this->changes->sawOpenTransaction);
    }

    public function testCreateLocalesUsesAllSevenCatalogOrFallbackFormats(): void
    {
        $known = $this->command(CliCommands::I18N_TEST_LANGUAGE_CREATE, ['code' => 'en', 'nativeName' => 'English']);
        $own = $this->command(CliCommands::I18N_TEST_LANGUAGE_CREATE, ['code' => 'qx', 'nativeName' => 'Own language']);
        $britain = $this->command(CliCommands::I18N_TEST_COUNTRY_CREATE, ['code' => 'gb', 'currencySymbol' => '£', 'currencyCode' => 'GBP']);
        $custom = $this->command(CliCommands::I18N_TEST_COUNTRY_CREATE, ['code' => 'qy', 'currencySymbol' => 'Y', 'currencyCode' => 'QYY']);
        foreach ([$known, $own, $britain, $custom] as $parent) {
            self::assertTrue($parent->isOk());
        }
        foreach ([
            [$known->payload['id'], null, 'en', false],
            [$known->payload['id'], $britain->payload['id'], 'en-GB', true],
            [$known->payload['id'], $custom->payload['id'], 'en-QY', false],
            [$own->payload['id'], null, 'qx', true],
            [$own->payload['id'], $britain->payload['id'], 'qx-GB', false],
        ] as [$languageId, $countryId, $code, $enabled]) {
            $payload = ['languageId' => $languageId];
            if ($countryId !== null) {
                $payload['countryId'] = $countryId;
            }
            if ($enabled) {
                $payload['enabled'] = true;
            }
            $reply = $this->command(CliCommands::I18N_TEST_LOCALE_CREATE, $payload);
            self::assertTrue($reply->isOk());
            $id = $reply->payload['id'];
            self::assertIsInt($id);
            self::assertGreaterThan(0, $id);
            self::assertSame(['id' => $id, 'code' => $code, 'enabled' => $enabled], $reply->payload);
            $stored = Database::sql('SELECT * FROM hilos_locale WHERE id = ?', [$id])->rows()[0];
            self::assertSame($languageId, (int)$stored['language_id']);
            self::assertSame($countryId, $stored['country_id'] === null ? null : (int)$stored['country_id']);
            self::assertSame((int)$enabled, (int)$stored['enabled']);
            $definition = BuiltInI18nCatalog::locale($code);
            $expected = $definition === null ? [
                LocaleTemplates::DATE[0], LocaleTemplates::TIME[0], LocaleTemplates::NUMBER[0],
                LocaleTemplates::PHONE[0], LocaleTemplates::ADDRESS[0], 'metric', LocaleTemplates::COLLATION[0],
            ] : [
                $definition->dateFormat, $definition->timeFormat, $definition->numberFormat,
                $definition->phoneFormat, $definition->addressFormat, $definition->measurementSystem, $definition->collation,
            ];
            self::assertSame($expected, [
                $stored['date_format'], $stored['time_format'], $stored['number_format'],
                $stored['phone_format'], $stored['address_format'], $stored['measurement_system'], $stored['collation'],
            ], $code);
        }
        self::assertFalse($this->changes->sawOpenTransaction);
    }

    public function testDuplicateCreatesRefuseWithoutChangingExistingRowsOrAnnouncingWrites(): void
    {
        $language = Hilos::$db->languages->actions->add('en', 'Original', false);
        $country = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        self::createLocale($language, null);
        self::createLocale($language, $country);
        foreach ([
            [CliCommands::I18N_TEST_LANGUAGE_CREATE, ['code' => 'en', 'nativeName' => 'Replacement', 'rtl' => true, 'enabled' => true]],
            [CliCommands::I18N_TEST_COUNTRY_CREATE, ['code' => 'gb', 'currencySymbol' => '$', 'currencyCode' => 'USD', 'enabled' => true]],
            [CliCommands::I18N_TEST_LOCALE_CREATE, ['languageId' => $language->id, 'enabled' => true]],
            [CliCommands::I18N_TEST_LOCALE_CREATE, ['languageId' => $language->id, 'countryId' => $country->id, 'enabled' => true]],
        ] as [$command, $payload]) {
            $before = $this->changes->count;
            $rows = self::fixtureRows();
            $reply = $this->command($command, $payload);
            self::assertFalse($reply->isOk());
            self::assertStringContainsString('already exists', $reply->payload[CommandConstants::FIELD_MESSAGE]);
            self::assertSame($rows, self::fixtureRows());
            self::assertSame($before, $this->changes->count);
            self::assertFalse(Database::hasOpenTransactions());
        }
    }

    public function testMalformedDaemonPayloadsAndMissingParentsRefuseWithoutPartialRows(): void
    {
        $requests = [
            CliCommands::I18N_TEST_LANGUAGE_CREATE => ['code' => 'qx', 'nativeName' => 'Fixture', 'rtl' => false, 'enabled' => false],
            CliCommands::I18N_TEST_COUNTRY_CREATE => ['code' => 'qx', 'currencySymbol' => 'X', 'currencyCode' => 'QXX', 'enabled' => false],
            CliCommands::I18N_TEST_LOCALE_CREATE => ['languageId' => 123, 'countryId' => null, 'enabled' => false],
        ];
        foreach ($requests as $command => $valid) {
            foreach ($valid as $field => $value) {
                $wrongTypes = match (true) {
                    is_bool($value) => ['1', 1, null, []],
                    is_string($value) => [1, false, null, []],
                    default => ['123', false, 1.5, [], 0, -1],
                };
                foreach ($wrongTypes as $wrong) {
                    $reply = $this->command($command, array_replace($valid, [$field => $wrong]));
                    self::assertFalse($reply->isOk(), $command . ': ' . $field);
                    self::assertSame(0, $this->changes->count);
                    self::assertSame([[], [], [], []], self::fixtureRows());
                    self::assertFalse(Database::hasOpenTransactions());
                }
            }
            self::assertFalse($this->command($command, [])->isOk());
        }
        foreach ([
            [CliCommands::I18N_TEST_LANGUAGE_CREATE, ['code' => 'XX', 'nativeName' => 'Fixture']],
            [CliCommands::I18N_TEST_LANGUAGE_CREATE, ['code' => 'qx', 'nativeName' => ' ']],
            [CliCommands::I18N_TEST_COUNTRY_CREATE, ['code' => 'qx', 'currencySymbol' => ' ', 'currencyCode' => 'QXX']],
            [CliCommands::I18N_TEST_COUNTRY_CREATE, ['code' => 'qx', 'currencySymbol' => 'X', 'currencyCode' => 'usd']],
            [CliCommands::I18N_TEST_LOCALE_CREATE, ['languageId' => 123]],
        ] as [$command, $payload]) {
            self::assertFalse($this->command($command, $payload)->isOk());
            self::assertSame([[], [], [], []], self::fixtureRows());
        }
        $language = Hilos::$db->languages->actions->create('qx', 'Existing', false);
        $before = $this->changes->count;
        self::assertFalse($this->command(CliCommands::I18N_TEST_LOCALE_CREATE, ['languageId' => $language->id, 'countryId' => 999])->isOk());
        self::assertSame([], Database::sql('SELECT * FROM hilos_locale')->rows());
        self::assertSame($before, $this->changes->count);
    }

    public function testFailureDuringCatalogImportRollsBackTheLanguageAndEarlierNames(): void
    {
        Hilos::$db->countries->actions->takeFromCatalog();
        $fault = new class implements SourceMirrorSubscriberInterface {
            public int $namesCreated = 0;

            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($change->sourceKey === HilosDbContext::countryNames && $change->mutationType === TableMutationType::Create) {
                    if (++$this->namesCreated === 2) {
                        throw new ValidationException('Injected catalog import failure');
                    }
                }
            }
        };
        SourceChangeBus::subscribe($fault);
        $before = $this->changes->count;
        $reply = $this->command(CliCommands::I18N_TEST_LANGUAGE_CREATE, ['code' => 'fr', 'nativeName' => 'Fixture', 'enabled' => true]);
        self::assertFalse($reply->isOk());
        self::assertSame(2, $fault->namesCreated, 'The failure follows persisted language and name writes');
        self::assertSame([], Database::sql('SELECT * FROM hilos_language')->rows());
        self::assertSame([], Database::sql('SELECT * FROM hilos_country_name')->rows());
        self::assertArrayNotHasKey('fr', Hilos::$db->languages);
        self::assertSame($before, $this->changes->count, 'Rolled-back writes announce nothing');
        self::assertFalse(Database::hasOpenTransactions());
        self::assertTrue($this->command(CliCommands::I18N_TEST_LANGUAGE_CREATE, ['code' => 'fr', 'nativeName' => 'Retry'])->isOk());
    }

    public function testFailedOptionalEnableRollsBackEachCreatedFixture(): void
    {
        $language = Hilos::$db->languages->actions->create('qy', 'Existing', false);
        $fault = new class implements SourceMirrorSubscriberInterface {
            public bool $armed = true;

            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($this->armed && $change->mutationType === TableMutationType::Update) {
                    $this->armed = false;
                    throw new ValidationException('Injected enable failure');
                }
            }
        };
        SourceChangeBus::subscribe($fault);
        foreach ([
            [CliCommands::I18N_TEST_LANGUAGE_CREATE, ['code' => 'qx', 'nativeName' => 'Fixture', 'enabled' => true]],
            [CliCommands::I18N_TEST_COUNTRY_CREATE, ['code' => 'qx', 'currencySymbol' => 'X', 'currencyCode' => 'QXX', 'enabled' => true]],
            [CliCommands::I18N_TEST_LOCALE_CREATE, ['languageId' => $language->id, 'enabled' => true]],
        ] as [$command, $payload]) {
            $fault->armed = true;
            $before = $this->changes->count;
            $rows = self::fixtureRows();
            self::assertFalse($this->command($command, $payload)->isOk());
            self::assertFalse($fault->armed, 'The injected failure must occur after creation');
            self::assertSame($rows, self::fixtureRows());
            self::assertSame($before, $this->changes->count);
            self::assertFalse(Database::hasOpenTransactions());
        }
    }

    public function testLanguageClearRemovesBothNameDirectionsAndItsLocalesOnly(): void
    {
        $target = Hilos::$db->languages->actions->create('qx', 'Fixture', true);
        $writer = Hilos::$db->languages->actions->create('en', 'English', false);
        $other = Hilos::$db->languages->actions->create('fr', 'French', false);
        $sameCodeCountry = Hilos::$db->countries->actions->create('qx', 'X', 'QXX');
        $borrowed = Hilos::$db->countries->actions->create('gb', '¤', 'USD');
        $countryless = self::createLocale($target, null);
        $linked = self::createLocale($target, $borrowed);
        $unrelatedLocale = self::createLocale($writer, $borrowed);
        $borrowed->actions->update('¤', 'USD', $linked->id);
        $borrowed->actions->switchOn();
        $target->actions->switchOn();
        $countryless->actions->switchOn();
        $linked->actions->switchOn();
        Hilos::$db->languageNames->actions->createManual($target, $writer, null, 'Target base');
        Hilos::$db->languageNames->actions->createManual($target, $writer, $unrelatedLocale, 'Target correction');
        Hilos::$db->languageNames->actions->createManual($writer, $target, null, 'Written in target');
        Hilos::$db->languageNames->actions->createManual($writer, $target, $linked, 'Written correction');
        $keptLanguageName = Hilos::$db->languageNames->actions->createManual($other, $writer, null, 'Keep base');
        $keptLanguageOverride = Hilos::$db->languageNames->actions->createManual($other, $writer, $unrelatedLocale, 'Keep correction');
        Hilos::$db->countryNames->actions->createManual($sameCodeCountry, $target, null, 'Country in target');
        Hilos::$db->countryNames->actions->createManual($sameCodeCountry, $target, $linked, 'Country correction');
        Hilos::$db->countryNames->actions->createManual($borrowed, $target, null, 'Borrowed country');
        $keptCountryName = Hilos::$db->countryNames->actions->createManual($sameCodeCountry, $writer, null, 'Keep country');
        $keptCountryOverride = Hilos::$db->countryNames->actions->createManual($sameCodeCountry, $writer, $unrelatedLocale, 'Keep override');
        $unrelated = Database::sql('SELECT * FROM hilos_locale WHERE id = ?', [$unrelatedLocale->id])->rows();

        $reply = $this->command(CliCommands::I18N_TEST_LANGUAGE_CLEAR, 'qx');

        self::assertTrue($reply->isOk(), json_encode($reply->payload));
        self::assertSame(['code' => 'qx'], $reply->payload);
        self::assertSame([], Database::sql('SELECT * FROM hilos_language WHERE id = ?', [$target->id])->rows());
        self::assertSame([], Database::sql('SELECT * FROM hilos_locale WHERE language_id = ?', [$target->id])->rows());
        self::assertSame([], Database::sql(
            'SELECT * FROM hilos_language_name WHERE language_id = ? OR in_language_id = ?', [$target->id, $target->id],
        )->rows());
        self::assertSame([], Database::sql('SELECT * FROM hilos_country_name WHERE language_id = ?', [$target->id])->rows());
        self::assertSame($unrelated, Database::sql('SELECT * FROM hilos_locale WHERE id = ?', [$unrelatedLocale->id])->rows());
        self::assertArrayHasKey($sameCodeCountry->id, Hilos::$db->countries);
        self::assertArrayHasKey($writer->id, Hilos::$db->languages);
        self::assertArrayHasKey($other->id, Hilos::$db->languages);
        self::assertArrayHasKey($keptLanguageName->id, Hilos::$db->languageNames);
        self::assertArrayHasKey($keptLanguageOverride->id, Hilos::$db->languageNames);
        self::assertArrayHasKey($keptCountryName->id, Hilos::$db->countryNames);
        self::assertArrayHasKey($keptCountryOverride->id, Hilos::$db->countryNames);
        $storedCountry = Database::sql('SELECT * FROM hilos_country WHERE id = ?', [$borrowed->id])->rows()[0];
        self::assertNull($storedCountry['default_locale_id']);
        self::assertSame('¤', $storedCountry['currency_symbol']);
        self::assertSame('USD', $storedCountry['currency_code']);
        self::assertSame(1, (int)$storedCountry['enabled']);
        self::assertFalse($this->changes->sawOpenTransaction);
        $before = $this->changes->count;
        self::assertTrue($this->command(CliCommands::I18N_TEST_LANGUAGE_CLEAR, 'qx')->isOk());
        self::assertSame($before, $this->changes->count);
    }

    public function testCountryClearRemovesItsNamesAndLinkedLocalesButKeepsBothLanguages(): void
    {
        $target = Hilos::$db->countries->actions->create('qx', 'X', 'QXX');
        $sameCodeLanguage = Hilos::$db->languages->actions->create('qx', 'Own language', false);
        $writer = Hilos::$db->languages->actions->create('en', 'English', false);
        $borrowed = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        $first = self::createLocale($sameCodeLanguage, $target);
        $second = self::createLocale($writer, $target);
        $unrelated = self::createLocale($writer, $borrowed);
        $target->actions->update('X', 'QXX', $first->id);
        $target->actions->switchOn();
        $first->actions->switchOn();
        $second->actions->switchOn();
        Hilos::$db->countryNames->actions->createManual($target, $writer, null, 'Target');
        Hilos::$db->countryNames->actions->createManual($target, $writer, $unrelated, 'Target correction outside its country');
        Hilos::$db->countryNames->actions->createManual($borrowed, $writer, null, 'Keep base');
        Hilos::$db->countryNames->actions->createManual($borrowed, $writer, $second, 'Remove locale correction');
        Hilos::$db->countryNames->actions->createManual($borrowed, $writer, $unrelated, 'Keep correction');
        Hilos::$db->languageNames->actions->createManual($sameCodeLanguage, $writer, null, 'Keep language base');
        Hilos::$db->languageNames->actions->createManual($sameCodeLanguage, $writer, $second, 'Remove language correction');
        $unrelatedRows = Database::sql('SELECT * FROM hilos_locale WHERE id = ?', [$unrelated->id])->rows();

        $reply = $this->command(CliCommands::I18N_TEST_COUNTRY_CLEAR, 'qx');

        self::assertTrue($reply->isOk(), json_encode($reply->payload));
        self::assertSame(['code' => 'qx'], $reply->payload);
        self::assertSame([], Database::sql('SELECT * FROM hilos_country WHERE id = ?', [$target->id])->rows());
        self::assertSame([], Database::sql('SELECT * FROM hilos_locale WHERE country_id = ?', [$target->id])->rows());
        self::assertSame([], Database::sql('SELECT * FROM hilos_country_name WHERE country_id = ?', [$target->id])->rows());
        self::assertSame([], Database::sql('SELECT * FROM hilos_language_name WHERE locale_id = ?', [$second->id])->rows());
        self::assertSame([], Database::sql('SELECT * FROM hilos_country_name WHERE locale_id = ?', [$second->id])->rows());
        self::assertSame($unrelatedRows, Database::sql('SELECT * FROM hilos_locale WHERE id = ?', [$unrelated->id])->rows());
        self::assertArrayHasKey($sameCodeLanguage->id, Hilos::$db->languages);
        self::assertArrayHasKey($writer->id, Hilos::$db->languages);
        self::assertArrayHasKey($borrowed->id, Hilos::$db->countries);
        self::assertCount(2, Database::sql('SELECT * FROM hilos_country_name')->rows());
        self::assertCount(1, Database::sql('SELECT * FROM hilos_language_name')->rows());
        self::assertFalse($this->changes->sawOpenTransaction);
        $before = $this->changes->count;
        self::assertTrue($this->command(CliCommands::I18N_TEST_COUNTRY_CLEAR, 'qx')->isOk());
        self::assertSame($before, $this->changes->count);
    }

    public function testLocaleClearPreservesBorrowedCountryCurrencyAndBothOriginalSwitchStates(): void
    {
        $writer = Hilos::$db->languages->actions->create('en', 'English', false);
        $named = Hilos::$db->languages->actions->create('qx', 'Named', false);
        $country = Hilos::$db->countries->actions->create('gb', '¤', 'USD');
        foreach ([false, true] as $enabled) {
            $locale = self::createLocale($writer, $country);
            $country->actions->update('¤', 'USD', $locale->id);
            if ($enabled) {
                $country->actions->switchOn();
                $locale->actions->switchOn();
            }
            $languageBase = Hilos::$db->languageNames->actions->createManual($named, $writer, null, 'Base');
            Hilos::$db->languageNames->actions->createManual($named, $writer, $locale, 'Correction');
            $countryBase = Hilos::$db->countryNames->actions->createManual($country, $writer, null, 'Base');
            Hilos::$db->countryNames->actions->createManual($country, $writer, $locale, '');
            $reply = $this->command(CliCommands::I18N_TEST_LOCALE_CLEAR, 'en-GB');
            self::assertTrue($reply->isOk(), json_encode($reply->payload));
            self::assertSame(['code' => 'en-GB'], $reply->payload);
            self::assertSame([], Database::sql('SELECT * FROM hilos_locale WHERE id = ?', [$locale->id])->rows());
            self::assertSame([], Database::sql('SELECT * FROM hilos_language_name WHERE locale_id = ?', [$locale->id])->rows());
            self::assertSame([], Database::sql('SELECT * FROM hilos_country_name WHERE locale_id = ?', [$locale->id])->rows());
            $stored = Database::sql('SELECT * FROM hilos_country WHERE id = ?', [$country->id])->rows()[0];
            self::assertNull($stored['default_locale_id']);
            self::assertSame('¤', $stored['currency_symbol']);
            self::assertSame('USD', $stored['currency_code']);
            self::assertSame((int)$enabled, (int)$stored['enabled']);
            self::assertArrayHasKey($languageBase->id, Hilos::$db->languageNames);
            self::assertArrayHasKey($countryBase->id, Hilos::$db->countryNames);
            $before = $this->changes->count;
            self::assertTrue($this->command(CliCommands::I18N_TEST_LOCALE_CLEAR, 'en-GB')->isOk());
            self::assertSame($before, $this->changes->count);
            $languageBase->actions->delete();
            $countryBase->actions->delete();
        }
        self::assertFalse($this->changes->sawOpenTransaction);
    }

    public function testLocaleClearLeavesAnotherCountryDefaultAndCountrylessParentsUntouched(): void
    {
        $known = Hilos::$db->languages->actions->create('en', 'English', false);
        $own = Hilos::$db->languages->actions->create('qx', 'Own', false);
        $country = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        $kept = self::createLocale($own, $country);
        self::createLocale($known, $country);
        $country->actions->update('£', 'GBP', $kept->id);
        $country->actions->switchOn();
        $countryBefore = Database::sql('SELECT * FROM hilos_country')->rows();
        $keptBefore = Database::sql('SELECT * FROM hilos_locale WHERE id = ?', [$kept->id])->rows();
        $before = $this->changes->count;
        self::assertTrue($this->command(CliCommands::I18N_TEST_LOCALE_CLEAR, 'en-GB')->isOk());
        self::assertSame($before + 1, $this->changes->count, 'Only the disabled target locale is removed');
        self::assertSame($countryBefore, Database::sql('SELECT * FROM hilos_country')->rows());
        self::assertSame($keptBefore, Database::sql('SELECT * FROM hilos_locale WHERE id = ?', [$kept->id])->rows());
        foreach ([$known, $own] as $language) {
            $locale = self::createLocale($language, null);
            $locale->actions->switchOn();
            self::assertTrue($this->command(CliCommands::I18N_TEST_LOCALE_CLEAR, $locale->code)->isOk());
            self::assertSame([], Database::sql('SELECT * FROM hilos_locale WHERE id = ?', [$locale->id])->rows());
            self::assertArrayHasKey($language->id, Hilos::$db->languages);
        }
        self::assertFalse($this->changes->sawOpenTransaction);
    }

    public function testClearProtectsDefaultsAndBuiltInCountriesBeforeAnyDependentWrite(): void
    {
        foreach ([
            [CliCommands::I18N_TEST_LANGUAGE_CLEAR, 'es'],
            [CliCommands::I18N_TEST_LOCALE_CLEAR, 'es'],
            [CliCommands::I18N_TEST_COUNTRY_CLEAR, 'gb'],
        ] as [$command, $code]) {
            self::assertFalse($this->command($command, $code)->isOk(), 'Even an absent protected address refuses');
        }
        $default = Hilos::$db->languages->actions->create('es', 'Spanish', false);
        $other = Hilos::$db->languages->actions->create('en', 'English', false);
        $country = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        $locale = self::createLocale($default, null);
        $linked = self::createLocale($default, $country);
        $country->actions->update('£', 'GBP', $linked->id);
        $country->actions->switchOn();
        $default->actions->switchOn();
        $locale->actions->switchOn();
        Hilos::$db->languageNames->actions->createManual($other, $default, null, 'Base');
        Hilos::$db->languageNames->actions->createManual($other, $default, $locale, 'Correction');
        Hilos::$db->countryNames->actions->createManual($country, $default, null, 'Base');
        Hilos::$db->countryNames->actions->createManual($country, $default, $linked, 'Correction');
        $rows = self::fixtureRows();
        $names = Database::sql('SELECT * FROM hilos_language_name ORDER BY id')->rows();
        $before = $this->changes->count;
        foreach ([
            [CliCommands::I18N_TEST_LANGUAGE_CLEAR, 'es'],
            [CliCommands::I18N_TEST_LOCALE_CLEAR, 'es'],
            [CliCommands::I18N_TEST_COUNTRY_CLEAR, 'gb'],
        ] as [$command, $code]) {
            $reply = $this->command($command, $code);
            self::assertFalse($reply->isOk());
            self::assertStringContainsString($code === 'gb' ? 'built-in country' : 'default', $reply->payload['message']);
            self::assertSame($rows, self::fixtureRows());
            self::assertSame($names, Database::sql('SELECT * FROM hilos_language_name ORDER BY id')->rows());
            self::assertSame($before, $this->changes->count);
        }
        self::assertTrue(
            $this->command(CliCommands::I18N_TEST_LOCALE_CLEAR, 'es-GB')->isOk(),
            'A default language may have a removable linked locale',
        );
        self::assertArrayHasKey($locale->id, Hilos::$db->locales);
    }

    public function testClearRejectsMalformedDaemonPayloadsWithoutWritingAndAcceptsAbsentFixtures(): void
    {
        foreach ([CliCommands::I18N_TEST_LANGUAGE_CLEAR, CliCommands::I18N_TEST_COUNTRY_CLEAR, CliCommands::I18N_TEST_LOCALE_CLEAR] as $command) {
            foreach ([[], ['code' => 42], ['code' => false], ['code' => null], ['code' => []], ['code' => ''], ['code' => 'QX'],
                ['code' => "qx\n"], ['code' => 'qx-gb'], ['code' => 'qx', 'extra' => true]] as $payload) {
                self::assertFalse($this->command($command, $payload)->isOk());
                self::assertSame(0, $this->changes->count);
                self::assertSame([[], [], [], []], self::fixtureRows());
            }
            $reply = $this->command($command, 'qx');
            self::assertTrue($reply->isOk());
            self::assertSame(['code' => 'qx'], $reply->payload);
            self::assertSame(0, $this->changes->count);
        }
        foreach ([CliCommands::I18N_TEST_LANGUAGE_CLEAR, CliCommands::I18N_TEST_COUNTRY_CLEAR] as $command) {
            self::assertFalse($this->command($command, 'qx-GB')->isOk());
        }
    }

    public function testLanguageClearResumesAfterCommittedBaseDeletionAndAMissingLocale(): void
    {
        $target = Hilos::$db->languages->actions->create('qx', 'Fixture', false);
        $firstWriter = Hilos::$db->languages->actions->create('en', 'English', false);
        $secondWriter = Hilos::$db->languages->actions->create('fr', 'French', false);
        $first = Hilos::$db->languageNames->actions->createManual($target, $firstWriter, null, 'First');
        $second = Hilos::$db->languageNames->actions->createManual($target, $secondWriter, null, 'Second');
        self::createLocale($target, null)->actions->delete();
        $fault = new class implements SourceMirrorSubscriberInterface {
            public int $deletions = 0;

            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($change->sourceKey === HilosDbContext::languageNames && $change->mutationType === TableMutationType::Delete
                    && ++$this->deletions === 2) {
                    throw new ValidationException('Injected cleanup failure');
                }
            }
        };
        SourceChangeBus::subscribe($fault);
        $reply = $this->command(CliCommands::I18N_TEST_LANGUAGE_CLEAR, 'qx');
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('Injected cleanup failure', $reply->payload['message']);
        self::assertSame([], Database::sql('SELECT * FROM hilos_language_name WHERE id = ?', [$first->id])->rows());
        self::assertCount(1, Database::sql('SELECT * FROM hilos_language_name WHERE id = ?', [$second->id])->rows());
        self::assertArrayHasKey($target->id, Hilos::$db->languages);
        self::assertFalse(Database::hasOpenTransactions());
        self::assertTrue($this->command(CliCommands::I18N_TEST_LANGUAGE_CLEAR, 'qx')->isOk());
        self::assertSame([], Database::sql('SELECT * FROM hilos_language_name')->rows());
        self::assertArrayNotHasKey($target->id, Hilos::$db->languages);
        self::assertFalse($this->changes->sawOpenTransaction);
        $before = $this->changes->count;
        self::assertTrue($this->command(CliCommands::I18N_TEST_LANGUAGE_CLEAR, 'qx')->isOk());
        self::assertSame($before, $this->changes->count);
    }

    public function testLocaleClearRestoresCountryOnFailedPointerUpdateAndCanResume(): void
    {
        $language = Hilos::$db->languages->actions->create('qx', 'Fixture', false);
        $country = Hilos::$db->countries->actions->create('gb', '¤', 'USD');
        $locale = self::createLocale($language, $country);
        $country->actions->update('¤', 'USD', $locale->id);
        $country->actions->switchOn();
        $locale->actions->switchOn();
        $before = Database::sql('SELECT * FROM hilos_country')->rows();
        $fault = new class implements SourceMirrorSubscriberInterface {
            public int $updates = 0;

            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($change->sourceKey === HilosDbContext::countries && $change->mutationType === TableMutationType::Update
                    && ++$this->updates === 2) {
                    throw new ValidationException('Injected pointer update failure');
                }
            }
        };
        SourceChangeBus::subscribe($fault);
        $announcements = $this->changes->count;
        $reply = $this->command(CliCommands::I18N_TEST_LOCALE_CLEAR, 'qx-GB');
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('Injected pointer update failure', $reply->payload['message']);
        self::assertSame($before, Database::sql('SELECT * FROM hilos_country')->rows());
        self::assertTrue(Hilos::$db->countries[$country->id]->enabled);
        self::assertSame($locale->id, Hilos::$db->countries[$country->id]->defaultLocaleId);
        self::assertSame($announcements, $this->changes->count);
        self::assertFalse(Database::hasOpenTransactions());
        self::assertTrue($this->command(CliCommands::I18N_TEST_LOCALE_CLEAR, 'qx-GB')->isOk());
        self::assertArrayNotHasKey($locale->id, Hilos::$db->locales);
        self::assertTrue(Hilos::$db->countries[$country->id]->enabled);
        self::assertNull(Hilos::$db->countries[$country->id]->defaultLocaleId);
        self::assertFalse($this->changes->sawOpenTransaction);
    }

    public function testLanguageClearAlsoRemovesAnEnabledBuiltInLanguageAndItsImportedNames(): void
    {
        Hilos::$db->countries->actions->takeFromCatalog();
        $reply = $this->command(CliCommands::I18N_TEST_LANGUAGE_CREATE, [
            'code' => 'en', 'nativeName' => 'Fixture English', 'enabled' => true,
        ]);
        self::assertTrue($reply->isOk());
        $languageId = $reply->payload['id'];
        self::assertCount(self::CATALOG_COUNTRIES, Database::sql('SELECT * FROM hilos_country_name')->rows());
        $countries = Database::sql('SELECT * FROM hilos_country ORDER BY id')->rows();

        $reply = $this->command(CliCommands::I18N_TEST_LANGUAGE_CLEAR, 'en');

        self::assertTrue($reply->isOk());
        self::assertSame(['code' => 'en'], $reply->payload);
        self::assertSame([], Database::sql('SELECT * FROM hilos_language WHERE id = ?', [$languageId])->rows());
        self::assertSame([], Database::sql('SELECT * FROM hilos_country_name')->rows());
        self::assertSame($countries, Database::sql('SELECT * FROM hilos_country ORDER BY id')->rows());
        self::assertFalse($this->changes->sawOpenTransaction);
    }

    public function testCountryClearResumesAfterMissingNamesAndLocaleWithoutTouchingTheirLanguage(): void
    {
        $country = Hilos::$db->countries->actions->create('qx', 'X', 'QXX');
        $language = Hilos::$db->languages->actions->create('en', 'English', false);
        $other = Hilos::$db->languages->actions->create('fr', 'French', false);
        $first = Hilos::$db->countryNames->actions->createManual($country, $language, null, 'Removed base');
        $missingLocale = self::createLocale($language, $country);
        Hilos::$db->countryNames->actions->createManual($country, $language, $missingLocale, 'Removed correction');
        $first->actions->delete();
        $missingLocale->actions->delete();
        Hilos::$db->countryNames->actions->createManual($country, $other, null, 'Remaining base');
        $remaining = self::createLocale($other, $country);
        Hilos::$db->countryNames->actions->createManual($country, $other, $remaining, 'Remaining correction');

        self::assertTrue($this->command(CliCommands::I18N_TEST_COUNTRY_CLEAR, 'qx')->isOk());

        self::assertSame([], Database::sql('SELECT * FROM hilos_country')->rows());
        self::assertSame([], Database::sql('SELECT * FROM hilos_locale')->rows());
        self::assertSame([], Database::sql('SELECT * FROM hilos_country_name')->rows());
        self::assertArrayHasKey($language->id, Hilos::$db->languages);
        self::assertArrayHasKey($other->id, Hilos::$db->languages);
        self::assertFalse($this->changes->sawOpenTransaction);
        $before = $this->changes->count;
        self::assertTrue($this->command(CliCommands::I18N_TEST_COUNTRY_CLEAR, 'qx')->isOk());
        self::assertSame($before, $this->changes->count);
    }

    /** @return array Independent snapshots of the fixture tables */
    private static function fixtureRows(): array
    {
        return [
            Database::sql('SELECT * FROM hilos_language ORDER BY id')->rows(),
            Database::sql('SELECT * FROM hilos_country ORDER BY id')->rows(),
            Database::sql('SELECT * FROM hilos_locale ORDER BY id')->rows(),
            Database::sql('SELECT * FROM hilos_country_name ORDER BY id')->rows(),
        ];
    }

    /**
     * @param string $command Library fixture command
     * @param string|array|null $payload Fixture code, structured create payload, or no arguments
     * @return CommandReplyDTO The command's single correlated reply
     * @throws HilosException When the command cannot be answered
     */
    private function command(string $command, string|array|null $payload = null): CommandReplyDTO
    {
        $previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        Hilos::$sr->dbSyncBroadcastEnabled = false;
        try {
            new I18nLibraryAgent()->onSignalCommand(
                new CommandRequestDTO(
                    'i18n-optional-read',
                    $command,
                    is_array($payload) ? $payload : ($payload === null ? [] : [I18nTestCommandConstants::FIELD_CODE => $payload]),
                ),
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
    public bool $sawOpenTransaction = false;

    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->count++;
        $this->sawOpenTransaction = $this->sawOpenTransaction || Database::hasOpenTransactions();
    }
}
