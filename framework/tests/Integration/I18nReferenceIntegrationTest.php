<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
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
    private const array TABLES = ['hilos_language', 'hilos_country', 'hilos_locale'];
    private const string OWNER_AGENT_ID = 'test-agent:i18n-library';

    private ?DbContext $previousDb = null;
    private I18nChangeRecorder $changes;

    /**
     * @throws DatabaseException When a migration stub or schema refresh fails
     * @throws HilosException When the context cannot be configured
     */
    protected function setUp(): void
    {
        parent::setUp();
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
