<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Backup\Anonymization\AnonymizationCoverageValidator;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Language as EntityLanguage;
use Hilos\Database\Entity\Item\LanguageName as EntityLanguageName;
use Hilos\Database\Entity\Item\CountryName as EntityCountryName;
use Hilos\Database\Entity\Item\Locale as EntityLocale;
use Hilos\Database\Object\Item\Locale as ObjectLocale;
use Hilos\Database\View\Collection\LanguageNames;
use Hilos\Database\View\Collection\Locales;
use Hilos\Database\Entity\Item\Country as EntityCountry;
use Hilos\Database\Object\Item\Country as ObjectCountry;
use Hilos\Database\View\Collection\CountryNames;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Exception\SqlRuntime\ForeignKeyConstraintException;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\Country;
use Hilos\Database\View\Item\Language;
use Hilos\Database\View\Item\Locale;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\Exception\I18nRowFrozenException;
use Hilos\I18n\MeasurementSystem;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use PHPUnit\Framework\Attributes\DataProvider;

/** Reference rows and their action doors against the live framework schema. */
final class I18nReferenceIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = [
        'hilos_language', 'hilos_country', 'hilos_locale', 'hilos_language_name', 'hilos_country_name',
    ];
    private const string OWNER_AGENT_ID = 'test-agent:i18n-library';

    private ?DbContext $previousDb = null;
    private string|false $previousDefaultLanguage;
    private I18nChangeRecorder $changes;

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
        $db = new I18nReferenceTestDbContext();
        $db->configure();
        Hilos::$db = $db;

        TruthSourceRegistry::register(HilosDbContext::languages, TruthSourceKeys::all(), self::OWNER_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::countries, TruthSourceKeys::all(), self::OWNER_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::locales, TruthSourceKeys::all(), self::OWNER_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::languageNames, TruthSourceKeys::all(), self::OWNER_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::countryNames, TruthSourceKeys::all(), self::OWNER_AGENT_ID);
        ExecutionContext::setCurrentAgentId(self::OWNER_AGENT_ID);
        SourceChangeBus::reset();
        $this->changes = new I18nChangeRecorder();
        SourceChangeBus::subscribe($this->changes);
    }

    /** @throws DatabaseException When dropping the stub table fails */
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

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testLanguageReadsByCodeAndIdAndStartsSwitchedOff(): void
    {
        $language = Hilos::$db->languages->actions->create('en', 'English', false);

        $this->assertNotNull($language->id);
        $this->assertSame('en', $language->code);
        $this->assertSame('English', $language->nativeName);
        $this->assertFalse($language->enabled);
        $this->assertSame($language->id, Hilos::$db->languages['en']?->id);
        $this->assertSame($language->id, Hilos::$db->languages[$language->id]?->id);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testLanguageRejectsMalformedCodesAndDuplicateCode(): void
    {
        foreach (['EN', 'eng', ''] as $code) {
            try {
                Hilos::$db->languages->actions->create($code, 'English', false);
                $this->fail("Expected malformed language code {$code} to be refused");
            } catch (ValidationException) {
                $this->assertNull(Hilos::$db->languages[$code]);
            }
        }

        Hilos::$db->languages->actions->create('en', 'English', false);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Language 'en' already exists.");
        Hilos::$db->languages->actions->create('en', 'Duplicate', false);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testLanguageIsFrozenOnlyWhileSwitchedOn(): void
    {
        $language = Hilos::$db->languages->actions->create('ar', 'Arabic', true);
        $language->actions->switchOn();
        $this->assertTrue($language->enabled);

        try {
            $language->actions->update('Changed', false);
            $this->fail('Expected a switched-on language to refuse an edit');
        } catch (I18nRowFrozenException $error) {
            $this->assertStringContainsString("Language 'ar' is switched on", $error->getMessage());
        }
        $this->assertSame('Arabic', EntityLanguage::get([EntityLanguage::code => 'ar'])->first()?->native_name);

        $language->actions->switchOff();
        $language->actions->update('Modern Arabic', false);
        $language->actions->switchOn();
        $this->assertSame('Modern Arabic', $language->nativeName);
        $this->assertFalse($language->rtl);
        $this->assertTrue($language->enabled);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testLanguageSwitchesAreIdempotent(): void
    {
        $language = Hilos::$db->languages->actions->create('fr', 'Français', false);
        $before = $this->changes->count;
        $language->actions->switchOff();
        $this->assertSame($before, $this->changes->count);
        $language->actions->switchOn();
        $this->assertSame($before + 1, $this->changes->count);
        $language->actions->switchOn();
        $this->assertSame($before + 1, $this->changes->count);
        $language->actions->switchOff();
        $this->assertSame($before + 2, $this->changes->count);
        $language->actions->switchOff();
        $this->assertSame($before + 2, $this->changes->count);

        $this->assertFalse($language->enabled);
        $this->assertFalse(EntityLanguage::get([EntityLanguage::code => 'fr'])->first()?->enabled);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testLanguageCreationRequiresAnOwner(): void
    {
        ExecutionContext::setCurrentAgentId('test-agent:outsider');
        $this->expectException(CreateNotAllowedException::class);
        Hilos::$db->languages->actions->create('de', 'Deutsch', false);
    }

    /** @throws HilosException When actions, bridges or the SQL counter fail */
    public function testCountryLocaleBridgeLoadsOnceAndTracksWritesWithoutWarmSelects(): void
    {
        $english = Hilos::$db->languages->actions->create('en', 'English', false);
        $german = Hilos::$db->languages->actions->create('de', 'Deutsch', false);
        $country = Hilos::$db->countries->actions->create('qx', '¤', 'XXX');
        $foreign = Hilos::$db->countries->actions->create('qy', '¤', 'XXX');
        $empty = Hilos::$db->countries->actions->create('qz', '¤', 'XXX');
        $bare = self::createLocale($english, null);
        $regional = self::createLocale($english, $country);
        $foreignLocale = self::createLocale($english, $foreign);
        $countryId = $country->id;
        $regionalId = $regional->id;

        // A new context mounts the production eager collections, without preloading locales.
        Hilos::$db = new I18nReferenceTestDbContext();
        Hilos::$db->configure();
        $country = Hilos::$db->countries[$countryId];
        $before = (int) Database::sql("SHOW SESSION STATUS LIKE 'Com_select'")->firstRow()['Value'];
        $locales = $country->locales;
        $after = (int) Database::sql("SHOW SESSION STATUS LIKE 'Com_select'")->firstRow()['Value'];
        self::assertSame(1, $after - $before, 'The cold bridge loads the complete locale table once.');
        self::assertInstanceOf(Locales::class, $locales);
        self::assertCount(1, $locales);
        self::assertSame(Hilos::$db->locales[$regionalId], $locales[$regionalId]);
        self::assertNull($locales[$bare->id]);
        self::assertNull($locales[$foreignLocale->id]);
        self::assertCount(0, Hilos::$db->countries[$empty->id]->locales);
        self::assertCount(0, Hilos::$db->locales->forCountryId(null));
        self::assertCount(0, new Country(ObjectCountry::fromEntity(new EntityCountry()))->locales);
        $this->assertWarmCountryLocales($country, ['en-QX' => false], null, null);

        $added = self::createLocale($german, $country);
        $this->assertWarmCountryLocales($country, ['de-QX' => false, 'en-QX' => false], null, null);
        Hilos::$db->locales[$regionalId]->actions->switchOn();
        $this->assertWarmCountryLocales($country, ['de-QX' => false, 'en-QX' => true], null, null);
        $country->actions->update('¤', 'XXX', $regionalId);
        $this->assertWarmCountryLocales($country, ['de-QX' => false, 'en-QX' => true], 'en-QX', true);
        Hilos::$db->locales[$regionalId]->actions->switchOff();
        $this->assertWarmCountryLocales($country, ['de-QX' => false, 'en-QX' => false], 'en-QX', false);
        $country->actions->update('¤', 'XXX', $added->id);
        $this->assertWarmCountryLocales($country, ['de-QX' => false, 'en-QX' => false], 'de-QX', false);
        $country->actions->update('¤', 'XXX', null);
        $this->assertWarmCountryLocales($country, ['de-QX' => false, 'en-QX' => false], null, null);
        $added->actions->delete();
        $this->assertWarmCountryLocales($country, ['en-QX' => false], null, null);
        Hilos::$db->locales[$regionalId]->actions->delete();
        $this->assertWarmCountryLocales($country, [], null, null);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testCountryAndLocaleCodesAndBridges(): void
    {
        $language = Hilos::$db->languages->actions->create('en', 'English', false);
        $country = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        $bare = self::createLocale($language, null);
        $regional = self::createLocale($language, $country);

        $this->assertSame('en', $bare->code);
        $this->assertNull($bare->country);
        $this->assertSame('en-GB', $regional->code);
        $this->assertSame($language->id, $regional->language?->id);
        $this->assertSame($country->id, $regional->country?->id);
        $this->assertSame(MeasurementSystem::METRIC, $regional->measurementSystem);
        $this->assertFalse($country->enabled);
        $this->assertFalse($regional->enabled);
        $this->assertSame($country->id, Hilos::$db->countries['gb']?->id);
        $this->assertSame($country->id, Hilos::$db->countries[$country->id]?->id);
        $this->assertSame($regional->id, Hilos::$db->locales['en-GB']?->id);
        $this->assertSame($regional->id, Hilos::$db->locales[$regional->id]?->id);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testCountryValidationAndFrozenEdits(): void
    {
        foreach (['GB', 'gbr', ''] as $code) {
            try {
                Hilos::$db->countries->actions->create($code, '£', 'GBP');
                $this->fail("Expected malformed country code {$code} to be refused");
            } catch (ValidationException) {
                $this->assertNull(Hilos::$db->countries[$code]);
            }
        }
        foreach (['usd', 'USDD'] as $currencyCode) {
            try {
                Hilos::$db->countries->actions->create('us', '$', $currencyCode);
                $this->fail("Expected malformed currency code {$currencyCode} to be refused");
            } catch (ValidationException) {
                $this->assertNull(Hilos::$db->countries['us']);
            }
        }

        $country = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        try {
            Hilos::$db->countries->actions->create('gb', 'Again', 'GBP');
            $this->fail('Expected a duplicate country code to be refused');
        } catch (ValidationException $error) {
            $this->assertSame("Country 'gb' already exists.", $error->getMessage());
            $this->assertSame($country->id, Hilos::$db->countries['gb']?->id);
        }
        $country->actions->switchOn();
        try {
            $country->actions->update('€', 'EUR', null);
            $this->fail('Expected a switched-on country to refuse an edit');
        } catch (I18nRowFrozenException) {
            $this->assertSame('GBP', $country->currencyCode);
        }
        Database::sql('SELECT `currency_code` FROM `hilos_country` WHERE `id` = ?', [$country->id]);
        $this->assertSame('GBP', Database::row()['currency_code']);

        $country->actions->switchOff();
        $country->actions->update('€', 'EUR', null);
        $country->actions->switchOn();
        $this->assertSame('EUR', $country->currencyCode);
        $this->assertTrue($country->enabled);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testLocaleValidationDuplicateAndFrozenEdits(): void
    {
        $language = Hilos::$db->languages->actions->create('en', 'English', false);
        $locale = self::createLocale($language, null);

        try {
            self::createLocale($language, null);
            $this->fail('Expected a second countryless locale to be refused');
        } catch (ValidationException $error) {
            $this->assertSame("Locale 'en' already exists.", $error->getMessage());
            $this->assertSame($locale->id, Hilos::$db->locales['en']?->id);
        }

        $country = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        $regional = self::createLocale($language, $country);
        try {
            self::createLocale($language, $country);
            $this->fail('Expected a second locale of one language-country pair to be refused');
        } catch (ValidationException $error) {
            $this->assertSame("Locale 'en-GB' already exists.", $error->getMessage());
            $this->assertSame($regional->id, Hilos::$db->locales['en-GB']?->id);
        }

        $otherCountry = Hilos::$db->countries->actions->create('us', '$', 'USD');
        try {
            Hilos::$db->locales->actions->create(
                $language, $otherCountry, '', 'HH:mm:ss', '1,000.00', '+XX-XXXX-XXXX',
                'Street, House, City, Index', MeasurementSystem::METRIC, 'und',
            );
            $this->fail('Expected an empty format to be refused');
        } catch (ValidationException $error) {
            $this->assertSame("Date format '' is not recognized: expected one of the known templates.", $error->getMessage());
            $this->assertNull(Hilos::$db->locales['en-US']);
        }

        $locale->actions->switchOn();
        try {
            $locale->actions->update('unknown', 'HH:mm:ss', '1,000.00', '+XX-XXXX-XXXX',
                'Street, House, City, Index', MeasurementSystem::IMPERIAL, 'und');
            $this->fail('Expected a switched-on locale to refuse an edit');
        } catch (I18nRowFrozenException) {
            $this->assertSame('YYYY-MM-DD', $locale->dateFormat);
        }
        Database::sql('SELECT `date_format` FROM `hilos_locale` WHERE `id` = ?', [$locale->id]);
        $this->assertSame('YYYY-MM-DD', Database::row()['date_format']);

        $locale->actions->switchOff();
        try {
            $locale->actions->update('', 'HH:mm:ss', '1,000.00', '+XX-XXXX-XXXX', 'Street, House, City, Index', MeasurementSystem::IMPERIAL, 'und');
            $this->fail('Expected an empty updated format to be refused');
        } catch (ValidationException $error) {
            $this->assertSame("Date format '' is not recognized: expected one of the known templates.", $error->getMessage());
            $this->assertSame('YYYY-MM-DD', $locale->dateFormat);
        }
        $locale->actions->update('DD/MM/YYYY', 'HH:mm:ss', '1,000.00', '+XX-XXXX-XXXX',
            'Street, House, City, Index', MeasurementSystem::IMPERIAL, 'und');
        $locale->actions->switchOn();
        $this->assertSame('DD/MM/YYYY', $locale->dateFormat);
        $this->assertSame(MeasurementSystem::IMPERIAL, $locale->measurementSystem);
        $this->assertTrue($locale->enabled);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testCountryDefaultLocaleIsRestrictedToItsOwnLocales(): void
    {
        $language = Hilos::$db->languages->actions->create('en', 'English', false);
        $gb = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        $us = Hilos::$db->countries->actions->create('us', '$', 'USD');
        $gbLocale = self::createLocale($language, $gb);
        $usLocale = self::createLocale($language, $us);

        $gb->actions->update('£', 'GBP', $gbLocale->id);
        $this->assertSame($gbLocale->id, $gb->defaultLocale?->id);

        try {
            $gb->actions->update('£', 'GBP', $usLocale->id);
            $this->fail('Expected another country\'s locale to be refused before a write');
        } catch (ValidationException) {
            $this->assertSame($gbLocale->id, $gb->defaultLocaleId);
        }

        $this->expectException(ForeignKeyConstraintException::class);
        Database::sqlRun('UPDATE `hilos_country` SET `default_locale_id` = ? WHERE `id` = ?', [$usLocale->id, $gb->id]);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testReferencesPreventDeletionUntilTheDefaultIsCleared(): void
    {
        $language = Hilos::$db->languages->actions->create('en', 'English', false);
        $country = Hilos::$db->countries->actions->create('qx', '£', 'GBP');
        $locale = self::createLocale($language, $country);
        $country->actions->update('£', 'GBP', $locale->id);

        try {
            $locale->actions->delete();
            $this->fail('Expected a default locale to be protected before the country key');
        } catch (ValidationException $failure) {
            $this->assertSame(
                "Locale 'en-QX' is the country's default: choose another locale for the country first.",
                $failure->getMessage(),
            );
            $this->assertNotNull(Hilos::$db->locales[$locale->id]);
        }
        try {
            $language->actions->delete();
            $this->fail('Expected a language with a locale to be protected');
        } catch (ValidationException $failure) {
            $this->assertSame("Language 'en' cannot be deleted: it has locales.", $failure->getMessage());
            $this->assertNotNull(Hilos::$db->languages[$language->id]);
        }

        $country->actions->update('£', 'GBP', null);
        $locale->actions->delete();
        $this->assertNull(Hilos::$db->locales[$locale->id]);
        $language->actions->delete();
        $this->assertNull(Hilos::$db->languages[$language->id]);
        $country->actions->delete();
        $this->assertNull(Hilos::$db->countries[$country->id]);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testCountryAndLocaleSwitchesAreIdempotent(): void
    {
        $language = Hilos::$db->languages->actions->create('en', 'English', false);
        $country = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        $locale = self::createLocale($language, $country);

        foreach ([$country, $locale] as $row) {
            $before = $this->changes->count;
            $row->actions->switchOff();
            $this->assertSame($before, $this->changes->count);
            $row->actions->switchOn();
            $this->assertSame($before + 1, $this->changes->count);
            $row->actions->switchOn();
            $this->assertSame($before + 1, $this->changes->count);
            $row->actions->switchOff();
            $this->assertSame($before + 2, $this->changes->count);
            $row->actions->switchOff();
            $this->assertSame($before + 2, $this->changes->count);
            $this->assertFalse($row->enabled);
        }
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testLanguageNamesKeepLiteralValuesAndDeleteTheirOverrides(): void
    {
        $named = Hilos::$db->languages->actions->create('fr', 'Français', false);
        $writing = Hilos::$db->languages->actions->create('en', 'English', false);
        $locale = self::createLocale($writing, null);

        $base = Hilos::$db->languageNames->actions->createManual($named, $writing, null, '');
        $this->assertSame('', $base->name);
        $this->assertTrue($base->locked);
        $this->assertNull($base->locale);
        $this->assertSame($named->id, $base->language->id);
        $this->assertSame($writing->id, $base->inLanguage->id);
        $this->assertSame($base->id, Hilos::$db->languageNames->findBase($named->id, $writing->id)?->id);
        $this->assertSame($base->id, Hilos::$db->languageNames[$base->id]?->id);

        $override = Hilos::$db->languageNames->actions->createManual($named, $writing, $locale, 'French');
        $this->assertSame($locale->id, $override->locale?->id);
        $this->assertSame(
            $override->id,
            Hilos::$db->languageNames->findOverride($named->id, $writing->id, $locale->id)?->id,
        );
        $override->actions->edit('');
        $this->assertSame('', $override->name);
        $override->actions->delete();
        $this->assertNull(Hilos::$db->languageNames[$override->id]);
        $this->assertNotNull(Hilos::$db->languageNames[$base->id]);

        $another = Hilos::$db->languageNames->actions->createManual($named, $writing, $locale, 'Français');
        $base->actions->delete();
        $this->assertNull(Hilos::$db->languageNames[$base->id]);
        $this->assertNull(Hilos::$db->languageNames[$another->id]);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testCountryNamesHaveCatalogAndManualWriteDoors(): void
    {
        $writing = Hilos::$db->languages->actions->create('en', 'English', false);
        $country = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        $locale = self::createLocale($writing, $country);

        $base = Hilos::$db->countryNames->actions->createCatalogBase($country, $writing, 'United Kingdom');
        $this->assertFalse($base->locked);
        $this->assertSame($country->id, $base->country->id);
        $this->assertSame($writing->id, $base->language->id);
        $this->assertSame($base->id, Hilos::$db->countryNames->findBase($country->id, $writing->id)?->id);
        $this->assertTrue($base->actions->refreshFromCatalog('Britain'));
        $this->assertSame('Britain', $base->name);
        $base->actions->edit('');
        $this->assertTrue($base->locked);
        $this->assertSame('', $base->name);
        $this->assertFalse($base->actions->refreshFromCatalog('Ignored'));
        $this->assertSame('', $base->name);

        $override = Hilos::$db->countryNames->actions->createManual($country, $writing, $locale, 'UK');
        $this->assertTrue($override->locked);
        $this->assertSame($locale->id, $override->locale?->id);
        $this->assertSame(
            $override->id,
            Hilos::$db->countryNames->findOverride($country->id, $writing->id, $locale->id)?->id,
        );
        try {
            $override->actions->refreshFromCatalog('No');
            $this->fail('Catalog refresh of an override must be refused');
        } catch (ValidationException) {
            $this->assertSame('UK', $override->name);
        }
        $base->actions->delete();
        $this->assertNull(Hilos::$db->countryNames[$override->id]);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testNameActionsRejectMissingBaseForeignLocaleAndWideName(): void
    {
        $named = Hilos::$db->languages->actions->create('fr', 'Français', false);
        $writing = Hilos::$db->languages->actions->create('en', 'English', false);
        $foreign = Hilos::$db->languages->actions->create('de', 'Deutsch', false);
        $foreignLocale = self::createLocale($foreign, null);
        $ownLocale = self::createLocale($writing, null);
        $country = Hilos::$db->countries->actions->create('gb', '£', 'GBP');

        foreach ([HilosDbContext::languageNames, HilosDbContext::countryNames] as $key) {
            $subject = $key === HilosDbContext::languageNames ? $named : $country;
            try {
                Hilos::$db->$key->actions->createManual($subject, $writing, $ownLocale, 'Orphan');
                $this->fail('An override without a base must be refused');
            } catch (ValidationException) {
                $this->assertNull(Hilos::$db->$key->findBase($subject->id, $writing->id));
            }
            $base = Hilos::$db->$key->actions->createManual($subject, $writing, null, 'Base');
            try {
                Hilos::$db->$key->actions->createManual($subject, $writing, $foreignLocale, 'Wrong language');
                $this->fail('A locale of another writing language must be refused');
            } catch (ValidationException) {
                $this->assertSame('Base', $base->name);
            }
            try {
                Hilos::$db->$key->actions->createManual($subject, $writing, null, str_repeat('x', 256));
                $this->fail('A name exceeding VARCHAR(255) must be refused');
            } catch (ValidationException) {
                $this->assertSame('Base', $base->name);
            }
        }
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testGeneratedSlotRefusesRawDuplicateBaseAndOverride(): void
    {
        $language = Hilos::$db->languages->actions->create('en', 'English', false);
        $country = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        $locale = self::createLocale($language, $country);
        Hilos::$db->languageNames->actions->createManual($language, $language, null, 'English');
        Hilos::$db->languageNames->actions->createManual($language, $language, $locale, 'English GB');
        Hilos::$db->countryNames->actions->createManual($country, $language, null, 'United Kingdom');
        Hilos::$db->countryNames->actions->createManual($country, $language, $locale, 'Britain');

        foreach ([
            ['hilos_language_name', $language->id, $language->id, 'in_language_id'],
            ['hilos_country_name', $country->id, $language->id, 'language_id'],
        ] as [$table, $subjectId, $writingId, $writingColumn]) {
            $subjectColumn = $table === 'hilos_language_name' ? 'language_id' : 'country_id';
            foreach ([null, $locale->id] as $localeId) {
                try {
                    Database::sqlRun(
                        "INSERT INTO `{$table}` (`{$subjectColumn}`, `{$writingColumn}`, `locale_id`, `name`)"
                            . ' VALUES (?, ?, ?, ?)',
                        [$subjectId, $writingId, $localeId, 'Duplicate'],
                    );
                    $this->fail('The generated slot must reject a duplicate, including a null base slot');
                } catch (DuplicateEntryException) {
                    $this->assertNotNull($localeId === null
                        ? ($table === 'hilos_language_name'
                            ? Hilos::$db->languageNames->findBase($subjectId, $writingId)
                            : Hilos::$db->countryNames->findBase($subjectId, $writingId))
                        : ($table === 'hilos_language_name'
                            ? Hilos::$db->languageNames->findOverride($subjectId, $writingId, $localeId)
                            : Hilos::$db->countryNames->findOverride($subjectId, $writingId, $localeId)));
                }
            }
        }
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testNamesProtectParentsAndOtherAgentsCannotWrite(): void
    {
        $language = Hilos::$db->languages->actions->create('en', 'English', false);
        $country = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        $locale = self::createLocale($language, $country);
        $languageName = Hilos::$db->languageNames->actions->createManual($language, $language, null, 'English');
        $countryName = Hilos::$db->countryNames->actions->createManual($country, $language, null, 'Britain');
        Hilos::$db->countryNames->actions->createManual($country, $language, $locale, 'UK');

        foreach ([
            ['hilos_language', $language->id], ['hilos_country', $country->id], ['hilos_locale', $locale->id],
        ] as [$table, $id]) {
            try {
                Database::sqlRun("DELETE FROM `{$table}` WHERE `id` = ?", [$id]);
                $this->fail('A name must protect its referenced parent');
            } catch (ForeignKeyConstraintException) {
                $this->assertNotNull(Hilos::$db->languageNames[$languageName->id]);
            }
        }

        ExecutionContext::setCurrentAgentId('test-agent:outsider');
        try {
            Hilos::$db->languageNames->actions->createManual($language, $language, null, 'No');
            $this->fail('A foreign agent must not create a name');
        } catch (CreateNotAllowedException) {
            $this->assertSame('English', $languageName->name);
        } finally {
            ExecutionContext::setCurrentAgentId(self::OWNER_AGENT_ID);
        }
        ExecutionContext::setCurrentAgentId('test-agent:outsider');
        try {
            $countryName->actions->edit('No');
            $this->fail('A foreign agent must not edit a name');
        } catch (WriteNotAllowedException) {
            $this->assertSame('Britain', $countryName->name);
        } finally {
            ExecutionContext::setCurrentAgentId(self::OWNER_AGENT_ID);
        }
        ExecutionContext::setCurrentAgentId('test-agent:outsider');
        try {
            $languageName->actions->delete();
            $this->fail('A foreign agent must not delete a name');
        } catch (WriteNotAllowedException) {
            $this->assertSame('English', $languageName->name);
        } finally {
            ExecutionContext::setCurrentAgentId(self::OWNER_AGENT_ID);
        }
        $this->assertSame('Britain', $countryName->name);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testFailedBaseDeletionRestoresOverridesAndCachedRows(): void
    {
        $language = Hilos::$db->languages->actions->create('en', 'English', false);
        $country = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        $locale = self::createLocale($language, $country);
        $base = Hilos::$db->countryNames->actions->createManual($country, $language, null, 'Britain');
        $override = Hilos::$db->countryNames->actions->createManual($country, $language, $locale, 'UK');

        Database::sqlRun('DROP TABLE IF EXISTS `test_hil_1468_name_blocker`');
        Database::sqlRun('CREATE TABLE `test_hil_1468_name_blocker` ('
            . ' `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . ' `name_id` INT UNSIGNED NOT NULL,'
            . ' CONSTRAINT `fk_test_hil_1468_name_blocker` FOREIGN KEY (`name_id`) REFERENCES `hilos_country_name` (`id`)'
            . ') ENGINE=InnoDB');
        try {
            Database::sqlRun('INSERT INTO `test_hil_1468_name_blocker` (`name_id`) VALUES (?)', [$base->id]);
            try {
                $base->actions->delete();
                $this->fail('A restricted base deletion must roll back its override deletion');
            } catch (ForeignKeyConstraintException) {
                $this->assertSame($base->id, Hilos::$db->countryNames[$base->id]?->id);
                $this->assertSame($override->id, Hilos::$db->countryNames[$override->id]?->id);
                $this->assertSame($override->id, Hilos::$db->countryNames->findOverride(
                    $country->id, $language->id, $locale->id,
                )?->id);
            }
        } finally {
            Database::sqlRun('DROP TABLE IF EXISTS `test_hil_1468_name_blocker`');
        }
    }

    /** @throws HilosException When a PII verdict or live schema read fails */
    public function testEveryNameColumnHasANonpersonalVerdict(): void
    {
        $registry = PiiRegistry::collect();
        $index = DatabaseConnectionDefaults::PRIMARY_INDEX;
        $schemas = LiveSchemaReader::read($index);
        AnonymizationCoverageValidator::validateLiveSchema($registry, [
            $index => [
                'hilos_language_name' => $schemas['hilos_language_name'],
                'hilos_country_name' => $schemas['hilos_country_name'],
            ],
        ]);
        foreach (['hilos_language_name', 'hilos_country_name'] as $table) {
            $this->assertSame([], $registry->strategiesFor($index, $table));
            $this->assertContains('locale_slot', $registry->notPersonalColumns($index, $table));
        }
    }

    /** @throws HilosException When the catalog restore or a fixture write fails */
    public function testCountryNameRestoresCatalogUnlockedAndRemovesAllOverrides(): void
    {
        $language = Hilos::$db->languages->actions->create('en', 'English', false);
        $country = Hilos::$db->countries->actions->create('us', '$', 'USD');
        $gb = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        $gbLocale = self::createLocale($language, $gb);
        $usLocale = self::createLocale($language, $country);
        $base = Hilos::$db->countryNames->actions->createCatalogBase($country, $language, 'Old catalog value');
        $base->actions->edit('');
        $gbName = Hilos::$db->countryNames->actions->createManual($country, $language, $gbLocale, 'British correction');
        $usName = Hilos::$db->countryNames->actions->createManual($country, $language, $usLocale, '');
        $oldIds = [$base->id, $gbName->id, $usName->id];
        foreach ([$language, $country, $gbLocale, $usLocale] as $parent) {
            $parent->actions->switchOn();
        }
        $base->actions->deleteAndRestoreCatalog();

        $restored = Hilos::$db->countryNames->findBase($country->id, $language->id);
        self::assertNotNull($restored);
        self::assertSame(BuiltInI18nCatalog::countryName('us', 'en'), $restored->name);
        self::assertFalse($restored->locked);
        self::assertSame([], Hilos::$db->countryNames->overridesFor($country->id, $language->id));
        foreach ($oldIds as $id) {
            self::assertNull(Hilos::$db->countryNames[$id]);
        }
        foreach ([$language, $country, $gbLocale, $usLocale] as $parent) {
            self::assertTrue($parent->enabled);
        }
    }

    /** @return iterable<string, array{string, string}> Pairs without a catalog name */
    public static function ownNameCoordinates(): iterable
    {
        yield 'own country' => ['qj', 'en'];
        yield 'own writing language' => ['us', 'qk'];
        yield 'both own' => ['qj', 'qk'];
    }

    /**
     * @param string $countryCode Country absent from the catalog, or us
     * @param string $languageCode Writing language absent from the catalog, or en
     * @throws HilosException When a fixture write or deletion fails
     */
    #[DataProvider('ownNameCoordinates')]
    public function testCountryNameWithoutCatalogValueDisappears(string $countryCode, string $languageCode): void
    {
        $country = Hilos::$db->countries->actions->create($countryCode, '$', 'USD');
        $language = Hilos::$db->languages->actions->create($languageCode, 'Writing', false);
        $locale = self::createLocale($language, $country);
        $base = Hilos::$db->countryNames->actions->createManual($country, $language, null, 'Own base');
        Hilos::$db->countryNames->actions->createManual($country, $language, $locale, 'Own correction');
        $base->actions->deleteAndRestoreCatalog();
        self::assertNull(Hilos::$db->countryNames->findBase($country->id, $language->id));
        self::assertSame([], Hilos::$db->countryNames->overridesFor($country->id, $language->id));
    }

    /** @throws HilosException When fixture setup or an ordinary deletion fails */
    public function testOrdinaryCountryNameDeletionNeverRestoresBaseOrCorrection(): void
    {
        $country = Hilos::$db->countries->actions->create('us', '$', 'USD');
        $language = Hilos::$db->languages->actions->create('en', 'English', false);
        $locale = self::createLocale($language, $country);
        $base = Hilos::$db->countryNames->actions->createManual($country, $language, null, 'Own base');
        $correction = Hilos::$db->countryNames->actions->createManual($country, $language, $locale, '');
        $correction->actions->delete();
        self::assertNull(Hilos::$db->countryNames->findOverride($country->id, $language->id, $locale->id));
        self::assertSame($base->getObject(), Hilos::$db->countryNames->findBase($country->id, $language->id)->getObject());
        $base->actions->delete();
        self::assertNull(Hilos::$db->countryNames->findBase($country->id, $language->id));
    }

    /** @throws HilosException When setup fails or the expected correction refusal is raised */
    public function testCountryNameRestoreDoorRefusesAnOverride(): void
    {
        $country = Hilos::$db->countries->actions->create('us', '$', 'USD');
        $language = Hilos::$db->languages->actions->create('en', 'English', false);
        $locale = self::createLocale($language, $country);
        Hilos::$db->countryNames->actions->createManual($country, $language, null, 'Own base');
        $correction = Hilos::$db->countryNames->actions->createManual($country, $language, $locale, '');
        $before = $this->countryNameRows();
        try {
            $correction->actions->deleteAndRestoreCatalog();
            self::fail('Only a base may ask for catalog restoration');
        } catch (ValidationException) {
            self::assertSame($before, $this->countryNameRows());
        }
    }

    /** @return iterable<string, array{string, bool}> Failures before and after the cascade finishes */
    public static function nameRemovalFailures(): iterable
    {
        yield 'known base blocked by foreign key' => ['us', false];
        yield 'own base blocked by foreign key' => ['qj', false];
        yield 'known base insert refused after cascade' => ['us', true];
    }

    /**
     * SQL is only the failure fixture and an independent persisted-row oracle. The write uses the item door.
     * The same objects remain usable after rollback, and neither source reactions nor DB-sync frames escape.
     *
     * @param string $countryCode Named country
     * @param bool $insertFailure Fail restoration rather than the base deletion
     * @throws HilosException When fixture setup, rollback inspection or cleanup fails
     */
    #[DataProvider('nameRemovalFailures')]
    public function testFailedCountryNameRemovalRestoresRowsCachesAndAnnouncements(string $countryCode, bool $insertFailure): void
    {
        $country = Hilos::$db->countries->actions->create($countryCode, '$', 'USD');
        $language = Hilos::$db->languages->actions->create('en', 'English', false);
        $gb = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        $gbLocale = self::createLocale($language, $gb);
        $ownLocale = self::createLocale($language, $country);
        $base = Hilos::$db->countryNames->actions->createManual($country, $language, null, 'Saved base');
        $gbName = Hilos::$db->countryNames->actions->createManual($country, $language, $gbLocale, 'Saved correction');
        $ownName = Hilos::$db->countryNames->actions->createManual($country, $language, $ownLocale, '');
        $before = $this->countryNameRows();
        $beforeChanges = $this->changes->count;
        $previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        try {
            if ($insertFailure) {
                Database::sqlRun('CREATE TRIGGER `test_hil_1501_restore_failure` BEFORE INSERT ON `hilos_country_name` '
                    . "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced country name restore refusal'");
            } else {
                Database::sqlRun('CREATE TABLE `test_hil_1501_name_blocker` ('
                    . ' `name_id` INT UNSIGNED NOT NULL PRIMARY KEY,'
                    . ' FOREIGN KEY (`name_id`) REFERENCES `hilos_country_name` (`id`)) ENGINE=InnoDB');
                Database::sqlRun('INSERT INTO `test_hil_1501_name_blocker` (`name_id`) VALUES (?)', [$base->id]);
            }
            try {
                $base->actions->deleteAndRestoreCatalog();
                self::fail('The failure fixture must refuse the write');
            } catch (DatabaseException $failure) {
                if ($insertFailure) {
                    self::assertStringContainsString('forced country name restore refusal', $failure->getMessage());
                } else {
                    self::assertInstanceOf(ForeignKeyConstraintException::class, $failure);
                }
            }
            self::assertSame($before, $this->countryNameRows());
            self::assertSame($beforeChanges, $this->changes->count);
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
            foreach ([$base, $gbName, $ownName] as $name) {
                self::assertSame($name->getObject(), Hilos::$db->countryNames[$name->id]->getObject());
                self::assertTrue($name->locked);
            }
            self::assertSame('Saved base', $base->name);
            self::assertSame('Saved correction', $gbName->name);
            self::assertSame('', $ownName->name);
            self::assertSame($base->id, Hilos::$db->countryNames->findBase($country->id, $language->id)->id);
            self::assertCount(2, Hilos::$db->countryNames->overridesFor($country->id, $language->id));
        } finally {
            Database::sqlRun('DROP TRIGGER IF EXISTS `test_hil_1501_restore_failure`');
            Database::sqlRun('DROP TABLE IF EXISTS `test_hil_1501_name_blocker`');
            Hilos::$sr = $previousRouter;
        }
        $base->actions->deleteAndRestoreCatalog();
        self::assertSame(BuiltInI18nCatalog::countryName($countryCode, 'en'),
            Hilos::$db->countryNames->findBase($country->id, $language->id)?->name);
        self::assertSame([], Hilos::$db->countryNames->overridesFor($country->id, $language->id));
    }

    /** @return iterable<string, array{bool}> Both locale address shapes */
    public static function localeAddressShapes(): iterable
    {
        yield 'countryless' => [false];
        yield 'pair' => [true];
    }

    /**
     * @param bool $regional Whether the locale has a country
     * @throws HilosException When fixture creation or deletion fails
     */
    #[DataProvider('localeAddressShapes')]
    public function testLocaleDeletionEvictsBothCachesAndAnnouncesOnlyItsRemoval(bool $regional): void
    {
        $language = Hilos::$db->languages->actions->create('en', 'English', false);
        $country = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        $locale = self::createLocale($language, $regional ? $country : null);
        $other = self::createLocale($language, $regional ? null : $country);
        $languageBase = Hilos::$db->languageNames->actions->createManual($language, $language, null, '');
        $countryBase = Hilos::$db->countryNames->actions->createManual($country, $language, null, 'Britain');
        $otherName = Hilos::$db->countryNames->actions->createManual($country, $language, $other, 'Other edition');
        $id = $locale->id;
        $code = $locale->code;
        self::assertSame($locale->getObject(), Hilos::$db->locales[$id]->getObject());
        self::assertSame(Hilos::$db->locales[$id], Hilos::$db->locales[$code]);
        self::assertNull($locale->deleteReason());
        $before = $this->changes->count;

        // The caller already holds a transaction, as the existing test-cleanup command does.
        Database::transactionStart();
        try {
            $locale->actions->delete();
            self::assertNull(Hilos::$db->locales[$id]);
            self::assertSame($before, $this->changes->count);
            Database::transactionCommit();
        } catch (HilosException $failure) {
            Database::transactionRollback();
            throw $failure;
        }

        self::assertSame($before + 1, $this->changes->count);
        $change = $this->changes->last;
        self::assertNotNull($change);
        self::assertSame(HilosDbContext::locales, $change->sourceKey);
        self::assertSame((string) $id, $change->sourceId);
        self::assertSame(TableMutationType::Delete, $change->mutationType);
        self::assertNull(Hilos::$db->locales[$id]);
        self::assertNull(Hilos::$db->locales[$code]);
        self::assertNull(Hilos::$db->locales->getObjectCollection()[$id]);
        self::assertSame(0, EntityLocale::count([EntityLocale::id => $id]));
        self::assertSame($other->getObject(), Hilos::$db->locales[$other->id]->getObject());
        self::assertSame($languageBase->getObject(), Hilos::$db->languageNames[$languageBase->id]->getObject());
        self::assertSame($countryBase->getObject(), Hilos::$db->countryNames[$countryBase->id]->getObject());
        self::assertSame($otherName->getObject(), Hilos::$db->countryNames[$otherName->id]->getObject());
        self::assertSame(1, EntityLanguageName::count([EntityLanguageName::id => $languageBase->id]));
        self::assertSame(2, EntityCountryName::count());
    }

    /** @throws HilosException When fixture writes or the deletion rule fail */
    public function testLocaleDeletionReasonsFollowCurrentStateInPriorityOrder(): void
    {
        $language = Hilos::$db->languages->actions->create('en', 'English', false);
        $country = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        $locale = self::createLocale($language, $country);
        Hilos::$db->countryNames->actions->createManual($country, $language, null, 'Britain');
        $override = Hilos::$db->countryNames->actions->createManual($country, $language, $locale, '');
        $country->actions->update('£', 'GBP', $locale->id);
        $locale->actions->switchOn();
        self::assertSame(Locale::DELETE_ENABLED, $locale->deleteReason());
        $this->assertLocaleDeleteRefused($locale, I18nRowFrozenException::class,
            "Locale 'en-GB' is switched on: switch it off before deleting it.");

        $locale->actions->switchOff();
        foreach ([false, true] as $countryEnabled) {
            if ($countryEnabled) {
                $country->actions->switchOn();
            }
            self::assertSame(Locale::DELETE_COUNTRY_DEFAULT, $locale->deleteReason());
            $this->assertLocaleDeleteRefused($locale, ValidationException::class,
                "Locale 'en-GB' is the country's default: choose another locale for the country first.");
        }
        $country->actions->switchOff();
        $country->actions->update('£', 'GBP', null);
        self::assertSame(Locale::DELETE_NAMES, $locale->deleteReason());
        $this->assertLocaleDeleteRefused($locale, ValidationException::class,
            "Locale 'en-GB' has name overrides: remove them before deleting it.");
        $override->actions->delete();
        self::assertNull($locale->deleteReason());
        $locale->actions->delete();
        self::assertNull(Hilos::$db->locales['en-GB']);
    }

    /** @return iterable<string, array{bool, string, bool}> Every referenced name protects the locale */
    public static function localeNameOverrides(): iterable
    {
        foreach ([false, true] as $countryName) {
            foreach (['', 'Correction'] as $name) {
                foreach ([false, true] as $locked) {
                    yield ($countryName ? 'country' : 'language') . ' ' . var_export([$name, $locked], true)
                        => [$countryName, $name, $locked];
                }
            }
        }
    }

    /**
     * @param bool $countryName Which names collection references the locale
     * @param string $name Literal override, including the empty string
     * @param bool $locked Whether the override is locked
     * @throws HilosException When fixture setup, inspection or removal fails
     */
    #[DataProvider('localeNameOverrides')]
    public function testAnyNameOverrideProtectsTheLocaleUntilTheLastOneIsRemoved(bool $countryName, string $name, bool $locked): void
    {
        $language = Hilos::$db->languages->actions->create('en', 'English', false);
        $country = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        $locale = self::createLocale($language, $country);
        $collection = $countryName ? Hilos::$db->countryNames : Hilos::$db->languageNames;
        $subject = $countryName ? $country : $language;
        $base = $collection->actions->createManual($subject, $language, null, 'Base');
        $overrideId = $collection->actions->createManual($subject, $language, $locale, $name)->id;
        if (!$locked) {
            // An unlocked override is a deliberately unusual fixture, with no public unlock door.
            $table = $countryName ? 'hilos_country_name' : 'hilos_language_name';
            Database::sqlRun("UPDATE `{$table}` SET `locked` = 0 WHERE `id` = ?", [$overrideId]);
            $collection->getObjectCollection()->reHydrate();
            $collection->clearCache();
        }
        $override = $collection[$overrideId];
        $baseObject = $collection[$base->id]->getObject();
        self::assertSame(Locale::DELETE_NAMES, $locale->deleteReason());
        $this->assertLocaleDeleteRefused($locale, ValidationException::class,
            "Locale 'en-GB' has name overrides: remove them before deleting it.");
        self::assertSame($override, $collection[$overrideId]);
        self::assertSame($name, $override->name);
        self::assertSame($locked, $override->locked);
        self::assertSame($baseObject, $collection[$base->id]->getObject());

        $override->actions->delete();
        self::assertNull($locale->deleteReason());
        $locale->actions->delete();
        self::assertNull(Hilos::$db->locales['en-GB']);
        self::assertSame($baseObject, $collection[$base->id]->getObject());
        self::assertSame('Base', $collection[$base->id]->name);
    }

    /** @throws HilosException When fixture writes, bridges or query measurement fail */
    public function testLocaleNameBridgesAreTypedFreshAndReadWarmCollectionsWithoutSelects(): void
    {
        $language = Hilos::$db->languages->actions->create('en', 'English', false);
        $country = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        $locale = self::createLocale($language, $country);
        $other = self::createLocale($language, null);
        Hilos::$db->languageNames->actions->createManual($language, $language, null, 'English');
        Hilos::$db->countryNames->actions->createManual($country, $language, null, 'Britain');
        $foreignLanguageName = Hilos::$db->languageNames->actions->createManual($language, $language, $other, 'Other');
        $foreignCountryName = Hilos::$db->countryNames->actions->createManual($country, $language, $other, 'Other');
        self::assertInstanceOf(LanguageNames::class, $locale->languageNames);
        self::assertInstanceOf(CountryNames::class, $locale->countryNames);
        self::assertCount(0, $locale->languageNames);
        self::assertCount(0, $locale->countryNames);
        foreach ([null, PHP_INT_MAX] as $missing) {
            self::assertCount(0, Hilos::$db->languageNames->forLocaleId($missing));
            self::assertCount(0, Hilos::$db->countryNames->forLocaleId($missing));
        }
        $unpersisted = new Locale(ObjectLocale::fromEntity(new EntityLocale()));
        self::assertCount(0, $unpersisted->languageNames);
        self::assertCount(0, $unpersisted->countryNames);

        $languageName = Hilos::$db->languageNames->actions->createManual($language, $language, $locale, '');
        $countryName = Hilos::$db->countryNames->actions->createManual($country, $language, $locale, 'Correction');
        self::assertSame(Hilos::$db->languageNames[$languageName->id], $locale->languageNames[$languageName->id]);
        self::assertSame(Hilos::$db->countryNames[$countryName->id], $locale->countryNames[$countryName->id]);
        self::assertNull($locale->languageNames[$foreignLanguageName->id]);
        self::assertNull($locale->countryNames[$foreignCountryName->id]);
        self::assertSame($country->getObject(), $locale->country->getObject());
        $before = (int) Database::sql("SHOW SESSION STATUS LIKE 'Com_select'")->firstRow()['Value'];
        self::assertCount(1, $locale->languageNames);
        self::assertCount(1, $locale->countryNames);
        self::assertSame(Locale::DELETE_NAMES, $locale->deleteReason());
        self::assertSame($before, (int) Database::sql("SHOW SESSION STATUS LIKE 'Com_select'")->firstRow()['Value']);

        $languageName->actions->delete();
        self::assertCount(0, $locale->languageNames);
        self::assertSame(Locale::DELETE_NAMES, $locale->deleteReason());
        $countryName->actions->delete();
        self::assertCount(0, $locale->countryNames);
        self::assertNull($locale->deleteReason());
        self::assertSame(Hilos::$db->languageNames[$foreignLanguageName->id], $other->languageNames[$foreignLanguageName->id]);
        self::assertSame(Hilos::$db->countryNames[$foreignCountryName->id], $other->countryNames[$foreignCountryName->id]);
    }

    /** @throws HilosException When fixture setup or the ownership check fails */
    public function testLocaleDeletionRequiresTheOwnerBeforeCheckingDomainReasons(): void
    {
        $language = Hilos::$db->languages->actions->create('en', 'English', false);
        $locale = self::createLocale($language, null);
        $locale->actions->switchOn();
        ExecutionContext::setCurrentAgentId('test-agent:outsider');
        $this->expectException(WriteNotAllowedException::class);
        $locale->actions->delete();
    }

    /**
     * SQL instrumentation observes reads only; actions complete before this window starts.
     *
     * @param Country $country Current country item
     * @param array<string, bool> $expected Locale switches keyed by code, in ascending order
     * @param ?string $defaultCode Chosen default, or null
     * @param ?bool $defaultEnabled Chosen default switch, or null
     * @throws HilosException When reading the bridge or counter fails
     */
    private function assertWarmCountryLocales(Country $country, array $expected, ?string $defaultCode, ?bool $defaultEnabled): void
    {
        $before = (int) Database::sql("SHOW SESSION STATUS LIKE 'Com_select'")->firstRow()['Value'];
        for ($read = 0; $read < 2; $read++) {
            $actual = [];
            foreach ($country->locales as $locale) {
                $actual[$locale->code] = $locale->enabled;
            }
            ksort($actual);
            self::assertSame($expected, $actual);
            self::assertCount(count($expected), $country->locales);
            self::assertSame($defaultCode, $country->defaultLocale?->code);
            self::assertSame($defaultEnabled, $country->defaultLocale?->enabled);
        }
        self::assertSame($before, (int) Database::sql("SHOW SESSION STATUS LIKE 'Com_select'")->firstRow()['Value']);
    }

    /**
     * SQL snapshots are an independent persistence oracle; the mutation uses the item door.
     *
     * @param Locale $locale Cached target
     * @param class-string<ValidationException> $exception Expected refusal class
     * @param string $message Exact domain refusal
     * @throws HilosException When the oracle or action fails unexpectedly
     */
    private function assertLocaleDeleteRefused(Locale $locale, string $exception, string $message): void
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = Database::sql("SELECT * FROM `{$table}` ORDER BY `id`")->rows();
        }
        $before = $this->changes->count;
        $object = $locale->getObject();
        $cached = Hilos::$db->locales[$locale->id];
        try {
            $locale->actions->delete();
            self::fail('The locale must remain protected');
        } catch (ValidationException $failure) {
            self::assertSame($exception, $failure::class);
            self::assertSame($message, $failure->getMessage());
        }
        self::assertSame($before, $this->changes->count);
        self::assertSame($cached, Hilos::$db->locales[$locale->id]);
        self::assertSame($cached, Hilos::$db->locales[$locale->code]);
        self::assertSame($object, Hilos::$db->locales->getObjectCollection()[$locale->id]);
        self::assertTrue($object->isRelated());
        foreach (self::TABLES as $table) {
            self::assertSame($rows[$table], Database::sql("SELECT * FROM `{$table}` ORDER BY `id`")->rows());
        }
    }

    /**
     * @return list<array<string, mixed>> Persisted names, independent of the ORM cache
     * @throws DatabaseException When the test oracle cannot read the table
     */
    private function countryNameRows(): array
    {
        Database::sql('SELECT * FROM `hilos_country_name` ORDER BY `id`');
        return Database::rows();
    }

    /**
     * The formats are test data shared by cases concerned with row behavior, not formatting.
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
            'Street, House, City, Index', MeasurementSystem::METRIC, 'und',
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

/** The framework's reference catalogs with no project extension. */
final class I18nReferenceTestDbContext extends HilosDbContext
{
}

/** Counts announcements to prove repeated switches do not write or sync. */
final class I18nChangeRecorder implements SourceChangeSubscriberInterface
{
    public int $count = 0;
    public ?SourceChange $last = null;

    /**
     * @param SourceChange $change Announced reference mutation
     * @param SourceChangeProvenance $provenance Mutation origin (unused)
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->count++;
        $this->last = $change;
    }
}
