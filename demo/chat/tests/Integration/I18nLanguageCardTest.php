<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageLocalesPage;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageNamesPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Language as EntityLanguage;
use Hilos\Database\Entity\Item\LanguageName as EntityLanguageName;
use Hilos\Database\Entity\Item\Locale as EntityLocale;
use Hilos\Database\View\Item\Country;
use Hilos\Database\View\Item\Language;
use Hilos\Database\View\Item\Locale;
use Hilos\I18n\Browser\LanguageCardBrowserData;
use Hilos\I18n\DTO\LanguageCardSummary;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\MeasurementSystem;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

/** The card's aggregate counts persisted rows and gives manual names precedence. */
final class I18nLanguageCardTest extends IntegrationTestCase
{
    private I18nLibraryAgent $agent;
    private string|false $previousDefaultLanguage;

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        Hilos::$sr = new SignalRouter();
        $this->previousDefaultLanguage = getenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=en');
        $this->agent = new I18nLibraryAgent();
        foreach ([
            HilosDbContext::languages,
            HilosDbContext::countries,
            HilosDbContext::locales,
            HilosDbContext::languageNames,
            HilosDbContext::countryNames,
        ] as $collection) {
            TruthSourceRegistry::register($collection, TruthSourceKeys::all(), $this->agent->getId());
        }
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), $this->agent->getId());
        Hilos::$rt->connections->actions->clear();
        $this->clearFixtures();
    }

    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent($this->agent->getId());
        $this->clearFixtures();
        TruthSourceRegistry::unregisterAgent($this->agent->getId());
        if ($this->previousDefaultLanguage === false) {
            putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        } else {
            putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=' . $this->previousDefaultLanguage);
        }
        Hilos::$sr = null;
        parent::tearDown();
    }

    public function testSummaryCountsBaseNamesAndLocalesAndSeparatesLockedCountryNames(): void
    {
        $this->underAgent($this->agent, static function (): void {
            $english = Hilos::$db->languages->actions->create('en', 'English', false);
            $own = Hilos::$db->languages->actions->create('qx', 'Own language', true);
            $writing = Hilos::$db->languages->actions->create('qy', 'Other language', false);
            $country = Hilos::$db->countries->actions->create('qx', '$', 'USD');

            self::assertSame(LanguageCardSummary::DELETE_DEFAULT, Hilos::$db->languages->cardSummary($english->id)->deleteReason);
            self::assertSame([
                'isDefault' => false,
                'isOwn' => true,
                'localeCount' => 0,
                'enabledLocaleCount' => 0,
                'nameCount' => 0,
                'countrylessLocale' => LanguageCardSummary::COUNTRYLESS_MISSING,
                'canDelete' => true,
                'deleteReason' => null,
            ], Hilos::$db->languages->cardSummary($own->id)->toArray());

            $catalogName = Hilos::$db->countryNames->actions->createCatalogBase($country, $writing, 'Catalog name');
            self::assertTrue(Hilos::$db->languages->cardSummary($writing->id)->canDelete);
            $catalogName->actions->edit('Manual name');
            self::assertSame(LanguageCardSummary::DELETE_NAMES, Hilos::$db->languages->cardSummary($writing->id)->deleteReason);

            Hilos::$db->languageNames->actions->createManual($own, $english, null, 'Owned');
            Hilos::$db->languageNames->actions->createManual($own, $writing, null, '');
            $withNames = Hilos::$db->languages->cardSummary($own->id);
            self::assertSame(1, $withNames->nameCount);
            self::assertSame(LanguageCardSummary::DELETE_NAMES, $withNames->deleteReason);

            Hilos::$db->locales->actions->create(
                $own,
                null,
                'YYYY-MM-DD',
                'HH:mm:ss',
                '1,000.00',
                '+XX-XXXX-XXXX',
                'Street, House, City, Index',
                MeasurementSystem::METRIC,
                'und',
            );
            $withLocale = Hilos::$db->languages->cardSummary($own->id);
            self::assertSame(1, $withLocale->localeCount);
            self::assertSame(1, $withLocale->nameCount);
            self::assertSame(LanguageCardSummary::COUNTRYLESS_OFF, $withLocale->countrylessLocale);
            self::assertSame(LanguageCardSummary::DELETE_LOCALES, $withLocale->deleteReason);
        });

        $snapshot = Hilos::$browser->buildSubscribeSnapshot(
            LanguageDetailPage::PAGE,
            'i18n-language-card-reader',
            new PageRouteParams(['languageCode' => 'qx']),
        )->toArray();
        self::assertSame([
            'languageId' => Hilos::$db->languages['qx']->id,
            'code' => 'qx',
            'nativeName' => 'Own language',
            'rtl' => true,
            'enabled' => false,
            LanguageCardBrowserData::FIELD_SUMMARY => [
                'isDefault' => false,
                'isOwn' => true,
                'localeCount' => 1,
                'enabledLocaleCount' => 0,
                'nameCount' => 1,
                'countrylessLocale' => LanguageCardSummary::COUNTRYLESS_OFF,
                'canDelete' => false,
                'deleteReason' => LanguageCardSummary::DELETE_LOCALES,
            ],
        ], $snapshot[PagePayload::data][LanguageCardBrowserData::DATA]);
    }

    public function testTwoSubscriptionsReceiveRenameAndDependentChangesFromTheSameCardSource(): void
    {
        $admin = Hilos::$db->users->actions->createWithName('Language card admin');
        $admin->actions->setAdmin(true);
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', __METHOD__), 0, 32));
        $session->actions->bindUser((int)$admin->id);
        foreach (['card-one', 'card-two'] as $acceptKey) {
            Hilos::$rt->connections->actions->register($acceptKey, (int)$admin->id, $session->token, (int)$session->id);
        }

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages->actions->create('en', 'English', false);
            Hilos::$db->languages->actions->create('qx', 'Before', false);
        });
        foreach (['card-one', 'card-two'] as $acceptKey) {
            $this->subscribe($acceptKey, 'qx');
            $responses = $this->drainCardResponses();
            self::assertCount(1, $responses);
            self::assertSame('Before', $this->cardOf($responses[0])['nativeName']);
        }

        $language = Hilos::$db->languages['qx'];
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages['qx']->actions->update('After', true);
        });
        Hilos::$browser->record(SourceChange::dbUpdated(
            HilosDbContext::languages,
            (string)$language->id,
            [EntityLanguage::native_name => 'After', EntityLanguage::rtl => true],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertCardsForBoth(['nativeName' => 'After', 'rtl' => true]);

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->locales->actions->create(
                Hilos::$db->languages['qx'],
                null,
                'YYYY-MM-DD',
                'HH:mm:ss',
                '1,000.00',
                '+XX-XXXX-XXXX',
                'Street, House, City, Index',
                MeasurementSystem::METRIC,
                'und',
            );
            Hilos::$db->languageNames->actions->createManual(
                Hilos::$db->languages['qx'],
                Hilos::$db->languages['en'],
                null,
                'Own name',
            );
        });
        $locale = Hilos::$db->locales['qx'];
        $name = Hilos::$db->languageNames->findBase($language->id, Hilos::$db->languages['en']->id);
        Hilos::$browser->record(SourceChange::dbCreated(
            HilosDbContext::locales,
            (string)$locale->id,
            [EntityLocale::language_id => $language->id],
        ));
        Hilos::$browser->record(SourceChange::dbCreated(
            HilosDbContext::languageNames,
            (string)$name->id,
            [EntityLanguageName::language_id => $language->id, EntityLanguageName::in_language_id => Hilos::$db->languages['en']->id],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertCardsForBoth(['summary' => [
            'localeCount' => 1,
            'nameCount' => 1,
            'deleteReason' => LanguageCardSummary::DELETE_LOCALES,
        ]]);

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->locales['qx']->actions->delete();
        });
        Hilos::$browser->record(SourceChange::dbDeleted(
            HilosDbContext::locales,
            (string)$locale->id,
            [EntityLocale::language_id => $language->id],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertCardsForBoth(['summary' => [
            'localeCount' => 0,
            'deleteReason' => LanguageCardSummary::DELETE_NAMES,
        ]]);

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languageNames->findBase(
                Hilos::$db->languages['qx']->id,
                Hilos::$db->languages['en']->id,
            )->actions->edit('');
        });
        Hilos::$browser->record(SourceChange::dbUpdated(
            HilosDbContext::languageNames,
            (string)$name->id,
            [EntityLanguageName::name => '', EntityLanguageName::language_id => $language->id],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertCardsForBoth(['summary' => ['nameCount' => 0, 'deleteReason' => LanguageCardSummary::DELETE_NAMES]]);

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languageNames->findBase(
                Hilos::$db->languages['qx']->id,
                Hilos::$db->languages['en']->id,
            )->actions->delete();
        });
        Hilos::$browser->record(SourceChange::dbDeleted(
            HilosDbContext::languageNames,
            (string)$name->id,
            [EntityLanguageName::language_id => $language->id, EntityLanguageName::in_language_id => Hilos::$db->languages['en']->id],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertCardsForBoth(['summary' => ['nameCount' => 0, 'canDelete' => true, 'deleteReason' => null]]);

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages['qx']->actions->delete();
        });
        Hilos::$browser->record(SourceChange::dbDeleted(
            HilosDbContext::languages,
            (string)$language->id,
            [EntityLanguage::id => $language->id, EntityLanguage::code => 'qx'],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $responses = $this->drainCardResponses();
        self::assertCount(2, $responses);
        foreach ($responses as $response) {
            self::assertSame([], $this->cardOf($response));
        }
    }

    /** @return iterable<string, array{class-string<AbstractPage>}> Pages sharing the language card */
    public static function languageCardPages(): iterable
    {
        yield 'overview' => [LanguageDetailPage::class];
        yield 'names' => [LanguageNamesPage::class];
        yield 'locales' => [LanguageLocalesPage::class];
    }

    /** @param class-string<AbstractPage> $pageClass Page already open when locale facts arrive */
    #[DataProvider('languageCardPages')]
    public function testSubscribedCardReceivesEnabledLocaleCounts(string $pageClass): void
    {
        $admin = Hilos::$db->users->actions->createWithName('Locale count admin');
        $admin->actions->setAdmin(true);
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', __METHOD__ . $pageClass), 0, 32));
        $session->actions->bindUser((int)$admin->id);
        foreach (['count-card', 'other-count-card'] as $acceptKey) {
            Hilos::$rt->connections->actions->register($acceptKey, (int)$admin->id, $session->token, (int)$session->id);
        }
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages->actions->create('en', 'English', false);
            Hilos::$db->languages->actions->create('qx', 'Counted', false);
            Hilos::$db->languages->actions->create('qy', 'Other', false);
            Hilos::$db->countries->actions->create('qx', '$', 'USD');
            self::createLocale(Hilos::$db->languages['qy'], null)->actions->switchOn();
        });
        $cards = [];
        foreach (['count-card' => 'qx', 'other-count-card' => 'qy'] as $acceptKey => $code) {
            $this->subscribe($acceptKey, $code, $pageClass);
            $responses = $this->drainAddressedCardResponses();
            self::assertSame([$acceptKey], array_keys($responses));
            self::assertCount(1, $responses[$acceptKey]);
            self::assertSame($pageClass::PAGE, $responses[$acceptKey][0]->pageKey);
            $cards[$acceptKey] = $this->cardOf($responses[$acceptKey][0]);
            self::assertSame($code, $cards[$acceptKey]['code']);
            self::assertSame($code === 'qx' ? 0 : 1, $cards[$acceptKey]['summary']['enabledLocaleCount']);
        }

        $assertCount = function (
            SourceChange $change, string $code, int $enabled, int $total, string $countryless,
        ) use ($pageClass, &$cards): void {
            $changedAcceptKey = $code === 'qx' ? 'count-card' : 'other-count-card';
            $foreignAcceptKey = $code === 'qx' ? 'other-count-card' : 'count-card';
            $foreignCard = $cards[$foreignAcceptKey];
            Hilos::$browser->record($change);
            self::assertSame([], Hilos::$browser->flushToSignalRouter());
            $responses = $this->drainAddressedCardResponses();
            self::assertArrayHasKey($changedAcceptKey, $responses, 'Every subscriber of the changed language must update');
            foreach ($responses as $acceptKey => $frames) {
                self::assertArrayHasKey($acceptKey, $cards);
                foreach ($frames as $response) {
                    self::assertSame($pageClass::PAGE, $response->pageKey);
                    $card = $this->cardOf($response);
                    self::assertNotSame([], $card, 'A locale fact must not erase an open card');
                    self::assertSame($cards[$acceptKey]['languageId'], $card['languageId']);
                    self::assertSame($cards[$acceptKey]['code'], $card['code']);
                    $cards[$acceptKey] = $card;
                }
            }
            self::assertSame($foreignCard, $cards[$foreignAcceptKey]);
            self::assertSame($enabled, $cards[$changedAcceptKey]['summary']['enabledLocaleCount']);
            self::assertSame($total, $cards[$changedAcceptKey]['summary']['localeCount']);
            self::assertSame($countryless, $cards[$changedAcceptKey]['summary']['countrylessLocale']);
        };
        foreach (['qx', 'qy'] as $code) {
            if ($code === 'qy') {
                $initialLocale = Hilos::$db->locales['qy'];
                $this->underAgent($this->agent, static fn () => $initialLocale->actions->switchOff());
                $assertCount(SourceChange::dbUpdated(HilosDbContext::locales, (string)$initialLocale->id, [
                    EntityLocale::enabled => false,
                ]), $code, 0, 1, LanguageCardSummary::COUNTRYLESS_OFF);
                $this->underAgent($this->agent, static fn () => $initialLocale->actions->delete());
                $assertCount(SourceChange::dbDeleted(HilosDbContext::locales, (string)$initialLocale->id, [
                    EntityLocale::language_id => Hilos::$db->languages[$code]->id,
                ]), $code, 0, 0, LanguageCardSummary::COUNTRYLESS_MISSING);
            }
            $countryless = $this->underAgent($this->agent, static fn (): Locale => self::createLocale(Hilos::$db->languages[$code], null));
            $assertCount(SourceChange::dbCreated(HilosDbContext::locales, (string)$countryless->id, [
                EntityLocale::language_id => Hilos::$db->languages[$code]->id,
            ]), $code, 0, 1, LanguageCardSummary::COUNTRYLESS_OFF);
            $this->underAgent($this->agent, static fn () => $countryless->actions->switchOn());
            $assertCount(SourceChange::dbUpdated(HilosDbContext::locales, (string)$countryless->id, [
                EntityLocale::enabled => true,
            ]), $code, 1, 1, LanguageCardSummary::COUNTRYLESS_ON);

            $regional = $this->underAgent($this->agent, static fn (): Locale => self::createLocale(
                Hilos::$db->languages[$code], Hilos::$db->countries['qx'],
            ));
            $assertCount(SourceChange::dbCreated(HilosDbContext::locales, (string)$regional->id, [
                EntityLocale::language_id => Hilos::$db->languages[$code]->id,
            ]), $code, 1, 2, LanguageCardSummary::COUNTRYLESS_ON);
            $this->underAgent($this->agent, static fn () => $regional->actions->switchOn());
            $assertCount(SourceChange::dbUpdated(HilosDbContext::locales, (string)$regional->id, [
                EntityLocale::enabled => true,
            ]), $code, 2, 2, LanguageCardSummary::COUNTRYLESS_ON);

            $this->underAgent($this->agent, static fn () => $countryless->actions->switchOff());
            $assertCount(SourceChange::dbUpdated(HilosDbContext::locales, (string)$countryless->id, [
                EntityLocale::enabled => false,
            ]), $code, 1, 2, LanguageCardSummary::COUNTRYLESS_OFF);
            $this->underAgent($this->agent, static fn () => $countryless->actions->delete());
            $assertCount(SourceChange::dbDeleted(HilosDbContext::locales, (string)$countryless->id, [
                EntityLocale::language_id => Hilos::$db->languages[$code]->id,
            ]), $code, 1, 1, LanguageCardSummary::COUNTRYLESS_MISSING);
            $this->underAgent($this->agent, static fn () => $regional->actions->switchOff());
            $assertCount(SourceChange::dbUpdated(HilosDbContext::locales, (string)$regional->id, [
                EntityLocale::enabled => false,
            ]), $code, 0, 1, LanguageCardSummary::COUNTRYLESS_MISSING);
            $this->underAgent($this->agent, static fn () => $regional->actions->delete());
            $assertCount(SourceChange::dbDeleted(HilosDbContext::locales, (string)$regional->id, [
                EntityLocale::language_id => Hilos::$db->languages[$code]->id,
            ]), $code, 0, 0, LanguageCardSummary::COUNTRYLESS_MISSING);
        }

        $languageId = $cards['count-card']['languageId'];
        $foreignCard = $cards['other-count-card'];
        $this->underAgent($this->agent, static fn () => Hilos::$db->languages[$languageId]->actions->delete());
        Hilos::$browser->record(SourceChange::dbDeleted(HilosDbContext::languages, (string)$languageId, [
            EntityLanguage::id => $languageId, EntityLanguage::code => 'qx',
        ]));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $responses = $this->drainAddressedCardResponses();
        self::assertArrayHasKey('count-card', $responses);
        foreach ($responses as $acceptKey => $frames) {
            self::assertContains($acceptKey, ['count-card', 'other-count-card']);
            foreach ($frames as $response) {
                self::assertSame($pageClass::PAGE, $response->pageKey);
                self::assertSame($acceptKey === 'count-card' ? [] : $foreignCard, $this->cardOf($response));
            }
        }
    }

    public function testAdminViewModeShowsAllNonpersonalCardFieldsToAViewer(): void
    {
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages->actions->create('en', 'English', false);
            Hilos::$db->languages->actions->create('qx', 'Viewer language', true);
        });
        $viewer = Hilos::$db->users->actions->createWithName('Language card viewer');
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', __METHOD__), 0, 32));
        $session->actions->bindUser((int)$viewer->id);
        Hilos::$rt->connections->actions->register('card-viewer', (int)$viewer->id, $session->token, (int)$session->id);
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);

        try {
            $this->subscribe('card-viewer', 'qx');
            $responses = $this->drainCardResponses();
            self::assertCount(1, $responses);
            $card = $this->cardOf($responses[0]);
            self::assertSame([
                'languageId' => Hilos::$db->languages['qx']->id,
                'code' => 'qx',
                'nativeName' => 'Viewer language',
                'rtl' => true,
                'enabled' => false,
                'summary' => [
                    'isDefault' => false,
                    'isOwn' => true,
                    'localeCount' => 0,
                    'enabledLocaleCount' => 0,
                    'nameCount' => 0,
                    'countrylessLocale' => LanguageCardSummary::COUNTRYLESS_MISSING,
                    'canDelete' => true,
                    'deleteReason' => null,
                ],
            ], $card);
            self::assertStringNotContainsString(HiddenValue::KEY, json_encode($card, JSON_THROW_ON_ERROR));

            $locale = $this->underAgent($this->agent, static function (): Locale {
                $locale = self::createLocale(Hilos::$db->languages['qx'], null);
                $locale->actions->switchOn();
                return $locale;
            });
            Hilos::$browser->record(SourceChange::dbCreated(HilosDbContext::locales, (string)$locale->id, [
                EntityLocale::language_id => Hilos::$db->languages['qx']->id,
            ]));
            self::assertSame([], Hilos::$browser->flushToSignalRouter());
            $responses = $this->drainCardResponses();
            self::assertCount(1, $responses);
            $updated = $this->cardOf($responses[0]);
            self::assertSame(1, $updated['summary']['enabledLocaleCount']);
            self::assertSame(LanguageCardSummary::COUNTRYLESS_ON, $updated['summary']['countrylessLocale']);
            self::assertStringNotContainsString(HiddenValue::KEY, json_encode($updated, JSON_THROW_ON_ERROR));
        } finally {
            Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
            RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        }
    }

    /**
     * @param string $acceptKey One open browser window
     * @param string $code Route code
     * @param class-string<AbstractPage> $pageClass Page subscribing to the shared card
     */
    private function subscribe(string $acceptKey, string $code, string $pageClass = LanguageDetailPage::class): void
    {
        $params = ['languageCode' => $code];
        Hilos::$sr->subscribeToPage(
            $pageClass::PAGE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, $pageClass::PAGE, $params),
        );
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), function () use ($acceptKey, $params, $pageClass): void {
            new $pageClass($this->agent)->onSubscribe($acceptKey, new PageRouteParams($params));
        });
    }

    /**
     * @param Language $language Locale's language
     * @param ?Country $country Country or null for the countryless locale
     * @return Locale New disabled locale
     */
    private static function createLocale(Language $language, ?Country $country): Locale
    {
        return Hilos::$db->locales->actions->create(
            $language, $country, 'YYYY-MM-DD', 'HH:mm:ss', '1,000.00', '+XX-XXXX-XXXX',
            'Street, House, City, Index', MeasurementSystem::METRIC, 'und',
        );
    }

    /** @return list<PageResponseSignalData> Responses queued since the last call */
    private function drainCardResponses(): array
    {
        $responses = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE) {
                continue;
            }
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
            $responses[] = $signal->data->data;
        }
        return $responses;
    }

    /** @return array<string, list<PageResponseSignalData>> Page responses by their actual connection addressee */
    private function drainAddressedCardResponses(): array
    {
        $responses = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE) {
                continue;
            }
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
            $responses[$signal->data->targetAcceptKey][] = $signal->data->data;
        }
        return $responses;
    }

    /**
     * @param PageResponseSignalData $response The page's subscription or live response
     * @return array<string, mixed> Card wire fields
     */
    private function cardOf(PageResponseSignalData $response): array
    {
        return $response->payload->toArray()[PagePayload::data][LanguageCardBrowserData::DATA];
    }

    /**
     * @param array<string, mixed> $expected Selected card fields to compare
     */
    private function assertCardsForBoth(array $expected): void
    {
        $responses = $this->drainCardResponses();
        self::assertCount(2, $responses);
        foreach ($responses as $response) {
            $card = $this->cardOf($response);
            foreach ($expected as $key => $value) {
                if ($key === LanguageCardBrowserData::FIELD_SUMMARY) {
                    foreach ($value as $field => $fieldValue) {
                        self::assertSame($fieldValue, $card[$key][$field]);
                    }
                } else {
                    self::assertSame($value, $card[$key]);
                }
            }
        }
    }

    private function clearFixtures(): void
    {
        Database::sqlRun("DELETE FROM hilos_country_name WHERE country_id IN (SELECT id FROM hilos_country WHERE code = 'qx')"
            . " OR language_id IN (SELECT id FROM hilos_language WHERE code IN ('en', 'qx', 'qy'))");
        Database::sqlRun("DELETE FROM hilos_language_name WHERE language_id IN (SELECT id FROM hilos_language WHERE code IN ('en', 'qx', 'qy'))"
            . " OR in_language_id IN (SELECT id FROM hilos_language WHERE code IN ('en', 'qx', 'qy'))");
        Database::sqlRun("DELETE FROM hilos_locale WHERE language_id IN (SELECT id FROM hilos_language WHERE code IN ('en', 'qx', 'qy'))");
        Database::sqlRun("DELETE FROM hilos_country WHERE code = 'qx'");
        Database::sqlRun("DELETE FROM hilos_language WHERE code IN ('en', 'qx', 'qy')");
        foreach ([
            Hilos::$db->countryNames,
            Hilos::$db->languageNames,
            Hilos::$db->locales,
            Hilos::$db->countries,
            Hilos::$db->languages,
        ] as $collection) {
            $collection->getObjectCollection()?->reHydrate();
            $collection->clearCache();
        }
    }
}
