<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\View\Item\Country;
use Hilos\Database\View\Item\Language;
use Hilos\Database\View\Item\Locale;
use Hilos\HilosException;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\MeasurementSystem;

/**
 * The library takes the built-in catalog in on start when its fingerprint changed, and on the
 * same fingerprint writes nothing at all (HIL-1472).
 */
final class I18nCatalogReflowTest extends IntegrationTestCase
{
    private const string FAILURE_TRIGGER = 'hil_1472_fail_country_name_insert';
    private const int CATALOG_COUNTRIES = 51;

    private I18nLibraryAgent $agent;
    private string|false $previousDefaultLanguage;
    private I18nReflowWriteRecorder $writes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDefaultLanguage = getenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=en');
        $this->agent = new I18nLibraryAgent();
        $this->clearFixtures();
        $this->writes = new I18nReflowWriteRecorder();
        SourceChangeBus::subscribe($this->writes);
    }

    protected function tearDown(): void
    {
        // The bus has no unsubscribe, and the facade is initialized once per process.
        $this->writes->listening = false;
        Database::sqlRun('DROP TRIGGER IF EXISTS `' . self::FAILURE_TRIGGER . '`');
        $this->clearFixtures();
        TruthSourceRegistry::unregisterAgent($this->agent->getId());
        if ($this->previousDefaultLanguage === false) {
            putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        } else {
            putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=' . $this->previousDefaultLanguage);
        }
        parent::tearDown();
    }

    public function testFirstStartTakesInSwitchedOffCountriesWithNamesInTheDefaultLanguage(): void
    {
        $this->startLibrary();

        $english = Hilos::$db->languages['en'];
        self::assertNotNull($english);
        self::assertSame(self::CATALOG_COUNTRIES, $this->rows('SELECT COUNT(*) AS `count` FROM `hilos_country`'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) AS `count` FROM `hilos_country` WHERE `enabled` = 1'));
        self::assertSame(
            0,
            $this->rows('SELECT COUNT(*) AS `count` FROM `hilos_country` WHERE `default_locale_id` IS NOT NULL'),
        );
        self::assertSame(self::CATALOG_COUNTRIES, $this->namesIn('en'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) AS `count` FROM `hilos_country_name` WHERE `locked` = 1'));
        $us = Hilos::$db->countries['us'];
        self::assertNotNull($us);
        self::assertFalse($us->enabled);
        self::assertSame('$', $us->currencySymbol);
        self::assertSame('USD', $us->currencyCode);
        self::assertNull($us->defaultLocaleId);
        self::assertSame('United States', Hilos::$db->countryNames->findBase((int)$us->id, (int)$english->id)?->name);
        self::assertSame(BuiltInI18nCatalog::fingerprint(), Hilos::$db->i18nReflows->recordedFingerprint());
    }

    public function testSecondStartWithTheSameFingerprintWritesNothing(): void
    {
        $this->startLibrary();
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->countries['de']->actions->update('$', 'USD', null);
        });

        $this->writes->count = 0;
        $this->startLibrary();

        self::assertSame(0, $this->writes->count);
        self::assertSame('$', Hilos::$db->countries['de']?->currencySymbol);
        self::assertSame('USD', Hilos::$db->countries['de']?->currencyCode);
        self::assertSame(self::CATALOG_COUNTRIES, $this->namesIn('en'));
    }

    public function testNewFingerprintRewritesOnlyWhatIsSwitchedOffAndUnlocked(): void
    {
        $this->startLibrary();
        $ids = $this->underAgent($this->agent, static function (): array {
            $english = Hilos::$db->languages['en'];
            Hilos::$db->countries['de']->actions->update('$', 'USD', null);
            $france = Hilos::$db->countries['fr'];
            $france->actions->update('$', 'USD', null);
            $france->actions->switchOn();
            $own = Hilos::$db->countries->actions->create('zz', 'Z', 'ZZZ');

            $spanish = Hilos::$db->languages->actions->create('es', 'Spanish', true);
            $arabic = Hilos::$db->languages->actions->create('ar', 'Arabic', false);
            $arabic->actions->switchOn();
            $ownLanguage = Hilos::$db->languages->actions->create('xx', 'Own', false);

            self::createLocale($spanish, null);
            $british = self::createLocale($english, Hilos::$db->countries['gb']);
            $british->actions->switchOn();

            $germany = Hilos::$db->countries['de'];
            Hilos::$db->countryNames->findBase($germany->id, $english->id)->actions->edit('Deutschland');
            $override = Hilos::$db->countryNames->actions->createManual(Hilos::$db->countries['gb'], $english, $british, 'UK');
            $us = Hilos::$db->countries['us'];
            Hilos::$db->countryNames->findBase($us->id, $english->id)->actions->delete();
            Hilos::$db->i18nReflows->actions->record(str_repeat('0', 64));

            return ['override' => $override->id, 'ownCountry' => $own->id, 'ownLanguage' => $ownLanguage->id];
        });

        $this->startLibrary();

        self::assertSame('€', Hilos::$db->countries['de']?->currencySymbol);
        self::assertSame('EUR', Hilos::$db->countries['de']?->currencyCode);
        self::assertTrue(Hilos::$db->countries['fr']?->enabled);
        self::assertSame('$', Hilos::$db->countries['fr']?->currencySymbol);
        self::assertSame('USD', Hilos::$db->countries['fr']?->currencyCode);
        self::assertSame('Z', Hilos::$db->countries['zz']?->currencySymbol);
        self::assertSame('ZZZ', Hilos::$db->countries['zz']?->currencyCode);

        self::assertSame('Español', Hilos::$db->languages['es']?->nativeName);
        self::assertFalse(Hilos::$db->languages['es']?->rtl);
        self::assertSame('Arabic', Hilos::$db->languages['ar']?->nativeName);
        self::assertSame('Own', Hilos::$db->languages['xx']?->nativeName);
        self::assertNull(Hilos::$db->languages['fr']);

        $catalogSpanish = BuiltInI18nCatalog::locale('es');
        self::assertNotNull($catalogSpanish);
        self::assertSame($catalogSpanish->dateFormat, Hilos::$db->locales['es']?->dateFormat);
        self::assertSame($catalogSpanish->addressFormat, Hilos::$db->locales['es']?->addressFormat);
        self::assertSame($catalogSpanish->measurementSystem, Hilos::$db->locales['es']?->measurementSystem->value);
        self::assertSame('YYYY-MM-DD', Hilos::$db->locales['en-GB']?->dateFormat);
        self::assertNull(Hilos::$db->locales['en-US']);
        self::assertNull(Hilos::$db->locales['ar']);
        self::assertSame(3, $this->rows('SELECT COUNT(*) AS `count` FROM `hilos_locale`'));

        $english = Hilos::$db->languages['en'];
        self::assertNotNull($english);
        $germanyName = Hilos::$db->countryNames->findBase((int)Hilos::$db->countries['de']?->id, (int)$english->id);
        self::assertSame('Deutschland', $germanyName?->name);
        self::assertTrue($germanyName?->locked);
        self::assertSame('UK', Hilos::$db->countryNames[$ids['override']]?->name);
        $usName = Hilos::$db->countryNames->findBase((int)Hilos::$db->countries['us']?->id, (int)$english->id);
        self::assertSame('United States', $usName?->name);
        self::assertFalse($usName?->locked);
        self::assertSame(self::CATALOG_COUNTRIES, $this->namesIn('es'));
        self::assertSame(self::CATALOG_COUNTRIES, $this->namesIn('ar'));
        self::assertSame(0, $this->namesIn('xx'));
        self::assertSame(
            0,
            $this->rows('SELECT COUNT(*) AS `count` FROM `hilos_country_name` WHERE `country_id` = ?', [$ids['ownCountry']]),
        );
        self::assertSame(BuiltInI18nCatalog::fingerprint(), Hilos::$db->i18nReflows->recordedFingerprint());
    }

    public function testFailedReflowRollsBackWholeAndKeepsTheDefaultLanguage(): void
    {
        Database::sqlRun(
            'CREATE TRIGGER `' . self::FAILURE_TRIGGER . '` BEFORE INSERT ON `hilos_country_name` '
            . "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced country name refusal'",
        );
        try {
            $this->startLibrary();
            self::fail('The country name insert was expected to fail');
        } catch (HilosException $failure) {
            self::assertStringContainsString('forced country name refusal', $failure->getMessage());
        }

        self::assertSame(0, $this->rows('SELECT COUNT(*) AS `count` FROM `hilos_country`'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) AS `count` FROM `hilos_i18n_reflow`'));
        self::assertNull(Hilos::$db->countries['us']);
        self::assertNull(Hilos::$db->i18nReflows->recordedFingerprint());
        self::assertTrue(Hilos::$db->languages['en']?->enabled);
        self::assertTrue(Hilos::$db->locales['en']?->enabled);

        Database::sqlRun('DROP TRIGGER IF EXISTS `' . self::FAILURE_TRIGGER . '`');
        $this->startLibrary();

        self::assertSame(self::CATALOG_COUNTRIES, $this->rows('SELECT COUNT(*) AS `count` FROM `hilos_country`'));
        self::assertSame(self::CATALOG_COUNTRIES, $this->namesIn('en'));
        self::assertSame(BuiltInI18nCatalog::fingerprint(), Hilos::$db->i18nReflows->recordedFingerprint());
    }

    public function testDefaultLanguageCreatedOnALaterStartGetsTheCatalogNames(): void
    {
        $this->startLibrary();
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=ru');

        $this->startLibrary();

        $russian = Hilos::$db->languages['ru'];
        self::assertNotNull($russian);
        self::assertTrue($russian->enabled);
        self::assertSame(self::CATALOG_COUNTRIES, $this->namesIn('ru'));
        $us = Hilos::$db->countries['us'];
        self::assertSame(
            BuiltInI18nCatalog::countryName('us', 'ru'),
            Hilos::$db->countryNames->findBase((int)$us?->id, (int)$russian->id)?->name,
        );
        self::assertSame(BuiltInI18nCatalog::fingerprint(), Hilos::$db->i18nReflows->recordedFingerprint());
    }

    private function startLibrary(): void
    {
        $this->underAgent($this->agent, fn (): mixed => $this->startAgent($this->agent));
    }

    /**
     * @param Language $language Locale's language
     * @param ?Country $country Its country, or null for a countryless locale
     * @return Locale New switched-off locale with formats no catalog locale has
     */
    private static function createLocale(Language $language, ?Country $country): Locale
    {
        return Hilos::$db->locales->actions->create(
            $language, $country, 'YYYY-MM-DD', 'HH:mm:ss', '1,000.00', '+XX-XXXX-XXXX',
            'Street, House, City, Index', MeasurementSystem::IMPERIAL, 'und',
        );
    }

    private function namesIn(string $languageCode): int
    {
        return $this->rows(
            'SELECT COUNT(*) AS `count` FROM `hilos_country_name` `n`'
                . ' JOIN `hilos_language` `l` ON `l`.`id` = `n`.`language_id`'
                . ' WHERE `l`.`code` = ? AND `n`.`locale_id` IS NULL',
            [$languageCode],
        );
    }

    /** @param list<int|string> $params Statement parameters */
    private function rows(string $sql, array $params = []): int
    {
        Database::sql($sql, $params);
        return (int)Database::field('count');
    }

    /** The reference tables are emptied whole: a start takes the whole catalog in. */
    private function clearFixtures(): void
    {
        Database::sqlRun('UPDATE `hilos_country` SET `default_locale_id` = NULL');
        Database::sqlRun('DELETE FROM `hilos_country_name`');
        Database::sqlRun('DELETE FROM `hilos_language_name`');
        Database::sqlRun('DELETE FROM `hilos_locale`');
        Database::sqlRun('DELETE FROM `hilos_country`');
        Database::sqlRun('DELETE FROM `hilos_i18n_reflow`');
        Database::sqlRun('DELETE FROM `hilos_language`');
        foreach ([
            Hilos::$db->countryNames,
            Hilos::$db->languageNames,
            Hilos::$db->locales,
            Hilos::$db->countries,
            Hilos::$db->i18nReflows,
            Hilos::$db->languages,
        ] as $collection) {
            $collection->getObjectCollection()?->reHydrate();
            $collection->clearCache();
        }
    }
}

/** Counts the writes the i18n library announces while a case listens. */
final class I18nReflowWriteRecorder implements SourceChangeSubscriberInterface
{
    private const array KEYS = [
        HilosDbContext::languages,
        HilosDbContext::countries,
        HilosDbContext::locales,
        HilosDbContext::languageNames,
        HilosDbContext::countryNames,
        HilosDbContext::i18nReflows,
    ];

    public bool $listening = true;
    public int $count = 0;

    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($this->listening && in_array($change->sourceKey, self::KEYS, true)) {
            $this->count++;
        }
    }
}
