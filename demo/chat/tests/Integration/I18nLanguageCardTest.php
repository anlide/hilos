<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageDetailPage;
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
use Hilos\Database\Entity\Item\Language as EntityLanguage;
use Hilos\Database\Entity\Item\LanguageName as EntityLanguageName;
use Hilos\Database\Entity\Item\Locale as EntityLocale;
use Hilos\I18n\Browser\LanguageCardBrowserData;
use Hilos\I18n\DTO\LanguageCardSummary;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\MeasurementSystem;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\TruthSource\RtTruthSourceRegistry;

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
                'nameCount' => 0,
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
            self::assertSame(LanguageCardSummary::DELETE_LOCALES, $withLocale->deleteReason);
        });

        $snapshot = Hilos::$browser->buildSubscribeSnapshot(
            LanguageDetailPage::PAGE,
            'i18n-language-card-reader',
            new PageRouteParams(['languageCode' => 'qx']),
        )->toArray();
        self::assertSame([
            'code' => 'qx',
            'nativeName' => 'Own language',
            'rtl' => true,
            'enabled' => false,
            LanguageCardBrowserData::FIELD_SUMMARY => [
                'isDefault' => false,
                'isOwn' => true,
                'localeCount' => 1,
                'nameCount' => 1,
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
                'code' => 'qx',
                'nativeName' => 'Viewer language',
                'rtl' => true,
                'enabled' => false,
                'summary' => [
                    'isDefault' => false,
                    'isOwn' => true,
                    'localeCount' => 0,
                    'nameCount' => 0,
                    'canDelete' => true,
                    'deleteReason' => null,
                ],
            ], $card);
            self::assertStringNotContainsString(HiddenValue::KEY, json_encode($card, JSON_THROW_ON_ERROR));
        } finally {
            Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
            RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        }
    }

    /**
     * @param string $acceptKey One open browser window
     * @param string $code Route code
     */
    private function subscribe(string $acceptKey, string $code): void
    {
        $params = ['languageCode' => $code];
        Hilos::$sr->subscribeToPage(
            LanguageDetailPage::PAGE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, LanguageDetailPage::PAGE, $params),
        );
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), function () use ($acceptKey, $params): void {
            new LanguageDetailPage($this->agent)->onSubscribe($acceptKey, new PageRouteParams($params));
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
