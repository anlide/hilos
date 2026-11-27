<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\Country;
use Hilos\Database\View\Item\Language;
use Hilos\Database\View\Item\Locale;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\I18n\BrowserLanguages;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\DTO\LocaleFormats;
use Hilos\I18n\DTO\TabLanguage;
use Hilos\I18n\MeasurementSystem;
use Hilos\I18n\TabLanguageLadder;

/** The two ladders of a tab's language and region over a seeded reference. */
final class TabLanguageLadderIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = ['hilos_language', 'hilos_country', 'hilos_locale'];
    private const string OWNER_AGENT_ID = 'test-agent:tab-language';

    private ?DbContext $previousDb = null;
    private string|false $previousDefaultLanguage;

    /**
     * @throws DatabaseException When a migration stub or schema refresh fails
     * @throws HilosException When the context cannot be configured
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDefaultLanguage = getenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=es');
        self::runStubs(down: true);
        self::runStubs(down: false);
        Schema::reset();
        Schema::initialize();

        $this->previousDb = Hilos::$db;
        $db = new TabLanguageLadderTestDbContext();
        $db->configure();
        Hilos::$db = $db;

        TruthSourceRegistry::register(HilosDbContext::languages, TruthSourceKeys::all(), self::OWNER_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::countries, TruthSourceKeys::all(), self::OWNER_AGENT_ID);
        TruthSourceRegistry::register(HilosDbContext::locales, TruthSourceKeys::all(), self::OWNER_AGENT_ID);
        ExecutionContext::setCurrentAgentId(self::OWNER_AGENT_ID);
        SourceChangeBus::reset();
    }

    /** @throws DatabaseException When dropping a stub table fails */
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
    public function testWithoutChoiceTakesDefaultLanguageAndItsCountrylessLocale(): void
    {
        $es = self::switchedOnLanguage('es', 'Español', false);
        $esLocale = self::switchedOnLocale($es, null);

        $tab = TabLanguageLadder::resolve(null, null, BrowserLanguages::fromAcceptLanguage(null));

        self::assertTab($tab, $es, $esLocale);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testChosenSwitchedOnLanguageWins(): void
    {
        $es = self::switchedOnLanguage('es', 'Español', false);
        self::switchedOnLocale($es, null);
        $ar = self::switchedOnLanguage('ar', 'العربية', true);
        $arLocale = self::switchedOnLocale($ar, null);

        $tab = TabLanguageLadder::resolve($ar->id, null, BrowserLanguages::fromAcceptLanguage(null));

        self::assertTab($tab, $ar, $arLocale);
        self::assertTrue($tab?->rtl);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testChosenSwitchedOffOrMissingLanguageFallsToDefault(): void
    {
        $es = self::switchedOnLanguage('es', 'Español', false);
        $esLocale = self::switchedOnLocale($es, null);
        $fr = Hilos::$db->languages->actions->create('fr', 'Français', false);
        self::switchedOnLocale($fr, null);

        self::assertTab(TabLanguageLadder::resolve($fr->id, null, BrowserLanguages::fromAcceptLanguage(null)), $es, $esLocale);
        self::assertTab(TabLanguageLadder::resolve($fr->id + 1000, null, BrowserLanguages::fromAcceptLanguage(null)), $es, $esLocale);
        self::assertFalse(Hilos::$db->languages[$fr->id]->enabled, 'The choice is passed over, not touched');
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testAvailableChosenVariantWins(): void
    {
        $es = self::switchedOnLanguage('es', 'Español', false);
        self::switchedOnLocale($es, null);
        $fr = self::switchedOnLanguage('fr', 'Français', false);
        self::switchedOnLocale($fr, null);
        $variant = self::switchedOnLocale($fr, self::switchedOnCountry('fr'));

        $tab = TabLanguageLadder::resolve($fr->id, $variant->id, BrowserLanguages::fromAcceptLanguage(null));

        self::assertTab($tab, $fr, $variant);
        self::assertSame('fr-FR', $tab?->localeCode);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testSwitchedOffChosenVariantFallsToCountrylessLocale(): void
    {
        $es = self::switchedOnLanguage('es', 'Español', false);
        self::switchedOnLocale($es, null);
        $fr = self::switchedOnLanguage('fr', 'Français', false);
        $frLocale = self::switchedOnLocale($fr, null);
        $variant = self::createLocale($fr, self::switchedOnCountry('fr'));

        self::assertTab(TabLanguageLadder::resolve($fr->id, $variant->id, BrowserLanguages::fromAcceptLanguage(null)), $fr, $frLocale);
        self::assertFalse(Hilos::$db->locales[$variant->id]->enabled, 'The choice is passed over, not touched');
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testChosenVariantOfSwitchedOffCountryFallsToCountrylessLocale(): void
    {
        $es = self::switchedOnLanguage('es', 'Español', false);
        self::switchedOnLocale($es, null);
        $fr = self::switchedOnLanguage('fr', 'Français', false);
        $frLocale = self::switchedOnLocale($fr, null);
        $country = Hilos::$db->countries->actions->create('fr', '€', 'EUR');
        $variant = self::switchedOnLocale($fr, $country);

        self::assertFalse(Hilos::$db->locales->isAvailable($variant));
        self::assertTab(TabLanguageLadder::resolve($fr->id, $variant->id, BrowserLanguages::fromAcceptLanguage(null)), $fr, $frLocale);
    }

    /**
     * The decided language is the variant's own only when it is the default language, which the
     * ladder takes switched on or not; here it was never switched on.
     *
     * @throws HilosException When a database or action fails unexpectedly
     */
    public function testChosenVariantOfSwitchedOffLanguageFallsBelow(): void
    {
        $es = Hilos::$db->languages->actions->create('es', 'Español', false);
        $esLocale = self::switchedOnLocale($es, null);
        $variant = self::switchedOnLocale($es, self::switchedOnCountry('es'));

        self::assertFalse(Hilos::$db->locales->isAvailable($variant));
        self::assertFalse(Hilos::$db->locales->isAvailable($esLocale));
        self::assertTab(TabLanguageLadder::resolve(null, $variant->id, BrowserLanguages::fromAcceptLanguage(null)), $es, $esLocale);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testChosenVariantOfSwitchedOffChosenLanguageIsNotTaken(): void
    {
        $es = self::switchedOnLanguage('es', 'Español', false);
        $esLocale = self::switchedOnLocale($es, null);
        $fr = Hilos::$db->languages->actions->create('fr', 'Français', false);
        $variant = self::switchedOnLocale($fr, self::switchedOnCountry('fr'));

        self::assertTab(TabLanguageLadder::resolve($fr->id, $variant->id, BrowserLanguages::fromAcceptLanguage(null)), $es, $esLocale);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testAvailableVariantOfAnotherLanguageIsNotTaken(): void
    {
        $es = self::switchedOnLanguage('es', 'Español', false);
        $esLocale = self::switchedOnLocale($es, null);
        $fr = self::switchedOnLanguage('fr', 'Français', false);
        $variant = self::switchedOnLocale($fr, self::switchedOnCountry('fr'));

        self::assertTrue(Hilos::$db->locales->isAvailable($variant));
        self::assertTab(TabLanguageLadder::resolve($es->id, $variant->id, BrowserLanguages::fromAcceptLanguage(null)), $es, $esLocale);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testSwitchedOffCountrylessLocaleFallsToDefaultCountrylessLocale(): void
    {
        $es = self::switchedOnLanguage('es', 'Español', false);
        $esLocale = self::switchedOnLocale($es, null);
        $fr = self::switchedOnLanguage('fr', 'Français', false);
        self::createLocale($fr, null);

        self::assertTab(TabLanguageLadder::resolve($fr->id, null, BrowserLanguages::fromAcceptLanguage(null)), $fr, $esLocale);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testDefaultLanguageWithoutCountrylessLocaleEndsAtCatalog(): void
    {
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=de');
        $de = self::switchedOnLanguage('de', 'Deutsch', false);
        $fr = self::switchedOnLanguage('fr', 'Français', false);

        $tab = TabLanguageLadder::resolve($fr->id, null, BrowserLanguages::fromAcceptLanguage(null));

        self::assertNotNull($tab);
        self::assertSame($fr->id, $tab->languageId);
        self::assertNull($tab->localeId);
        self::assertNull($tab->localeCode);
        self::assertSame(LocaleFormats::ofCatalog(BuiltInI18nCatalog::locale('de-DE'))->toArray(), $tab->formats->toArray());
        self::assertSame($de->id, TabLanguageLadder::resolve(null, null, BrowserLanguages::fromAcceptLanguage(null))?->languageId);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testSwitchedOffDefaultCountrylessLocaleEndsAtCatalog(): void
    {
        $es = self::switchedOnLanguage('es', 'Español', false);
        self::createLocale($es, null);

        $tab = TabLanguageLadder::resolve(null, null, BrowserLanguages::fromAcceptLanguage(null));

        self::assertNotNull($tab);
        self::assertSame($es->id, $tab->languageId);
        self::assertNull($tab->localeId);
        self::assertNull($tab->localeCode);
        self::assertSame(LocaleFormats::ofCatalog(BuiltInI18nCatalog::locale('es'))->toArray(), $tab->formats->toArray());
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testMissingDefaultLanguageRowGivesNoLanguage(): void
    {
        self::assertNull(TabLanguageLadder::resolve(null, null, BrowserLanguages::fromAcceptLanguage(null)));

        $fr = self::switchedOnLanguage('fr', 'Français', false);
        $frLocale = self::switchedOnLocale($fr, null);

        self::assertNull(TabLanguageLadder::resolve($fr->id, $frLocale->id, BrowserLanguages::fromAcceptLanguage(null)));
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testWithoutChoiceTakesFirstSwitchedOnLanguageByWeight(): void
    {
        $es = self::switchedOnLanguage('es', 'Español', false);
        self::switchedOnLocale($es, null);
        $de = self::switchedOnLanguage('de', 'Deutsch', false);
        self::switchedOnLocale($de, null);
        $fr = self::switchedOnLanguage('fr', 'Français', false);
        $frLocale = self::switchedOnLocale($fr, null);

        $tab = TabLanguageLadder::resolve(null, null, BrowserLanguages::fromAcceptLanguage('de;q=0.5, fr;q=0.9'));

        self::assertTab($tab, $fr, $frLocale);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testSwitchedOffFirstAskedLanguageGivesWayToNext(): void
    {
        $es = self::switchedOnLanguage('es', 'Español', false);
        self::switchedOnLocale($es, null);
        Hilos::$db->languages->actions->create('uk', 'Українська', false);
        $en = self::switchedOnLanguage('en', 'English', false);
        $enLocale = self::switchedOnLocale($en, null);

        self::assertTab(TabLanguageLadder::resolve(null, null, BrowserLanguages::fromAcceptLanguage('uk, en')), $en, $enLocale);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testNothingAskedSwitchedOnTakesDefaultLanguage(): void
    {
        $es = self::switchedOnLanguage('es', 'Español', false);
        $esLocale = self::switchedOnLocale($es, null);
        $fr = Hilos::$db->languages->actions->create('fr', 'Français', false);
        self::switchedOnLocale($fr, null);

        self::assertTab(TabLanguageLadder::resolve(null, null, BrowserLanguages::fromAcceptLanguage('fr, uk')), $es, $esLocale);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testChoiceBeatsBrowser(): void
    {
        $es = self::switchedOnLanguage('es', 'Español', false);
        self::switchedOnLocale($es, null);
        $fr = self::switchedOnLanguage('fr', 'Français', false);
        self::switchedOnLocale($fr, null);
        $ar = self::switchedOnLanguage('ar', 'العربية', true);
        $arLocale = self::switchedOnLocale($ar, null);

        self::assertTab(TabLanguageLadder::resolve($ar->id, null, BrowserLanguages::fromAcceptLanguage('fr')), $ar, $arLocale);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testSwitchedOffChoiceTakesDefaultLanguagePastBrowser(): void
    {
        $es = self::switchedOnLanguage('es', 'Español', false);
        $esLocale = self::switchedOnLocale($es, null);
        $fr = Hilos::$db->languages->actions->create('fr', 'Français', false);
        $de = self::switchedOnLanguage('de', 'Deutsch', false);
        self::switchedOnLocale($de, null);

        self::assertTab(TabLanguageLadder::resolve($fr->id, null, BrowserLanguages::fromAcceptLanguage('de')), $es, $esLocale);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testAvailableBrowserVariantWins(): void
    {
        $es = self::switchedOnLanguage('es', 'Español', false);
        self::switchedOnLocale($es, null);
        $fr = self::switchedOnLanguage('fr', 'Français', false);
        self::switchedOnLocale($fr, null);
        $variant = self::switchedOnLocale($fr, self::switchedOnCountry('fr'));

        $tab = TabLanguageLadder::resolve(null, null, BrowserLanguages::fromAcceptLanguage('fr-FR'));

        self::assertTab($tab, $fr, $variant);
        self::assertSame('fr-FR', $tab?->localeCode);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testBrowserVariantOfSwitchedOffCountryOrLocaleFallsToCountrylessLocale(): void
    {
        $es = self::switchedOnLanguage('es', 'Español', false);
        self::switchedOnLocale($es, null);
        $fr = self::switchedOnLanguage('fr', 'Français', false);
        $frLocale = self::switchedOnLocale($fr, null);
        $ofSwitchedOffCountry = self::switchedOnLocale($fr, Hilos::$db->countries->actions->create('be', '€', 'EUR'));
        $switchedOff = self::createLocale($fr, self::switchedOnCountry('fr'));

        self::assertFalse(Hilos::$db->locales->isAvailable($ofSwitchedOffCountry));
        self::assertFalse(Hilos::$db->locales->isAvailable($switchedOff));
        self::assertTab(TabLanguageLadder::resolve(null, null, BrowserLanguages::fromAcceptLanguage('fr-BE, fr-FR')), $fr, $frLocale);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testBrowserVariantOfAnotherLanguageIsNotTaken(): void
    {
        $es = self::switchedOnLanguage('es', 'Español', false);
        $esLocale = self::switchedOnLocale($es, null);
        $fr = self::switchedOnLanguage('fr', 'Français', false);
        $variant = self::switchedOnLocale($fr, self::switchedOnCountry('fr'));

        self::assertTrue(Hilos::$db->locales->isAvailable($variant));
        self::assertTab(TabLanguageLadder::resolve($es->id, null, BrowserLanguages::fromAcceptLanguage('fr-FR')), $es, $esLocale);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testChosenLanguageTakesBrowserVariantOfIt(): void
    {
        $es = self::switchedOnLanguage('es', 'Español', false);
        self::switchedOnLocale($es, null);
        $ru = self::switchedOnLanguage('ru', 'Русский', false);
        self::switchedOnLocale($ru, null);
        $variant = self::switchedOnLocale($ru, self::switchedOnCountry('ua'));

        $tab = TabLanguageLadder::resolve($ru->id, null, BrowserLanguages::fromAcceptLanguage('ru-UA'));

        self::assertTab($tab, $ru, $variant);
        self::assertSame('ru-UA', $tab?->localeCode);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testDefaultLanguageTakesBrowserVariantOfIt(): void
    {
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=en');
        $en = self::switchedOnLanguage('en', 'English', false);
        self::switchedOnLocale($en, null);
        $variant = self::switchedOnLocale($en, self::switchedOnCountry('gb'));
        $fr = Hilos::$db->languages->actions->create('fr', 'Français', false);

        $tab = TabLanguageLadder::resolve($fr->id, null, BrowserLanguages::fromAcceptLanguage('fr-FR, en-GB'));

        self::assertTab($tab, $en, $variant);
        self::assertSame('en-GB', $tab?->localeCode);
    }

    /** @throws HilosException When a database or action fails unexpectedly */
    public function testChosenVariantBeatsBrowserVariant(): void
    {
        $es = self::switchedOnLanguage('es', 'Español', false);
        self::switchedOnLocale($es, null);
        $fr = self::switchedOnLanguage('fr', 'Français', false);
        self::switchedOnLocale($fr, null);
        $chosen = self::switchedOnLocale($fr, self::switchedOnCountry('fr'));
        self::switchedOnLocale($fr, self::switchedOnCountry('be'));

        self::assertTab(TabLanguageLadder::resolve($fr->id, $chosen->id, BrowserLanguages::fromAcceptLanguage('fr-BE')), $fr, $chosen);
    }

    /**
     * @param ?TabLanguage $tab Ladder's answer
     * @param Language $language Expected language
     * @param Locale $locale Expected stored locale
     */
    private static function assertTab(?TabLanguage $tab, Language $language, Locale $locale): void
    {
        self::assertNotNull($tab);
        self::assertSame($language->id, $tab->languageId);
        self::assertSame($language->code, $tab->languageCode);
        self::assertSame($language->rtl, $tab->rtl);
        self::assertSame($locale->id, $tab->localeId);
        self::assertSame($locale->code, $tab->localeCode);
        self::assertSame(LocaleFormats::ofLocale($locale)->toArray(), $tab->formats->toArray());
    }

    /**
     * @param string $code Language code
     * @param string $nativeName Native name
     * @param bool $rtl Writing direction
     * @return Language Switched-on language
     * @throws HilosException When an action refuses
     */
    private static function switchedOnLanguage(string $code, string $nativeName, bool $rtl): Language
    {
        $language = Hilos::$db->languages->actions->create($code, $nativeName, $rtl);
        $language->actions->switchOn();

        return $language;
    }

    /**
     * @param string $code Country code
     * @return Country Switched-on country
     * @throws HilosException When an action refuses
     */
    private static function switchedOnCountry(string $code): Country
    {
        $country = Hilos::$db->countries->actions->create($code, '€', 'EUR');
        $country->actions->switchOn();

        return $country;
    }

    /**
     * @param Language $language Locale's language
     * @param ?Country $country Its country, or null for the countryless locale
     * @return Locale Switched-on locale
     * @throws HilosException When an action refuses
     */
    private static function switchedOnLocale(Language $language, ?Country $country): Locale
    {
        $locale = self::createLocale($language, $country);
        $locale->actions->switchOn();

        return $locale;
    }

    /**
     * @param Language $language Locale's language
     * @param ?Country $country Its country, or null for the countryless locale
     * @return Locale Switched-off locale
     * @throws HilosException When an action refuses
     */
    private static function createLocale(Language $language, ?Country $country): Locale
    {
        return Hilos::$db->locales->actions->create(
            $language, $country, 'YYYY-MM-DD', 'HH:mm:ss', '1,000.00', '+XX-XXXX-XXXX',
            'Street, House, City, Index', MeasurementSystem::METRIC, 'und',
        );
    }

    /**
     * @param bool $down Whether to drop the tables instead of creating them
     * @throws DatabaseException When a stub fails
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

final class TabLanguageLadderTestDbContext extends HilosDbContext
{
}
