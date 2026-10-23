<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\I18nPage;
use Demo\Chat\Pages\Hilos\I18n\Details\CountryDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Details\CountryNamesPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageErrorMessages;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\Entity\Item\Country as EntityCountry;
use Hilos\Database\Entity\Item\CountryName as EntityCountryName;
use Hilos\Database\Entity\Item\Locale as EntityLocale;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\I18n\Browser\CountryCardBrowserData;
use Hilos\I18n\DTO\CountryCardSummary;
use Hilos\I18n\DTO\I18nOverviewData;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\MeasurementSystem;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/** The country card reads its name, default locale and deletion verdict from persisted rows. */
final class I18nCountryCardTest extends IntegrationTestCase
{
    private I18nCountryServingWorker $worker;
    private I18nLibraryAgent $agent;
    private string|false $previousDefaultLanguage;
    private bool $overviewViewerMode = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->worker = new I18nCountryServingWorker();
        $this->agent = $this->worker->library();
        Hilos::initBrowser();
        Hilos::$browser->bindPageResender($this->worker);
        $this->previousDefaultLanguage = getenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name);
        putenv(EnvConstants::HILOS_DEFAULT_LANGUAGE->name . '=en');
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
        if ($this->overviewViewerMode) {
            Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
            RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        }
        foreach (['card-one', 'card-two', 'card-viewer', 'card-detail', 'card-names', 'overview-admin', 'overview-viewer'] as $acceptKey) {
            SourceInterestRegistry::releaseConsumer(SourceConsumer::page($acceptKey));
        }
        ExecutionContext::setCurrentAgentId(null);
        ExecutionContext::setCurrentAcceptKey(null);
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

    public function testSummaryReadsTheBaseNameDefaultLocaleAndVerdictInPriorityOrder(): void
    {
        $this->underAgent($this->agent, static function (): void {
            $english = Hilos::$db->languages->actions->create('en', 'English', false);
            $german = Hilos::$db->languages->actions->create('de', 'Deutsch', false);
            $french = Hilos::$db->languages->actions->create('fr', 'Français', false);
            $known = Hilos::$db->countries->actions->create('us', '$', 'USD');
            $own = Hilos::$db->countries->actions->create('qx', '¤', 'XQX');

            self::assertSame([
                'name' => null,
                'isOwn' => false,
                'defaultLocaleId' => null,
            'defaultLocaleCode' => null,
                'defaultLocale' => CountryCardSummary::DEFAULT_LOCALE_MISSING,
                'localeCount' => 0,
                'nameCount' => 0,
                'locales' => [],
                'catalogDefaultLocaleCode' => 'en-US',
                'canDelete' => false,
                'deleteReason' => CountryCardSummary::DELETE_KNOWN,
            ], Hilos::$db->countries->cardSummary($known->id)->toArray());
            Hilos::$db->countryNames->actions->createCatalogBase($known, $english, 'United States');
            self::assertSame('United States', Hilos::$db->countries->cardSummary($known->id)->name);

            self::assertSame([
                'name' => null,
                'isOwn' => true,
                'defaultLocaleId' => null,
            'defaultLocaleCode' => null,
                'defaultLocale' => CountryCardSummary::DEFAULT_LOCALE_MISSING,
                'localeCount' => 0,
                'nameCount' => 0,
                'locales' => [],
                'catalogDefaultLocaleCode' => null,
                'canDelete' => true,
                'deleteReason' => null,
            ], Hilos::$db->countries->cardSummary($own->id)->toArray());

            Hilos::$db->countryNames->actions->createManual($own, $german, null, 'Qxland');
            $foreignName = Hilos::$db->countries->cardSummary($own->id);
            self::assertNull($foreignName->name);
            self::assertSame(CountryCardSummary::DELETE_NAMES, $foreignName->deleteReason);

            Hilos::$db->countryNames->actions->createManual($own, $english, null, '');
            self::assertNull(Hilos::$db->countries->cardSummary($own->id)->name);

            self::assertSame(CountryCardSummary::DEFAULT_LOCALE_MISSING, Hilos::$db->countries->cardSummary($own->id)->defaultLocale);

            $locale = Hilos::$db->locales->actions->create(
                $english,
                $own,
                'YYYY-MM-DD',
                'HH:mm:ss',
                '1,000.00',
                '+XX-XXXX-XXXX',
                'Street, House, City, Index',
                MeasurementSystem::METRIC,
                'und',
            );
            $locale->actions->switchOn();
            $disabledLocale = Hilos::$db->locales->actions->create(
                $german,
                $own,
                'YYYY-MM-DD',
                'HH:mm:ss',
                '1,000.00',
                '+XX-XXXX-XXXX',
                'Street, House, City, Index',
                MeasurementSystem::METRIC,
                'und',
            );
            Hilos::$db->countryNames->actions->createManual($own, $french, null, 'Qxlande');
            Hilos::$db->countryNames->actions->createManual($own, $english, $locale, 'Qxland EN');

            Hilos::$db->countries['qx']->actions->update('¤', 'XQX', $locale->id);
            $withLocale = Hilos::$db->countries->cardSummary($own->id);
            self::assertSame('en-QX', $withLocale->defaultLocaleCode);
            self::assertSame(CountryCardSummary::DEFAULT_LOCALE_ON, $withLocale->defaultLocale);
            self::assertSame(2, $withLocale->localeCount);
            self::assertSame(2, $withLocale->nameCount);
            self::assertSame([
                ['localeId' => Hilos::$db->locales['de-QX']->id, 'code' => 'de-QX', 'enabled' => false],
                ['localeId' => Hilos::$db->locales['en-QX']->id, 'code' => 'en-QX', 'enabled' => true],
            ], $withLocale->toArray()['locales']);
            self::assertNull($withLocale->catalogDefaultLocaleCode);
            self::assertSame(CountryCardSummary::DELETE_LOCALES, $withLocale->deleteReason);
            self::assertFalse($withLocale->canDelete);

            $locale->actions->switchOff();
            $withOffLocale = Hilos::$db->countries->cardSummary($own->id);
            self::assertSame(CountryCardSummary::DEFAULT_LOCALE_OFF, $withOffLocale->defaultLocale);

            Hilos::$db->countries['qx']->actions->switchOn();
            self::assertSame(CountryCardSummary::DELETE_LOCALES, Hilos::$db->countries->cardSummary($own->id)->deleteReason);
        });

        $snapshot = Hilos::$browser->buildSubscribeSnapshot(
            CountryDetailPage::PAGE,
            'i18n-country-card-reader',
            new PageRouteParams(['countryCode' => 'qx']),
        )->toArray();
        self::assertSame([
            'countryId' => Hilos::$db->countries['qx']->id,
            'code' => 'qx',
            'currencySymbol' => '¤',
            'currencyCode' => 'XQX',
            'enabled' => true,
            CountryCardBrowserData::FIELD_SUMMARY => [
                'name' => null,
                'isOwn' => true,
                'defaultLocaleId' => Hilos::$db->locales['en-QX']->id,
            'defaultLocaleCode' => 'en-QX',
                'defaultLocale' => CountryCardSummary::DEFAULT_LOCALE_OFF,
                'localeCount' => 2,
                'nameCount' => 2,
                'locales' => [
                    ['localeId' => Hilos::$db->locales['de-QX']->id, 'code' => 'de-QX', 'enabled' => false],
                    ['localeId' => Hilos::$db->locales['en-QX']->id, 'code' => 'en-QX', 'enabled' => false],
                ],
                'catalogDefaultLocaleCode' => null,
                'canDelete' => false,
                'deleteReason' => CountryCardSummary::DELETE_LOCALES,
            ],
        ], $snapshot[PagePayload::data][CountryCardBrowserData::DATA]);
    }

    public function testTwoSubscriptionsReceiveRowLocaleAndNameChangesFromTheSameCardSource(): void
    {
        $admin = Hilos::$db->users->actions->createWithName('Country card admin');
        $admin->actions->setAdmin(true);
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', __METHOD__), 0, 32));
        $session->actions->bindUser((int)$admin->id);
        foreach (['card-one', 'card-two'] as $acceptKey) {
            Hilos::$rt->connections->actions->register($acceptKey, (int)$admin->id, $session->token, (int)$session->id);
        }

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages->actions->create('en', 'English', false);
            Hilos::$db->countries->actions->create('qx', '¤', 'XQX');
        });
        $countryId = Hilos::$db->countries['qx']->id;
        foreach (['card-one', 'card-two'] as $acceptKey) {
            $this->subscribe($acceptKey, 'qx');
            $responses = $this->drainCardResponses();
            self::assertCount(1, $responses);
            self::assertSame('XQX', $this->cardOf($responses[0])['currencyCode']);
            $this->assertBoundCountry($acceptKey, $countryId);
        }

        $country = Hilos::$db->countries['qx'];
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->countries['qx']->actions->update('Q', 'QXQ', null);
        });
        Hilos::$browser->record(SourceChange::dbUpdated(
            HilosDbContext::countries,
            (string)$country->id,
            [EntityCountry::currency_symbol => 'Q', EntityCountry::currency_code => 'QXQ'],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertCardsForBoth(['currencySymbol' => 'Q', 'currencyCode' => 'QXQ']);

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->locales->actions->create(
                Hilos::$db->languages['en'],
                Hilos::$db->countries['qx'],
                'YYYY-MM-DD',
                'HH:mm:ss',
                '1,000.00',
                '+XX-XXXX-XXXX',
                'Street, House, City, Index',
                MeasurementSystem::METRIC,
                'und',
            );
        });
        $locale = Hilos::$db->locales['en-QX'];
        Hilos::$browser->record(SourceChange::dbCreated(
            HilosDbContext::locales,
            (string)$locale->id,
            [EntityLocale::language_id => Hilos::$db->languages['en']->id, EntityLocale::country_id => $country->id],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertCardsForBoth(['summary' => [
            'localeCount' => 1,
            'locales' => [['localeId' => Hilos::$db->locales['en-QX']->id, 'code' => 'en-QX', 'enabled' => false]],
            'catalogDefaultLocaleCode' => null,
            'deleteReason' => CountryCardSummary::DELETE_LOCALES,
        ]]);

        $this->underAgent($this->agent, static function () use ($locale): void {
            Hilos::$db->countries['qx']->actions->update('Q', 'QXQ', $locale->id);
        });
        Hilos::$browser->record(SourceChange::dbUpdated(
            HilosDbContext::countries,
            (string)$country->id,
            [EntityCountry::default_locale_id => $locale->id],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertCardsForBoth(['summary' => [
            'defaultLocaleId' => Hilos::$db->locales['en-QX']->id,
            'defaultLocaleCode' => 'en-QX',
            'defaultLocale' => CountryCardSummary::DEFAULT_LOCALE_OFF,
        ]]);

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->locales['en-QX']->actions->switchOn();
        });
        Hilos::$browser->record(SourceChange::dbUpdated(
            HilosDbContext::locales,
            (string)$locale->id,
            [EntityLocale::enabled => true],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertCardsForBoth(['summary' => [
            'defaultLocale' => CountryCardSummary::DEFAULT_LOCALE_ON,
            'locales' => [['localeId' => Hilos::$db->locales['en-QX']->id, 'code' => 'en-QX', 'enabled' => true]],
        ]]);

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->countries['qx']->actions->switchOn();
        });
        Hilos::$browser->record(SourceChange::dbUpdated(
            HilosDbContext::countries,
            (string)$country->id,
            [EntityCountry::enabled => true],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertCardsForBoth(['enabled' => true]);

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->countryNames->actions->createManual(
                Hilos::$db->countries['qx'],
                Hilos::$db->languages['en'],
                null,
                'Qxland',
            );
        });
        $name = Hilos::$db->countryNames->findBase($country->id, Hilos::$db->languages['en']->id);
        Hilos::$browser->record(SourceChange::dbCreated(
            HilosDbContext::countryNames,
            (string)$name->id,
            [EntityCountryName::country_id => $country->id, EntityCountryName::language_id => Hilos::$db->languages['en']->id],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertCardsForBoth(['summary' => ['name' => 'Qxland', 'deleteReason' => CountryCardSummary::DELETE_LOCALES]]);

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->countries['qx']->actions->switchOff();
            Hilos::$db->countries['qx']->actions->update('Q', 'QXQ', null);
            Hilos::$db->locales['en-QX']->actions->switchOff();
            Hilos::$db->locales['en-QX']->actions->delete();
        });
        Hilos::$browser->record(SourceChange::dbDeleted(
            HilosDbContext::locales,
            (string)$locale->id,
            [EntityLocale::language_id => Hilos::$db->languages['en']->id, EntityLocale::country_id => $country->id],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertCardsForBoth(['summary' => [
            'defaultLocaleId' => null,
            'defaultLocaleCode' => null,
            'defaultLocale' => CountryCardSummary::DEFAULT_LOCALE_MISSING,
            'locales' => [],
            'deleteReason' => CountryCardSummary::DELETE_NAMES,
        ]]);

        $this->underAgent($this->agent, static function () use ($country): void {
            Hilos::$db->countryNames->findBase($country->id, Hilos::$db->languages['en']->id)->actions->delete();
        });
        Hilos::$browser->record(SourceChange::dbDeleted(
            HilosDbContext::countryNames,
            (string)$name->id,
            [EntityCountryName::country_id => $country->id, EntityCountryName::language_id => Hilos::$db->languages['en']->id],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertCardsForBoth(['summary' => ['name' => null, 'canDelete' => true, 'deleteReason' => null]]);

        $viewer = Hilos::$db->users->actions->createWithName('Country disappearance viewer');
        $viewerSession = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', __METHOD__ . 'viewer'), 0, 32));
        $viewerSession->actions->bindUser((int) $viewer->id);
        Hilos::$rt->connections->actions->register(
            'card-viewer',
            (int) $viewer->id,
            $viewerSession->token,
            (int) $viewerSession->id,
        );
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);
        try {
            $this->subscribe('card-viewer', 'qx');
            $opened = $this->drainCardResponses();
            self::assertCount(1, $opened);
            self::assertSame('qx', $this->cardOf($opened[0])['code']);
            $this->assertBoundCountry('card-viewer', $country->id);

            $this->underAgent($this->agent, static function (): void {
                Hilos::$db->countries['qx']->actions->delete();
            });
            $this->worker->recordCommittedSync();
            self::assertSame([], Hilos::$browser->flushToSignalRouter());
            $byKey = $this->drainByAcceptKey();
            $this->assertLiveDeletion($byKey['card-one'] ?? [], CountryDetailPage::PAGE);
            $this->assertLiveDeletion($byKey['card-two'] ?? [], CountryDetailPage::PAGE);
            $this->assertLiveDeletion($byKey['card-viewer'] ?? [], CountryDetailPage::PAGE);
            self::assertNull(Hilos::$sr?->pageResource('card-one'));
            self::assertNull(Hilos::$sr?->pageResource('card-two'));
            self::assertNull(Hilos::$sr?->pageResource('card-viewer'));
        } finally {
            Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
            RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        }
    }

    /** @param class-string<CountryDetailPage|CountryNamesPage> $pageClass Country tab opened by the viewer */
    #[DataProvider('countryPages')]
    public function testAdminViewModeShowsAllNonpersonalCardFieldsToAViewer(string $pageClass): void
    {
        $this->underAgent($this->agent, static function (): void {
            $english = Hilos::$db->languages->actions->create('en', 'English', false);
            $known = Hilos::$db->countries->actions->create('us', '$', 'USD');
            Hilos::$db->countryNames->actions->createCatalogBase($known, $english, 'United States');
        });
        $viewer = Hilos::$db->users->actions->createWithName('Country card viewer');
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', __METHOD__ . $pageClass), 0, 32));
        $session->actions->bindUser((int)$viewer->id);
        Hilos::$rt->connections->actions->register('card-viewer', (int)$viewer->id, $session->token, (int)$session->id);
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);

        try {
            $this->subscribe('card-viewer', 'us', $pageClass);
            $responses = $this->drainCardResponses();
            self::assertCount(1, $responses);
            self::assertSame($pageClass::PAGE, $responses[0]->pageKey);
            $this->assertCountryHead($responses[0]);
            $card = $this->cardOf($responses[0]);
            self::assertSame([
                'countryId' => Hilos::$db->countries['us']->id,
            'code' => 'us',
                'currencySymbol' => '$',
                'currencyCode' => 'USD',
                'enabled' => false,
                'summary' => [
                    'name' => 'United States',
                    'isOwn' => false,
                    'defaultLocaleId' => null,
            'defaultLocaleCode' => null,
                    'defaultLocale' => CountryCardSummary::DEFAULT_LOCALE_MISSING,
                    'localeCount' => 0,
                    'nameCount' => 1,
                    'locales' => [],
                    'catalogDefaultLocaleCode' => 'en-US',
                    'canDelete' => false,
                    'deleteReason' => CountryCardSummary::DELETE_KNOWN,
                ],
            ], $card);
            self::assertStringNotContainsString(HiddenValue::KEY, json_encode($card, JSON_THROW_ON_ERROR));
        } finally {
            Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
            RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        }
    }

    /** @return iterable<string, array{class-string<CountryDetailPage|CountryNamesPage>}> Both real country subscriptions */
    public static function countryPages(): iterable
    {
        yield 'Overview' => [CountryDetailPage::class];
        yield 'Names' => [CountryNamesPage::class];
    }

    public function testNamesPageCarriesTheSameCardAndItsLiveUpdates(): void
    {
        $admin = Hilos::$db->users->actions->createWithName('Country names card admin');
        $admin->actions->setAdmin(true);
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', __METHOD__), 0, 32));
        $session->actions->bindUser((int)$admin->id);
        Hilos::$rt->connections->actions->register('card-detail', (int)$admin->id, $session->token, (int)$session->id);
        Hilos::$rt->connections->actions->register('card-names', (int)$admin->id, $session->token, (int)$session->id);

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages->actions->create('en', 'English', false);
            Hilos::$db->countries->actions->create('qx', '¤', 'XQX');
        });

        $countryId = Hilos::$db->countries['qx']->id;
        $this->subscribe('card-detail', 'qx', CountryDetailPage::class);
        $detailResponses = $this->drainCardResponses();
        self::assertCount(1, $detailResponses);
        self::assertSame(CountryDetailPage::PAGE, $detailResponses[0]->pageKey);
        $this->assertCountryHead($detailResponses[0]);
        $detailCard = $this->cardOf($detailResponses[0]);
        $this->assertBoundCountry('card-detail', $countryId);

        $this->subscribe('card-names', 'qx', CountryNamesPage::class);
        $namesResponses = $this->drainCardResponses();
        self::assertCount(1, $namesResponses);
        self::assertSame(CountryNamesPage::PAGE, $namesResponses[0]->pageKey);
        $this->assertCountryHead($namesResponses[0]);
        $namesCard = $this->cardOf($namesResponses[0]);
        $this->assertBoundCountry('card-names', $countryId);

        self::assertSame($detailCard, $namesCard);
        self::assertSame('XQX', $namesCard['currencyCode']);

        $country = Hilos::$db->countries['qx'];
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->countries['qx']->actions->update('Q', 'QXQ', null);
        });
        Hilos::$browser->record(SourceChange::dbUpdated(
            HilosDbContext::countries,
            (string)$country->id,
            [EntityCountry::currency_symbol => 'Q', EntityCountry::currency_code => 'QXQ'],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertCardsForBoth(['currencySymbol' => 'Q', 'currencyCode' => 'QXQ']);

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->countryNames->actions->createManual(
                Hilos::$db->countries['qx'],
                Hilos::$db->languages['en'],
                null,
                'Qxland',
            );
        });
        $name = Hilos::$db->countryNames->findBase($country->id, Hilos::$db->languages['en']->id);
        Hilos::$browser->record(SourceChange::dbCreated(
            HilosDbContext::countryNames,
            (string)$name->id,
            [EntityCountryName::country_id => $country->id, EntityCountryName::language_id => Hilos::$db->languages['en']->id],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertCardsForBoth(['summary' => ['name' => 'Qxland']]);

        $this->underAgent($this->agent, static function () use ($country, $name): void {
            $name->actions->delete();
            $country->actions->delete();
        });
        $this->worker->recordCommittedSync();
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $byKey = $this->drainByAcceptKey();
        $this->assertLiveDeletion($byKey['card-detail'] ?? [], CountryDetailPage::PAGE);
        $this->assertLiveDeletion($byKey['card-names'] ?? [], CountryNamesPage::PAGE);
        self::assertNull(Hilos::$sr?->pageResource('card-detail'));
        self::assertNull(Hilos::$sr?->pageResource('card-names'));
    }

    public function testOverviewSubscriptionAndCommittedActionsDeliverTheSameWholeToAdminAndViewer(): void
    {
        $this->prepareOverview();
        $this->assertOverviewForBoth(1, 3, 1, 2, 1, 1, 'Installation English', initial: true);
        $englishId = Hilos::$db->languages['en']->id;
        $countryId = Hilos::$db->countries['qx']->id;
        $this->underAgent($this->agent, function () use ($englishId, $countryId): void {
            JournalReceiptScope::run(JournalReceiptData::agent('test.i18n.overview', $this->agent->getId()), function () use (
                $englishId,
                $countryId,
            ): void {
                Database::transactionStart();
                try {
                    Hilos::$db->languages[$englishId]->actions->update('Current English', false);
                    Hilos::$db->languages[$englishId]->actions->switchOn();
                    Hilos::$db->languages->actions->create('de', 'Deutsch', false)->actions->switchOn();
                    Hilos::$db->countries[$countryId]->actions->switchOff();
                    Hilos::$db->countries->actions->create('gb', '£', 'GBP')->actions->switchOn();
                    Hilos::$db->countries->actions->create('qz', '¤', 'XQZ');
                    $this->worker->recordCommittedSync();
                    self::assertSame([], Hilos::$browser->flushToSignalRouter());
                    self::assertSame([], $this->drainByAcceptKey());
                    Database::transactionCommit();
                } catch (Throwable $failure) {
                    Database::transactionRollbackSilent();
                    throw $failure;
                }
            });
        });
        $this->worker->recordCommittedSync();
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertOverviewForBoth(3, 4, 1, 4, 2, 2, 'Current English');
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        self::assertSame([], $this->drainByAcceptKey());

        $languageId = Hilos::$db->languages['qx']->id;
        $countryId = Hilos::$db->countries['qz']->id;
        $this->underAgent($this->agent, function () use ($languageId, $countryId): void {
            JournalReceiptScope::run(JournalReceiptData::agent('test.i18n.overview.delete', $this->agent->getId()), static function () use (
                $languageId,
                $countryId,
            ): void {
                Hilos::$db->languages[$languageId]->actions->delete();
                Hilos::$db->countries[$countryId]->actions->delete();
            });
        });
        $this->worker->recordCommittedSync();
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertOverviewForBoth(3, 3, 1, 3, 2, 1, 'Current English');
    }

    public function testOverviewRollbackPublishesNothingAndRestoresThePersistedRows(): void
    {
        $this->prepareOverview();
        $this->assertOverviewForBoth(1, 3, 1, 2, 1, 1, 'Installation English', initial: true);
        $englishId = Hilos::$db->languages['en']->id;
        $countryId = Hilos::$db->countries['qx']->id;
        $this->underAgent($this->agent, function () use ($englishId, $countryId): void {
            JournalReceiptScope::run(JournalReceiptData::agent('test.i18n.overview.rollback', $this->agent->getId()), function () use (
                $englishId,
                $countryId,
            ): void {
                Database::transactionStart();
                try {
                    Hilos::$db->languages[$englishId]->actions->update('Rolled back English', false);
                    Hilos::$db->languages->actions->create('de', 'Deutsch', false);
                    Hilos::$db->countries[$countryId]->actions->switchOff();
                    Hilos::$db->countries->actions->create('qz', '¤', 'XQZ');
                    $this->worker->recordCommittedSync();
                    self::assertSame([], Hilos::$browser->flushToSignalRouter());
                    self::assertSame([], $this->drainByAcceptKey());
                } finally {
                    Database::transactionRollback();
                }
            });
        });
        $this->worker->recordCommittedSync();
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        self::assertSame([], $this->drainByAcceptKey());
        self::assertSame([['native_name' => 'Installation English']], Database::sql(
            'SELECT native_name FROM hilos_language WHERE id = ?', [$englishId],
        )->rows());
        self::assertSame([], Database::sql('SELECT id FROM hilos_language WHERE code = ?', ['de'])->rows());
        self::assertSame([], Database::sql('SELECT id FROM hilos_country WHERE code = ?', ['qz'])->rows());
        self::assertSame([['enabled' => '1']], Database::sql('SELECT enabled FROM hilos_country WHERE id = ?', [$countryId])->rows());

        $this->underAgent($this->agent, static function () use ($englishId): void {
            Hilos::$db->languages[$englishId]->actions->update('After rollback', false);
        });
        $this->worker->recordCommittedSync();
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertOverviewForBoth(1, 3, 1, 2, 1, 1, 'After rollback');
    }

    /** Uses this class's existing persisted fixture and worker, without a rollback root that would hold announcements. */
    private function prepareOverview(): void
    {
        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages->actions->create('en', 'Installation English', false);
            Hilos::$db->languages->actions->create('fr', 'Français', false)->actions->switchOn();
            Hilos::$db->languages->actions->create('qx', 'Project language', false);
            Hilos::$db->countries->actions->create('us', '$', 'USD');
            Hilos::$db->countries->actions->create('qx', '¤', 'XQX')->actions->switchOn();
        });
        foreach (['overview-admin' => true, 'overview-viewer' => false] as $acceptKey => $admin) {
            $user = Hilos::$db->users->actions->createWithName($acceptKey);
            if ($admin) {
                $user->actions->setAdmin(true);
            }
            $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', __METHOD__ . $this->name() . $acceptKey), 0, 32));
            $session->actions->bindUser((int) $user->id);
            Hilos::$rt->connections->actions->register($acceptKey, (int) $user->id, $session->token, (int) $session->id);
        }
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);
        $this->overviewViewerMode = true;
        $this->worker->recordCommittedSync();
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->drainByAcceptKey();
        foreach (['overview-admin', 'overview-viewer'] as $acceptKey) {
            $this->worker->deliver(
                SignalTypeConstants::PAGE_SUBSCRIBE,
                new WebSocketPageSubscribeSignalDTO($acceptKey, I18nPage::PAGE),
                I18nPage::PAGE,
            );
        }
    }

    /**
     * @param int $enabledLanguages Enabled languages
     * @param int $totalLanguages Installed languages
     * @param int $enabledCountries Enabled countries
     * @param int $totalCountries Installed countries
     * @param int $builtIn Built-in countries
     * @param int $own Custom countries
     * @param string $defaultName Current native name of the default language
     * @param bool $initial Whether the worker also sends its initial freshness frame
     */
    private function assertOverviewForBoth(
        int $enabledLanguages,
        int $totalLanguages,
        int $enabledCountries,
        int $totalCountries,
        int $builtIn,
        int $own,
        string $defaultName,
        bool $initial = false,
    ): void {
        $byKey = $this->drainByAcceptKey();
        self::assertSame(['overview-admin', 'overview-viewer'], array_keys($byKey));
        $overviews = [];
        foreach ($byKey as $frames) {
            self::assertSame(
                $initial ? [SignalTypeConstants::RT_STALENESS, SignalTypeConstants::PAGE_RESPONSE] : [SignalTypeConstants::PAGE_RESPONSE],
                array_map(static fn (SignalDTO $frame): string => $frame->signalName->getName(), $frames),
            );
            $responseFrame = $frames[array_key_last($frames)];
            self::assertInstanceOf(PageResponseSignalData::class, $responseFrame->data->data);
            $response = $responseFrame->data->data;
            self::assertSame(I18nPage::PAGE, $response->pageKey);
            $overviews[] = $response->payload->data[I18nOverviewData::DATA];
            self::assertSame([
                'languages' => [
                    'enabledCount' => $enabledLanguages, 'totalCount' => $totalLanguages,
                    'defaultLanguageCode' => 'en', 'defaultLanguageName' => $defaultName,
                ],
                'countries' => [
                    'enabledCount' => $enabledCountries, 'totalCount' => $totalCountries,
                    'builtInCount' => $builtIn, 'ownCount' => $own,
                ],
            ], $response->payload->data[I18nOverviewData::DATA]);
            self::assertSame('Internationalization', $response->payload->data[PageCatalogConstants::WIRE_PAGE_LABEL]);
            self::assertSame([
                'page' => HilosPageConstants::HILOS_I18N,
                'label' => 'Internationalization',
                'lead' => 'Languages, countries, locales, and translation screens.',
                'icon' => 'bi-translate',
            ], $response->payload->data[PageCatalogConstants::WIRE_PAGE_HEAD_OWNER]);
            self::assertSame([
                ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                ['page' => HilosPageConstants::HILOS_I18N, 'label' => 'Internationalization'],
            ], $response->payload->data[PageCatalogConstants::WIRE_PAGE_BREADCRUMB]);
            self::assertSame([], $response->payload->data[PageCatalogConstants::WIRE_PAGE_CHILDREN]);
            self::assertStringNotContainsString(HiddenValue::KEY, json_encode($overviews[array_key_last($overviews)], JSON_THROW_ON_ERROR));
        }
        self::assertSame($overviews[0], $overviews[1]);
    }

    /**
     * @param string $acceptKey One open browser window
     * @param string $code Route code
     * @param class-string<CountryDetailPage|CountryNamesPage> $pageClass Page class to subscribe to
     */
    private function subscribe(
        string $acceptKey,
        string $code,
        string $pageClass = CountryDetailPage::class,
    ): void {
        $this->worker->deliver(
            SignalTypeConstants::PAGE_SUBSCRIBE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, $pageClass::PAGE, ['countryCode' => $code]),
            $pageClass::PAGE,
        );
    }

    /**
     * @param string $acceptKey Connection whose answer named the country
     * @param int|string $countryId Primary id that answer bound
     */
    private function assertBoundCountry(string $acceptKey, int|string $countryId): void
    {
        $resource = Hilos::$sr?->pageResource($acceptKey);
        self::assertNotNull($resource);
        self::assertSame(HilosDbContext::countries, $resource->collectionKey);
        self::assertSame((string) $countryId, $resource->idString);
    }

    /**
     * @return array<string, list<SignalDTO>> Frames addressed to a connection, in queue order
     */
    private function drainByAcceptKey(): array
    {
        $byKey = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if (!$signal->data instanceof WebSocketSignalData || $signal->data->targetAcceptKey === null) {
                continue;
            }
            $byKey[$signal->data->targetAcceptKey][] = $signal;
        }

        return $byKey;
    }

    /**
     * @param list<SignalDTO> $signals Frames addressed to one connection
     * @param string $page Page the refusal names
     */
    private function assertLiveDeletion(array $signals, string $page): void
    {
        self::assertCount(1, $signals);
        self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signals[0]->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $signals[0]->data);
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $signals[0]->data->data);
        $missing = new PageResourceNotFoundException();
        $text = PageErrorMessages::forStatus($missing->httpCode);
        self::assertSame([
            'page' => $page,
            'httpCode' => $missing->httpCode,
            'errorCode' => $missing->errorCode,
            'title' => $text->title,
            'message' => $text->message,
            PageSubscriptionErrorSignalData::transitionMessage => PageErrorMessages::RESOURCE_DELETED_MESSAGE,
        ], $signals[0]->data->data->toArray());
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

    /**
     * @param PageResponseSignalData $response The page's subscription or live response
     * @return array<string, mixed> Card wire fields
     */
    private function cardOf(PageResponseSignalData $response): array
    {
        return $response->payload->toArray()[PagePayload::data][CountryCardBrowserData::DATA];
    }

    /** @param PageResponseSignalData $response The one frame carrying identity and the browser snapshot */
    private function assertCountryHead(PageResponseSignalData $response): void
    {
        self::assertSame([
            'page' => HilosPageConstants::HILOS_I18N_COUNTRY,
            'label' => 'Country',
            'lead' => 'A single country: currency, default locale, and state.',
        ], $response->payload->data[PageCatalogConstants::WIRE_PAGE_HEAD_OWNER] ?? null);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_I18N, 'label' => 'Internationalization'],
            ['page' => HilosPageConstants::HILOS_I18N_COUNTRIES, 'label' => 'Countries'],
            ['page' => HilosPageConstants::HILOS_I18N_COUNTRY, 'label' => 'Country'],
        ], $response->payload->data[PageCatalogConstants::WIRE_PAGE_BREADCRUMB]);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_I18N_COUNTRY, 'label' => 'Overview'],
            ['page' => HilosPageConstants::HILOS_I18N_COUNTRY_NAMES, 'label' => 'Names'],
        ], $response->payload->data[PageCatalogConstants::WIRE_PAGE_TABS] ?? null);
        self::assertSame([], $response->payload->data[PageCatalogConstants::WIRE_PAGE_CHILDREN]);
        self::assertSame(
            $response->pageKey === CountryDetailPage::PAGE ? 'Country' : 'Country names',
            $response->payload->data[PageCatalogConstants::WIRE_PAGE_LABEL],
        );
        self::assertSame(
            $response->pageKey === CountryDetailPage::PAGE
                ? 'A single country: currency, default locale, and state.'
                : 'How this country is named in each language.',
            $response->payload->data[PageCatalogConstants::WIRE_PAGE_LEAD],
        );
        if ($response->pageKey === CountryNamesPage::PAGE) {
            self::assertArrayHasKey(ChatTableContext::hilosI18nCountryNames, $response->payload->windows);
        }
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
                if ($key === CountryCardBrowserData::FIELD_SUMMARY) {
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
        Database::sqlRun('UPDATE hilos_country SET default_locale_id = NULL');
        Database::sqlRun('DELETE FROM hilos_country_name');
        Database::sqlRun('DELETE FROM hilos_language_name');
        Database::sqlRun('DELETE FROM hilos_locale');
        Database::sqlRun('DELETE FROM hilos_country');
        Database::sqlRun('DELETE FROM hilos_i18n_reflow');
        Database::sqlRun('DELETE FROM hilos_language');
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
