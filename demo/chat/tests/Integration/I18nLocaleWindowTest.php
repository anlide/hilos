<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageLocalesPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\DTO\LocaleFormats;
use Hilos\I18n\Exception\DefaultLocaleProtectedException;
use Hilos\I18n\Exception\I18nRowFrozenException;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLocaleAddActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLocaleSwitchOffActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLocaleSwitchOnActionDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLocaleUpdateActionDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** The locale window's write actions use the library's owned rows and write door. */
final class I18nLocaleWindowTest extends IntegrationTestCase
{
    private I18nLibraryAgent $agent;
    private string|false $previousDefaultLanguage;
    private bool $createdBritain = false;

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        Hilos::$sr = new SignalRouter();
        $this->previousDefaultLanguage = getenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=en');
        $this->agent = new I18nLibraryAgent();
        foreach ([
            HilosDbContext::languages, HilosDbContext::countries, HilosDbContext::locales,
            HilosDbContext::languageNames, HilosDbContext::countryNames,
        ] as $collection) {
            TruthSourceRegistry::register($collection, TruthSourceKeys::all(), $this->agent->getId());
        }
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), $this->agent->getId());
        Hilos::$rt->connections->actions->clear();
        $this->clearFixtures();
        $this->underAgent($this->agent, function (): void {
            Hilos::$db->languages->actions->create('en', 'English', false);
            if (Hilos::$db->countries['gb'] === null) {
                Hilos::$db->countries->actions->create('gb', '£', 'GBP');
                $this->createdBritain = true;
            }
        });
    }

    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent($this->agent->getId());
        $this->clearFixtures();
        TruthSourceRegistry::unregisterAgent($this->agent->getId());
        putenv($this->previousDefaultLanguage === false
            ? EnvConstants::HILOS_DEFAULT_LANGUAGE->name
            : EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=' . $this->previousDefaultLanguage);
        Hilos::$sr = null;
        parent::tearDown();
    }

    public function testAddAndUpdateKnownPairAndCountrylessLocale(): void
    {
        $formats = LocaleFormats::ofCatalog(BuiltInI18nCatalog::locale('en-GB'));
        $page = new LanguageLocalesPage($this->agent);
        $this->underAgent($this->agent, static function () use ($page, $formats): void {
            self::assertNull($page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_ADD,
                new HilosI18nLocaleAddActionDTO('en', 'gb', $formats)));
        });
        self::assertFalse(Hilos::$db->locales['en-GB']->enabled);
        self::assertSame($formats->toArray(), LocaleFormats::ofLocale(Hilos::$db->locales['en-GB'])->toArray());

        $updated = new LocaleFormats(
            'YYYY-MM-DD', $formats->time, $formats->number, $formats->phone,
            $formats->address, $formats->measurement, $formats->collation,
        );
        $this->underAgent($this->agent, static function () use ($page, $updated): void {
            self::assertNull($page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_UPDATE,
                new HilosI18nLocaleUpdateActionDTO('en', 'gb', $updated)));
            self::assertNull($page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_ADD,
                new HilosI18nLocaleAddActionDTO('en', null, $updated)));
        });
        self::assertSame('YYYY-MM-DD', Hilos::$db->locales['en-GB']->dateFormat);
        self::assertFalse(Hilos::$db->locales['en']->enabled);
    }

    public function testSwitchOnKnownPairIsIdempotent(): void
    {
        $formats = LocaleFormats::ofCatalog(BuiltInI18nCatalog::locale('en-GB'));
        $page = new LanguageLocalesPage($this->agent);
        $this->underAgent($this->agent, static function () use ($page, $formats): void {
            $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_ADD,
                new HilosI18nLocaleAddActionDTO('en', 'gb', $formats));
            self::assertNull($page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_ON,
                new HilosI18nLocaleSwitchOnActionDTO('en', 'gb')));
        });
        self::assertTrue(Hilos::$db->locales['en-GB']->enabled);

        $this->underAgent($this->agent, static function () use ($page): void {
            self::assertNull($page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_ON,
                new HilosI18nLocaleSwitchOnActionDTO('en', 'gb')));
        });
        self::assertTrue(Hilos::$db->locales['en-GB']->enabled);
    }

    public function testSwitchOnFreezesUpdatesOfTheLocale(): void
    {
        $formats = LocaleFormats::ofCatalog(BuiltInI18nCatalog::locale('en-GB'));
        $page = new LanguageLocalesPage($this->agent);
        $this->underAgent($this->agent, static function () use ($page, $formats): void {
            $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_ADD,
                new HilosI18nLocaleAddActionDTO('en', 'gb', $formats));
            $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_ON,
                new HilosI18nLocaleSwitchOnActionDTO('en', 'gb'));
        });

        $this->expectException(I18nRowFrozenException::class);
        $this->underAgent($this->agent, static function () use ($page, $formats): void {
            $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_UPDATE,
                new HilosI18nLocaleUpdateActionDTO('en', 'gb', $formats));
        });
    }

    public function testSwitchOnCountrylessLocale(): void
    {
        $formats = LocaleFormats::ofCatalog(BuiltInI18nCatalog::locale('en'));
        $page = new LanguageLocalesPage($this->agent);
        $this->underAgent($this->agent, static function () use ($page, $formats): void {
            $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_ADD,
                new HilosI18nLocaleAddActionDTO('en', null, $formats));
            self::assertNull($page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_ON,
                new HilosI18nLocaleSwitchOnActionDTO('en', null)));
        });
        self::assertTrue(Hilos::$db->locales['en']->enabled);
    }

    public function testSwitchOnUnknownAddressesIsRefused(): void
    {
        $page = new LanguageLocalesPage($this->agent);
        foreach ([['en', 'gb', 'Unknown locale: en-GB'], ['zz', null, 'Unknown locale: zz']] as [$language, $country, $message]) {
            try {
                $this->underAgent($this->agent, static function () use ($page, $language, $country): void {
                    $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_ON,
                        new HilosI18nLocaleSwitchOnActionDTO($language, $country));
                });
                self::fail('Unknown locale should be refused');
            } catch (TableActionException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
    }

    public function testSwitchOffSelectedCountryLocalePreservesItsReferencesAndAllowsEditing(): void
    {
        $formats = LocaleFormats::ofCatalog(BuiltInI18nCatalog::locale('en-GB'));
        $page = new LanguageLocalesPage($this->agent);
        $previousDefault = Hilos::$db->countries['gb']->defaultLocaleId;
        $this->underAgent($this->agent, static function () use ($page, $formats): void {
            $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_ADD,
                new HilosI18nLocaleAddActionDTO('en', 'gb', $formats));
            Hilos::$db->locales['en-GB']->actions->switchOn();
            Hilos::$db->countries['gb']->actions->update('£', 'GBP', Hilos::$db->locales['en-GB']->id);
            Hilos::$db->countryNames->actions->createManual(
                Hilos::$db->countries['gb'], Hilos::$db->languages['en'], null, 'Britain',
            );
        });
        try {
            $names = Database::sql('SELECT * FROM hilos_country_name ORDER BY id')->rows();
            $this->underAgent($this->agent, static function () use ($page): void {
                self::assertNull($page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_OFF,
                    new HilosI18nLocaleSwitchOffActionDTO('en', 'gb')));
            });
            self::assertFalse(Hilos::$db->locales['en-GB']->enabled);
            self::assertSame(Hilos::$db->locales['en-GB']->id, Hilos::$db->countries['gb']->defaultLocaleId);
            self::assertSame($formats->toArray(), LocaleFormats::ofLocale(Hilos::$db->locales['en-GB'])->toArray());
            self::assertSame($names, Database::sql('SELECT * FROM hilos_country_name ORDER BY id')->rows());
            $page->sendActionSuccess('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_OFF, 'switch-off-request');
            do {
                $success = Hilos::$sr->getNextQueuedSignal();
            } while ($success !== null && $success->signalName->getName() !== SignalConstants::ACTION_SUCCESS);
            self::assertSame(SignalConstants::ACTION_SUCCESS, $success?->signalName->getName());
            self::assertInstanceOf(WebSocketSignalData::class, $success->data);
            self::assertInstanceOf(PageActionSuccessSignalData::class, $success->data->data);
            self::assertSame(HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_OFF, $success->data->data->action);
            self::assertSame('switch-off-request', $success->data->data->requestId);
            self::assertSame('Locale switched off.', $success->data->data->message);
            self::assertNull($success->data->data->reply);

            $before = (int) Database::sql("SHOW SESSION STATUS LIKE 'Com_update'")->firstRow()['Value'];
            $this->underAgent($this->agent, static function () use ($page): void {
                self::assertNull($page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_OFF,
                    new HilosI18nLocaleSwitchOffActionDTO('en', 'gb')));
            });
            self::assertSame($before, (int) Database::sql("SHOW SESSION STATUS LIKE 'Com_update'")->firstRow()['Value']);
            $updated = new LocaleFormats(
                'YYYY-MM-DD', $formats->time, $formats->number, $formats->phone,
                $formats->address, $formats->measurement, $formats->collation,
            );
            $this->underAgent($this->agent, static function () use ($page, $updated): void {
                $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_UPDATE,
                    new HilosI18nLocaleUpdateActionDTO('en', 'gb', $updated));
            });
            self::assertSame($updated->toArray(), LocaleFormats::ofLocale(Hilos::$db->locales['en-GB'])->toArray());
        } finally {
            $this->underAgent($this->agent, static function () use ($previousDefault): void {
                Hilos::$db->countries['gb']->actions->update('£', 'GBP', $previousDefault);
            });
        }
    }

    public function testSwitchOffNonDefaultCountrylessLocaleLeavesLanguageEnabled(): void
    {
        $formats = LocaleFormats::ofCatalog(BuiltInI18nCatalog::locale('en'));
        $page = new LanguageLocalesPage($this->agent);
        $this->underAgent($this->agent, static function () use ($page, $formats): void {
            Hilos::$db->languages->actions->create('zz', 'Test language', false)->actions->switchOn();
            $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_ADD,
                new HilosI18nLocaleAddActionDTO('zz', null, $formats));
            Hilos::$db->locales['zz']->actions->switchOn();
            self::assertNull($page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_OFF,
                new HilosI18nLocaleSwitchOffActionDTO('zz', null)));
        });
        self::assertFalse(Hilos::$db->locales['zz']->enabled);
        self::assertTrue(Hilos::$db->languages['zz']->enabled);
        self::assertSame($formats->toArray(), LocaleFormats::ofLocale(Hilos::$db->locales['zz'])->toArray());
    }

    public function testDefaultCountrylessLocaleIsProtectedBeforeIdempotentReturnOnBothEntrances(): void
    {
        $formats = LocaleFormats::ofCatalog(BuiltInI18nCatalog::locale('en'));
        $page = new LanguageLocalesPage($this->agent);
        $this->underAgent($this->agent, static function () use ($page, $formats): void {
            $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_ADD,
                new HilosI18nLocaleAddActionDTO('en', null, $formats));
        });
        foreach ([false, true] as $enabled) {
            if ($enabled) {
                $this->underAgent($this->agent, static function (): void {
                    Hilos::$db->locales['en']->actions->switchOn();
                });
            }
            foreach ([false, true] as $viaPage) {
                try {
                    $this->underAgent($this->agent, static function () use ($page, $viaPage): void {
                        if ($viaPage) {
                            $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_OFF,
                                new HilosI18nLocaleSwitchOffActionDTO('en', null));
                        } else {
                            Hilos::$db->locales['en']->actions->switchOff();
                        }
                    });
                    self::fail('The default countryless locale must be protected');
                } catch (DefaultLocaleProtectedException $error) {
                    self::assertSame("Default language locale 'en' cannot be switched off", $error->getMessage());
                }
                self::assertSame($enabled, Hilos::$db->locales['en']->enabled);
                self::assertSame($formats->toArray(), LocaleFormats::ofLocale(Hilos::$db->locales['en'])->toArray());
            }
        }
    }

    public function testSwitchOffUnknownAddressesAndMismatchedDtoAreRefused(): void
    {
        $page = new LanguageLocalesPage($this->agent);
        foreach ([['en', 'gb', 'Unknown locale: en-GB'], ['zz', null, 'Unknown locale: zz']] as [$language, $country, $message]) {
            try {
                $this->underAgent($this->agent, static function () use ($page, $language, $country): void {
                    $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_OFF,
                        new HilosI18nLocaleSwitchOffActionDTO($language, $country));
                });
                self::fail('Unknown locale should be refused');
            } catch (TableActionException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
        $this->expectException(InvalidActionPayloadException::class);
        $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_OFF,
            new HilosI18nLocaleSwitchOnActionDTO('en', null));
    }

    public function testDuplicateAndUnknownAddressesAreRefused(): void
    {
        $formats = LocaleFormats::ofCatalog(BuiltInI18nCatalog::locale('en-GB'));
        $page = new LanguageLocalesPage($this->agent);
        $this->underAgent($this->agent, static function () use ($page, $formats): void {
            $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_ADD,
                new HilosI18nLocaleAddActionDTO('en', 'gb', $formats));
        });
        try {
            $this->underAgent($this->agent, static function () use ($page, $formats): void {
                $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_ADD,
                    new HilosI18nLocaleAddActionDTO('en', 'gb', $formats));
            });
            self::fail('The pair already has a locale');
        } catch (ValidationException $error) {
            self::assertSame("Locale 'en-GB' already exists.", $error->getMessage());
        }

        foreach ([['zz', null, 'Unknown language: zz'], ['en', 'zz', 'Unknown country: zz']] as [$language, $country, $message]) {
            try {
                $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_ADD,
                    new HilosI18nLocaleAddActionDTO($language, $country, $formats));
                self::fail('Unknown address should be refused');
            } catch (TableActionException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
        $this->expectException(TableActionException::class);
        $this->expectExceptionMessage('Unknown locale: en');
        $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_UPDATE,
            new HilosI18nLocaleUpdateActionDTO('en', null, $formats));
    }

    public function testUnknownTemplateAndFrozenLocaleAreRefused(): void
    {
        $formats = LocaleFormats::ofCatalog(BuiltInI18nCatalog::locale('en'));
        $unknown = new LocaleFormats(
            'bad date', $formats->time, $formats->number, $formats->phone,
            $formats->address, $formats->measurement, $formats->collation,
        );
        $page = new LanguageLocalesPage($this->agent);
        try {
            $this->underAgent($this->agent, static function () use ($page, $unknown): void {
                $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_ADD,
                    new HilosI18nLocaleAddActionDTO('en', null, $unknown));
            });
            self::fail('Unknown format should be refused');
        } catch (ValidationException $error) {
            self::assertStringContainsString("Date format 'bad date'", $error->getMessage());
            self::assertNull(Hilos::$db->locales['en']);
        }

        $this->underAgent($this->agent, static function () use ($page, $formats): void {
            $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_ADD,
                new HilosI18nLocaleAddActionDTO('en', null, $formats));
            Hilos::$db->locales['en']->actions->switchOn();
        });
        $this->expectException(I18nRowFrozenException::class);
        $this->underAgent($this->agent, static function () use ($page, $unknown): void {
            $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_UPDATE,
                new HilosI18nLocaleUpdateActionDTO('en', null, $unknown));
        });
    }

    private function clearFixtures(): void
    {
        Database::sqlRun("UPDATE hilos_country SET default_locale_id = NULL WHERE default_locale_id IN"
            . " (SELECT id FROM hilos_locale WHERE language_id IN (SELECT id FROM hilos_language WHERE code IN ('en', 'zz')))");
        Database::sqlRun("DELETE FROM hilos_country_name WHERE language_id IN (SELECT id FROM hilos_language WHERE code IN ('en', 'zz'))");
        Database::sqlRun("DELETE FROM hilos_language_name WHERE language_id IN (SELECT id FROM hilos_language WHERE code IN ('en', 'zz'))"
            . " OR in_language_id IN (SELECT id FROM hilos_language WHERE code IN ('en', 'zz'))");
        Database::sqlRun("DELETE FROM hilos_locale WHERE language_id IN (SELECT id FROM hilos_language WHERE code IN ('en', 'zz'))");
        Database::sqlRun("DELETE FROM hilos_language WHERE code IN ('en', 'zz')");
        if ($this->createdBritain) {
            Database::sqlRun("DELETE FROM hilos_country WHERE code = 'gb'");
        }
        foreach ([Hilos::$db->countryNames, Hilos::$db->languageNames, Hilos::$db->locales,
            Hilos::$db->countries, Hilos::$db->languages] as $collection) {
            $collection->getObjectCollection()?->reHydrate();
            $collection->clearCache();
        }
    }
}
