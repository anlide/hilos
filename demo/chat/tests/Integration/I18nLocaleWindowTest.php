<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageLocalesPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Database\Object\Item\Locale as ObjectLocale;
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
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Pages\I18n\Details\DTO\HilosI18nLocaleDeleteActionDTO;
use PHPUnit\Framework\Attributes\DataProvider;
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
            if (!isset(Hilos::$db->countries['gb'])) {
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

    /** @return iterable<string, array{class-string<ActionPayloadDTO>, bool}> Existing-locale actions and parent shapes */
    public static function idActions(): iterable
    {
        foreach ([
            HilosI18nLocaleUpdateActionDTO::class,
            HilosI18nLocaleSwitchOnActionDTO::class,
            HilosI18nLocaleSwitchOffActionDTO::class,
            HilosI18nLocaleDeleteActionDTO::class,
        ] as $dtoClass) {
            yield $dtoClass . ' country' => [$dtoClass, true];
            yield $dtoClass . ' countryless' => [$dtoClass, false];
        }
    }

    /**
     * @param class-string<ActionPayloadDTO> $dtoClass Existing-locale action
     * @param bool $withCountry Whether the pair has a country
     */
    #[DataProvider('idActions')]
    public function testLocaleIdRefusesRecreationAndForeignParentPairs(string $dtoClass, bool $withCountry): void
    {
        $this->underAgent($this->agent, function () use ($dtoClass, $withCountry): void {
            $language = Hilos::$db->languages->actions->create('zz', 'Own language', false);
            $countryId = $withCountry ? Hilos::$db->countries['gb']->id : null;
            $page = new LanguageLocalesPage($this->agent);
            $formats = LocaleFormats::ofCatalog(BuiltInI18nCatalog::locale('en-GB'))->toArray();
            $payload = ['languageId' => $language->id, 'countryId' => $countryId, 'formats' => $formats];
            $add = HilosI18nLocaleAddActionDTO::fromArray($payload);
            $page->onAction('first-locale', $add->getAction(), $add);
            $code = $withCountry ? 'zz-GB' : 'zz';
            $oldId = Hilos::$db->locales[$code]->id;
            Hilos::$db->locales[$oldId]->actions->delete();
            $page->onAction('replacement-locale', $add->getAction(), $add);
            $replacement = Hilos::$db->locales[$code];
            self::assertNotSame($oldId, $replacement->id);
            $payload['localeId'] = $oldId;
            $payload['formats'][LocaleFormats::date] = 'YYYY-MM-DD';
            foreach ([
                $payload,
                array_replace($payload, ['localeId' => $replacement->id, 'languageId' => Hilos::$db->languages['en']->id]),
                array_replace($payload, ['localeId' => $replacement->id, 'countryId' => $withCountry ? null : Hilos::$db->countries['gb']->id]),
            ] as $invalid) {
                $dto = $dtoClass::fromArray($invalid);
                try {
                    $page->onAction('stale-locale', $dto->getAction(), $dto);
                    self::fail('The old ID or foreign parent pair must be refused');
                } catch (TableActionException) {
                    self::assertSame($formats, LocaleFormats::ofLocale($replacement)->toArray());
                    self::assertFalse($replacement->enabled);
                    self::assertSame($replacement->id, Hilos::$db->locales[$code]->id);
                }
            }
            $fresh = $dtoClass::fromArray(array_replace($payload, ['localeId' => $replacement->id]));
            $page->onAction('fresh-locale', $fresh->getAction(), $fresh);
            if ($fresh instanceof HilosI18nLocaleDeleteActionDTO) {
                self::assertArrayNotHasKey($replacement->id, Hilos::$db->locales);
            } elseif ($fresh instanceof HilosI18nLocaleUpdateActionDTO) {
                self::assertSame('YYYY-MM-DD', $replacement->dateFormat);
            } else {
                self::assertSame($fresh instanceof HilosI18nLocaleSwitchOnActionDTO, $replacement->enabled);
            }
        });
    }

    /** ADD uses the exact parents captured before either code was reused. */
    public function testLocaleAddRefusesRecreatedParents(): void
    {
        $this->underAgent($this->agent, function (): void {
            $oldLanguageId = Hilos::$db->languages->actions->create('zz', 'Old', false)->id;
            Hilos::$db->languages[$oldLanguageId]->actions->delete();
            $language = Hilos::$db->languages->actions->create('zz', 'New', false);
            $oldCountryId = Hilos::$db->countries->actions->create('qx', '¤', 'XXX')->id;
            Hilos::$db->countries[$oldCountryId]->actions->delete();
            $country = Hilos::$db->countries->actions->create('qx', '¤', 'XXX');
            $formats = LocaleFormats::ofCatalog(BuiltInI18nCatalog::locale('en-GB'));
            $page = new LanguageLocalesPage($this->agent);
            try {
                foreach ([
                    new HilosI18nLocaleAddActionDTO($oldLanguageId, $country->id, $formats),
                    new HilosI18nLocaleAddActionDTO($language->id, $oldCountryId, $formats),
                ] as $stale) {
                    try {
                        $page->onAction('stale-parent', $stale->getAction(), $stale);
                        self::fail('A stale parent ID must not resolve its replacement');
                    } catch (TableActionException) {
                        self::assertArrayNotHasKey('zz-QX', Hilos::$db->locales);
                    }
                }
                $fresh = new HilosI18nLocaleAddActionDTO($language->id, $country->id, $formats);
                $page->onAction('fresh-parents', $fresh->getAction(), $fresh);
                self::assertSame($language->id, Hilos::$db->locales['zz-QX']->languageId);
                self::assertSame($country->id, Hilos::$db->locales['zz-QX']->countryId);
            } finally {
                if (isset(Hilos::$db->locales['zz-QX'])) {
                    Hilos::$db->locales['zz-QX']->actions->delete();
                }
                $country->actions->delete();
            }
        });
    }

    public function testAddAndUpdateKnownPairAndCountrylessLocale(): void
    {
        $formats = LocaleFormats::ofCatalog(BuiltInI18nCatalog::locale('en-GB'));
        $page = new LanguageLocalesPage($this->agent);
        $this->underAgent($this->agent, static function () use ($page, $formats): void {
            self::assertNull($page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_ADD,
                new HilosI18nLocaleAddActionDTO(Hilos::$db->languages['en']->id, Hilos::$db->countries['gb']->id, $formats)));
        });
        self::assertFalse(Hilos::$db->locales['en-GB']->enabled);
        self::assertSame($formats->toArray(), LocaleFormats::ofLocale(Hilos::$db->locales['en-GB'])->toArray());

        $updated = new LocaleFormats(
            'YYYY-MM-DD', $formats->time, $formats->number, $formats->phone,
            $formats->address, $formats->measurement, $formats->collation,
        );
        $this->underAgent($this->agent, static function () use ($page, $updated): void {
            self::assertNull($page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_UPDATE,
                new HilosI18nLocaleUpdateActionDTO(
                    Hilos::$db->languages['en']->id,
                    Hilos::$db->countries['gb']->id,
                    Hilos::$db->locales['en-GB']->id,
                    $updated,
                )));
            self::assertNull($page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_ADD,
                new HilosI18nLocaleAddActionDTO(Hilos::$db->languages['en']->id, null, $updated)));
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
                new HilosI18nLocaleAddActionDTO(Hilos::$db->languages['en']->id, Hilos::$db->countries['gb']->id, $formats));
            self::assertNull($page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_ON,
                new HilosI18nLocaleSwitchOnActionDTO(
                    Hilos::$db->languages['en']->id,
                    Hilos::$db->countries['gb']->id,
                    Hilos::$db->locales['en-GB']->id,
                )));
        });
        self::assertTrue(Hilos::$db->locales['en-GB']->enabled);

        $this->underAgent($this->agent, static function () use ($page): void {
            self::assertNull($page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_ON,
                new HilosI18nLocaleSwitchOnActionDTO(
                    Hilos::$db->languages['en']->id,
                    Hilos::$db->countries['gb']->id,
                    Hilos::$db->locales['en-GB']->id,
                )));
        });
        self::assertTrue(Hilos::$db->locales['en-GB']->enabled);
    }

    public function testSwitchOnFreezesUpdatesOfTheLocale(): void
    {
        $formats = LocaleFormats::ofCatalog(BuiltInI18nCatalog::locale('en-GB'));
        $page = new LanguageLocalesPage($this->agent);
        $this->underAgent($this->agent, static function () use ($page, $formats): void {
            $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_ADD,
                new HilosI18nLocaleAddActionDTO(Hilos::$db->languages['en']->id, Hilos::$db->countries['gb']->id, $formats));
            $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_ON,
                new HilosI18nLocaleSwitchOnActionDTO(
                    Hilos::$db->languages['en']->id,
                    Hilos::$db->countries['gb']->id,
                    Hilos::$db->locales['en-GB']->id,
                ));
        });

        $this->expectException(I18nRowFrozenException::class);
        $this->underAgent($this->agent, static function () use ($page, $formats): void {
            $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_UPDATE,
                new HilosI18nLocaleUpdateActionDTO(
                    Hilos::$db->languages['en']->id,
                    Hilos::$db->countries['gb']->id,
                    Hilos::$db->locales['en-GB']->id,
                    $formats,
                ));
        });
    }

    public function testSwitchOnCountrylessLocale(): void
    {
        $formats = LocaleFormats::ofCatalog(BuiltInI18nCatalog::locale('en'));
        $page = new LanguageLocalesPage($this->agent);
        $this->underAgent($this->agent, static function () use ($page, $formats): void {
            $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_ADD,
                new HilosI18nLocaleAddActionDTO(Hilos::$db->languages['en']->id, null, $formats));
            self::assertNull($page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_ON,
                new HilosI18nLocaleSwitchOnActionDTO(Hilos::$db->languages['en']->id, null, Hilos::$db->locales['en']->id)));
        });
        self::assertTrue(Hilos::$db->locales['en']->enabled);
    }

    public function testSwitchOnUnknownAddressesIsRefused(): void
    {
        $page = new LanguageLocalesPage($this->agent);
        foreach ([['en', 'gb', 'Unknown locale: 999999'], ['zz', null, 'Unknown locale: 999999']] as [$language, $country, $message]) {
            try {
                $this->underAgent($this->agent, static function () use ($page, $language, $country): void {
                    $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_ON,
                        new HilosI18nLocaleSwitchOnActionDTO(
                            isset(Hilos::$db->languages[$language]) ? Hilos::$db->languages[$language]->id : 999999,
                            isset(Hilos::$db->countries[$country]) ? Hilos::$db->countries[$country]->id : 999999,
                            isset(Hilos::$db->locales[ObjectLocale::codeFor($language, $country)])
                                ? Hilos::$db->locales[ObjectLocale::codeFor($language, $country)]->id
                                : 999999,
                        ));
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
                new HilosI18nLocaleAddActionDTO(Hilos::$db->languages['en']->id, Hilos::$db->countries['gb']->id, $formats));
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
                    new HilosI18nLocaleSwitchOffActionDTO(
                        Hilos::$db->languages['en']->id,
                        Hilos::$db->countries['gb']->id,
                        Hilos::$db->locales['en-GB']->id,
                    )));
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
            self::assertSame('Locale en-GB switched off.', $success->data->data->message);
            self::assertNull($success->data->data->reply);

            $before = (int) Database::sql("SHOW SESSION STATUS LIKE 'Com_update'")->firstRow()['Value'];
            $this->underAgent($this->agent, static function () use ($page): void {
                self::assertNull($page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_OFF,
                    new HilosI18nLocaleSwitchOffActionDTO(
                        Hilos::$db->languages['en']->id,
                        Hilos::$db->countries['gb']->id,
                        Hilos::$db->locales['en-GB']->id,
                    )));
            });
            self::assertSame($before, (int) Database::sql("SHOW SESSION STATUS LIKE 'Com_update'")->firstRow()['Value']);
            $updated = new LocaleFormats(
                'YYYY-MM-DD', $formats->time, $formats->number, $formats->phone,
                $formats->address, $formats->measurement, $formats->collation,
            );
            $this->underAgent($this->agent, static function () use ($page, $updated): void {
                $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_UPDATE,
                    new HilosI18nLocaleUpdateActionDTO(
                        Hilos::$db->languages['en']->id,
                        Hilos::$db->countries['gb']->id,
                        Hilos::$db->locales['en-GB']->id,
                        $updated,
                    ));
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
                new HilosI18nLocaleAddActionDTO(Hilos::$db->languages['zz']->id, null, $formats));
            Hilos::$db->locales['zz']->actions->switchOn();
            self::assertNull($page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_OFF,
                new HilosI18nLocaleSwitchOffActionDTO(Hilos::$db->languages['zz']->id, null, Hilos::$db->locales['zz']->id)));
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
                new HilosI18nLocaleAddActionDTO(Hilos::$db->languages['en']->id, null, $formats));
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
                                new HilosI18nLocaleSwitchOffActionDTO(
                                    Hilos::$db->languages['en']->id,
                                    null,
                                    Hilos::$db->locales['en']->id,
                                ));
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
        foreach ([['en', 'gb', 'Unknown locale: 999999'], ['zz', null, 'Unknown locale: 999999']] as [$language, $country, $message]) {
            try {
                $this->underAgent($this->agent, static function () use ($page, $language, $country): void {
                    $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_OFF,
                        new HilosI18nLocaleSwitchOffActionDTO(
                            isset(Hilos::$db->languages[$language]) ? Hilos::$db->languages[$language]->id : 999999,
                            isset(Hilos::$db->countries[$country]) ? Hilos::$db->countries[$country]->id : 999999,
                            isset(Hilos::$db->locales[ObjectLocale::codeFor($language, $country)])
                                ? Hilos::$db->locales[ObjectLocale::codeFor($language, $country)]->id
                                : 999999,
                        ));
                });
                self::fail('Unknown locale should be refused');
            } catch (TableActionException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
        $this->expectException(InvalidActionPayloadException::class);
        $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_SWITCH_OFF,
            new HilosI18nLocaleSwitchOnActionDTO(
                Hilos::$db->languages['en']->id,
                null,
                isset(Hilos::$db->locales['en']) ? Hilos::$db->locales['en']->id : 999999,
            ));
    }

    public function testDuplicateAndUnknownAddressesAreRefused(): void
    {
        $formats = LocaleFormats::ofCatalog(BuiltInI18nCatalog::locale('en-GB'));
        $page = new LanguageLocalesPage($this->agent);
        $this->underAgent($this->agent, static function () use ($page, $formats): void {
            $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_ADD,
                new HilosI18nLocaleAddActionDTO(Hilos::$db->languages['en']->id, Hilos::$db->countries['gb']->id, $formats));
        });
        try {
            $this->underAgent($this->agent, static function () use ($page, $formats): void {
                $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_ADD,
                    new HilosI18nLocaleAddActionDTO(
                        Hilos::$db->languages['en']->id,
                        Hilos::$db->countries['gb']->id,
                        $formats,
                    ));
            });
            self::fail('The pair already has a locale');
        } catch (ValidationException $error) {
            self::assertSame("Locale 'en-GB' already exists.", $error->getMessage());
        }

        foreach ([['zz', null, 'Unknown language: 999999'], ['en', 'zz', 'Unknown country: 999999']] as [$language, $country, $message]) {
            try {
                $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_ADD,
                    new HilosI18nLocaleAddActionDTO(
                        isset(Hilos::$db->languages[$language]) ? Hilos::$db->languages[$language]->id : 999999,
                        isset(Hilos::$db->countries[$country]) ? Hilos::$db->countries[$country]->id : 999999,
                        $formats,
                    ));
                self::fail('Unknown address should be refused');
            } catch (TableActionException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
        $this->expectException(TableActionException::class);
        $this->expectExceptionMessage('Unknown locale: 999999');
        $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_UPDATE,
            new HilosI18nLocaleUpdateActionDTO(
                Hilos::$db->languages['en']->id,
                null,
                isset(Hilos::$db->locales['en']) ? Hilos::$db->locales['en']->id : 999999,
                $formats,
            ));
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
                    new HilosI18nLocaleAddActionDTO(Hilos::$db->languages['en']->id, null, $unknown));
            });
            self::fail('Unknown format should be refused');
        } catch (ValidationException $error) {
            self::assertStringContainsString("Date format 'bad date'", $error->getMessage());
            self::assertArrayNotHasKey('en', Hilos::$db->locales);
        }

        $this->underAgent($this->agent, static function () use ($page, $formats): void {
            $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_ADD,
                new HilosI18nLocaleAddActionDTO(Hilos::$db->languages['en']->id, null, $formats));
            Hilos::$db->locales['en']->actions->switchOn();
        });
        $this->expectException(I18nRowFrozenException::class);
        $this->underAgent($this->agent, static function () use ($page, $unknown): void {
            $page->onAction('locale-ak', HilosSignalConstants::HILOS_I18N_LOCALE_UPDATE,
                new HilosI18nLocaleUpdateActionDTO(
                    Hilos::$db->languages['en']->id,
                    null,
                    Hilos::$db->locales['en']->id,
                    $unknown,
                ));
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
