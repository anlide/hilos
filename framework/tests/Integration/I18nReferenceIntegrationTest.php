<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Backup\Anonymization\AnonymizationCoverageValidator;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
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
        $this->expectException(DuplicateEntryException::class);
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
        } catch (DuplicateEntryException) {
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
        } catch (DuplicateEntryException) {
            $this->assertSame($locale->id, Hilos::$db->locales['en']?->id);
        }

        $country = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        $regional = self::createLocale($language, $country);
        try {
            self::createLocale($language, $country);
            $this->fail('Expected a second locale of one language-country pair to be refused');
        } catch (DuplicateEntryException) {
            $this->assertSame($regional->id, Hilos::$db->locales['en-GB']?->id);
        }

        try {
            Hilos::$db->locales->actions->create(
                $language, null, '', 'H:i', '#,##0.00', '+00 000', 'Street', MeasurementSystem::METRIC, 'unicode',
            );
            $this->fail('Expected an empty format to be refused');
        } catch (ValidationException) {
            $this->assertSame($locale->id, Hilos::$db->locales['en']?->id);
        }

        $locale->actions->switchOn();
        try {
            $locale->actions->update('d/m/Y', 'H:i', '#,##0.00', '+00 000', 'Street', MeasurementSystem::IMPERIAL, 'unicode');
            $this->fail('Expected a switched-on locale to refuse an edit');
        } catch (I18nRowFrozenException) {
            $this->assertSame('Y-m-d', $locale->dateFormat);
        }
        Database::sql('SELECT `date_format` FROM `hilos_locale` WHERE `id` = ?', [$locale->id]);
        $this->assertSame('Y-m-d', Database::row()['date_format']);

        $locale->actions->switchOff();
        try {
            $locale->actions->update('', 'H:i', '#,##0.00', '+00 000', 'Street', MeasurementSystem::IMPERIAL, 'unicode');
            $this->fail('Expected an empty updated format to be refused');
        } catch (ValidationException) {
            $this->assertSame('Y-m-d', $locale->dateFormat);
        }
        $locale->actions->update('d/m/Y', 'H:i', '#,##0.00', '+00 000', 'Street', MeasurementSystem::IMPERIAL, 'unicode');
        $locale->actions->switchOn();
        $this->assertSame('d/m/Y', $locale->dateFormat);
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
        $country = Hilos::$db->countries->actions->create('gb', '£', 'GBP');
        $locale = self::createLocale($language, $country);
        $country->actions->update('£', 'GBP', $locale->id);

        try {
            $locale->actions->delete();
            $this->fail('Expected a default locale to be protected by the country key');
        } catch (ForeignKeyConstraintException) {
            $this->assertNotNull(Hilos::$db->locales[$locale->id]);
        }
        try {
            $language->actions->delete();
            $this->fail('Expected a language with a locale to be protected');
        } catch (ForeignKeyConstraintException) {
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
            $language, $country, 'Y-m-d', 'H:i', '#,##0.00', '+00 000', 'Street', MeasurementSystem::METRIC, 'unicode',
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

    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->count++;
    }
}
