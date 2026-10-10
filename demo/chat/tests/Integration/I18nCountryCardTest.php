<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\I18n\Details\CountryDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Details\CountryNamesPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
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
use Hilos\Database\Entity\Item\Country as EntityCountry;
use Hilos\Database\Entity\Item\CountryName as EntityCountryName;
use Hilos\Database\Entity\Item\Locale as EntityLocale;
use Hilos\I18n\Browser\CountryCardBrowserData;
use Hilos\I18n\DTO\CountryCardSummary;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\MeasurementSystem;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** The country card reads its name, default locale and deletion verdict from persisted rows. */
final class I18nCountryCardTest extends IntegrationTestCase
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

    public function testSummaryReadsTheBaseNameDefaultLocaleAndVerdictInPriorityOrder(): void
    {
        $this->underAgent($this->agent, static function (): void {
            $english = Hilos::$db->languages->actions->create('en', 'English', false);
            $german = Hilos::$db->languages->actions->create('de', 'Deutsch', false);
            $known = Hilos::$db->countries->actions->create('us', '$', 'USD');
            $own = Hilos::$db->countries->actions->create('qx', '¤', 'XQX');

            self::assertSame([
                'name' => null,
                'isOwn' => false,
                'defaultLocaleCode' => null,
                'canDelete' => false,
                'deleteReason' => CountryCardSummary::DELETE_KNOWN,
            ], Hilos::$db->countries->cardSummary($known->id)->toArray());
            Hilos::$db->countryNames->actions->createCatalogBase($known, $english, 'United States');
            self::assertSame('United States', Hilos::$db->countries->cardSummary($known->id)->name);

            self::assertSame([
                'name' => null,
                'isOwn' => true,
                'defaultLocaleCode' => null,
                'canDelete' => true,
                'deleteReason' => null,
            ], Hilos::$db->countries->cardSummary($own->id)->toArray());

            Hilos::$db->countryNames->actions->createManual($own, $german, null, 'Qxland');
            $foreignName = Hilos::$db->countries->cardSummary($own->id);
            self::assertNull($foreignName->name);
            self::assertSame(CountryCardSummary::DELETE_NAMES, $foreignName->deleteReason);

            Hilos::$db->countryNames->actions->createManual($own, $english, null, '');
            self::assertNull(Hilos::$db->countries->cardSummary($own->id)->name);

            $locale = Hilos::$db->locales->actions->create(
                $english,
                $own,
                'Y-m-d',
                'H:i',
                '1,234.56',
                '+1 555',
                'street, city',
                MeasurementSystem::METRIC,
                'und',
            );
            Hilos::$db->countries['qx']->actions->update('¤', 'XQX', $locale->id);
            $withLocale = Hilos::$db->countries->cardSummary($own->id);
            self::assertSame('en-QX', $withLocale->defaultLocaleCode);
            self::assertSame(CountryCardSummary::DELETE_LOCALES, $withLocale->deleteReason);
            self::assertFalse($withLocale->canDelete);

            Hilos::$db->countries['qx']->actions->switchOn();
            self::assertSame(CountryCardSummary::DELETE_LOCALES, Hilos::$db->countries->cardSummary($own->id)->deleteReason);
        });

        $snapshot = Hilos::$browser->buildSubscribeSnapshot(
            CountryDetailPage::PAGE,
            'i18n-country-card-reader',
            new PageRouteParams(['countryCode' => 'qx']),
        )->toArray();
        self::assertSame([
            'code' => 'qx',
            'currencySymbol' => '¤',
            'currencyCode' => 'XQX',
            'enabled' => true,
            CountryCardBrowserData::FIELD_SUMMARY => [
                'name' => null,
                'isOwn' => true,
                'defaultLocaleCode' => 'en-QX',
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
        foreach (['card-one', 'card-two'] as $acceptKey) {
            $this->subscribe($acceptKey, 'qx');
            $responses = $this->drainCardResponses();
            self::assertCount(1, $responses);
            self::assertSame('XQX', $this->cardOf($responses[0])['currencyCode']);
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
                'Y-m-d',
                'H:i',
                '1,234.56',
                '+1 555',
                'street, city',
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
        $this->assertCardsForBoth(['summary' => ['deleteReason' => CountryCardSummary::DELETE_LOCALES]]);

        $this->underAgent($this->agent, static function () use ($locale): void {
            Hilos::$db->countries['qx']->actions->update('Q', 'QXQ', $locale->id);
        });
        Hilos::$browser->record(SourceChange::dbUpdated(
            HilosDbContext::countries,
            (string)$country->id,
            [EntityCountry::default_locale_id => $locale->id],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertCardsForBoth(['summary' => ['defaultLocaleCode' => 'en-QX']]);

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
            Hilos::$db->locales['en-QX']->actions->delete();
        });
        Hilos::$browser->record(SourceChange::dbDeleted(
            HilosDbContext::locales,
            (string)$locale->id,
            [EntityLocale::language_id => Hilos::$db->languages['en']->id, EntityLocale::country_id => $country->id],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertCardsForBoth(['summary' => ['defaultLocaleCode' => null, 'deleteReason' => CountryCardSummary::DELETE_NAMES]]);

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

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->countries['qx']->actions->delete();
        });
        Hilos::$browser->record(SourceChange::dbDeleted(
            HilosDbContext::countries,
            (string)$country->id,
            [EntityCountry::id => $country->id, EntityCountry::code => 'qx'],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $responses = $this->drainCardResponses();
        self::assertCount(2, $responses);
        foreach ($responses as $response) {
            self::assertSame([], $this->cardOf($response));
        }
    }

    public function testAdminViewModeShowsAllNonpersonalCardFieldsToAViewer(): void
    {
        $this->underAgent($this->agent, static function (): void {
            $english = Hilos::$db->languages->actions->create('en', 'English', false);
            $known = Hilos::$db->countries->actions->create('us', '$', 'USD');
            Hilos::$db->countryNames->actions->createCatalogBase($known, $english, 'United States');
        });
        $viewer = Hilos::$db->users->actions->createWithName('Country card viewer');
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', __METHOD__), 0, 32));
        $session->actions->bindUser((int)$viewer->id);
        Hilos::$rt->connections->actions->register('card-viewer', (int)$viewer->id, $session->token, (int)$session->id);
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);

        try {
            $this->subscribe('card-viewer', 'us');
            $responses = $this->drainCardResponses();
            self::assertCount(1, $responses);
            $card = $this->cardOf($responses[0]);
            self::assertSame([
                'code' => 'us',
                'currencySymbol' => '$',
                'currencyCode' => 'USD',
                'enabled' => false,
                'summary' => [
                    'name' => 'United States',
                    'isOwn' => false,
                    'defaultLocaleCode' => null,
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

        $this->subscribe('card-detail', 'qx', CountryDetailPage::class);
        $detailResponses = $this->drainCardResponses();
        self::assertCount(1, $detailResponses);
        $detailCard = $this->cardOf($detailResponses[0]);

        $this->subscribe('card-names', 'qx', CountryNamesPage::class);
        $namesResponses = $this->drainCardResponses();
        self::assertCount(1, $namesResponses);
        $namesCard = $this->cardOf($namesResponses[0]);

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
        Hilos::$browser->record(SourceChange::dbDeleted(
            HilosDbContext::countries,
            (string)$country->id,
            [EntityCountry::id => $country->id, EntityCountry::code => 'qx'],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $responses = $this->drainCardResponses();
        self::assertCount(2, $responses);
        foreach ($responses as $response) {
            self::assertSame([], $this->cardOf($response));
        }
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
        $params = ['countryCode' => $code];
        Hilos::$sr->subscribeToPage(
            $pageClass::PAGE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, $pageClass::PAGE, $params),
        );
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), function () use ($acceptKey, $params, $pageClass): void {
            new $pageClass($this->agent)->onSubscribe($acceptKey, new PageRouteParams($params));
        });
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
