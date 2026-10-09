<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageDetailPage;
use Demo\Chat\Pages\Hilos\I18n\Details\LanguageNamesPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Language as EntityLanguage;
use Hilos\Database\Entity\Item\LanguageName as EntityLanguageName;
use Hilos\I18n\Browser\LanguageCardBrowserData;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\I18n\HilosI18nLanguageNamesTable;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** The language names page delivers the card alongside the names table window. */
final class I18nLanguageNamesPageTest extends IntegrationTestCase
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

    public function testSubscriptionDeliversBothCardDataAndNamesWindow(): void
    {
        $admin = Hilos::$db->users->actions->createWithName('Language names admin');
        $admin->actions->setAdmin(true);
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', __METHOD__), 0, 32));
        $session->actions->bindUser((int)$admin->id);
        foreach (['main-sub', 'names-sub'] as $acceptKey) {
            Hilos::$rt->connections->actions->register($acceptKey, (int)$admin->id, $session->token, (int)$session->id);
        }

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages->actions->create('en', 'English', false);
            Hilos::$db->languages->actions->create('qx', 'Test language', false);
        });

        $this->subscribeMain('main-sub', 'qx');
        $mainResponses = $this->drainCardResponses();
        self::assertCount(1, $mainResponses);
        $mainCard = $this->cardOf($mainResponses[0]);

        $this->subscribeNames('names-sub', 'qx');
        $namesCardResponses = $this->drainCardResponses();
        self::assertCount(1, $namesCardResponses);
        $namesCard = $this->cardOf($namesCardResponses[0]);

        self::assertSame('qx', $namesCard['code']);
        self::assertSame('Test language', $namesCard['nativeName']);
        self::assertSame($mainCard, $namesCard);

        $payload = $namesCardResponses[0]->payload->toArray();
        self::assertArrayHasKey(PagePayload::windows, $payload);
        self::assertArrayHasKey(ChatTableContext::hilosI18nLanguageNames, $payload[PagePayload::windows]);
        $window = $payload[PagePayload::windows][ChatTableContext::hilosI18nLanguageNames];
        $wireRows = $window[TableWindowSignalData::rows];
        self::assertNotEmpty($wireRows);
        self::assertSame('en', $wireRows[0][BrowserPageSignalData::rowKey]);
    }

    public function testBothMainAndNamesSubscribersReceiveCardUpdateWhenManualNameIsCreated(): void
    {
        $admin = Hilos::$db->users->actions->createWithName('Language names admin');
        $admin->actions->setAdmin(true);
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', __METHOD__), 0, 32));
        $session->actions->bindUser((int)$admin->id);
        foreach (['main-sub', 'names-sub'] as $acceptKey) {
            Hilos::$rt->connections->actions->register($acceptKey, (int)$admin->id, $session->token, (int)$session->id);
        }

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages->actions->create('en', 'English', false);
            Hilos::$db->languages->actions->create('qx', 'Test language', false);
        });

        $this->subscribeMain('main-sub', 'qx');
        $this->subscribeNames('names-sub', 'qx');
        $this->drainCardResponses();

        $language = Hilos::$db->languages['qx'];
        $inLanguage = Hilos::$db->languages['en'];
        $this->underAgent($this->agent, static function () use ($language, $inLanguage): void {
            Hilos::$db->languageNames->actions->createManual(
                $language,
                $inLanguage,
                null,
                'Own name',
            );
        });
        $name = Hilos::$db->languageNames->findBase($language->id, $inLanguage->id);
        self::assertNotNull($name);
        Hilos::$browser->record(SourceChange::dbCreated(
            HilosDbContext::languageNames,
            (string)$name->id,
            [
                EntityLanguageName::language_id => $language->id,
                EntityLanguageName::in_language_id => $inLanguage->id,
            ],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());

        $responses = $this->drainCardResponses();
        self::assertCount(2, $responses);
        foreach ($responses as $response) {
            $card = $this->cardOf($response);
            self::assertSame(1, $card[LanguageCardBrowserData::FIELD_SUMMARY]['nameCount']);
        }
    }

    public function testLanguageDeletionClearsCardForNamesPageSubscriber(): void
    {
        $admin = Hilos::$db->users->actions->createWithName('Language names admin');
        $admin->actions->setAdmin(true);
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', __METHOD__), 0, 32));
        $session->actions->bindUser((int)$admin->id);
        Hilos::$rt->connections->actions->register('names-sub', (int)$admin->id, $session->token, (int)$session->id);

        $this->underAgent($this->agent, static function (): void {
            Hilos::$db->languages->actions->create('en', 'English', false);
            Hilos::$db->languages->actions->create('qx', 'Test language', false);
        });

        $this->subscribeNames('names-sub', 'qx');
        $this->drainCardResponses();

        $language = Hilos::$db->languages['qx'];
        $this->underAgent($this->agent, static function () use ($language): void {
            $language->actions->delete();
        });
        Hilos::$browser->record(SourceChange::dbDeleted(
            HilosDbContext::languages,
            (string)$language->id,
            [EntityLanguage::id => $language->id, EntityLanguage::code => 'qx'],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());

        $responses = $this->drainCardResponses();
        self::assertCount(1, $responses);
        self::assertSame([], $this->cardOf($responses[0]));
    }

    private function subscribeMain(string $acceptKey, string $code): void
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

    private function subscribeNames(string $acceptKey, string $code): void
    {
        $params = ['languageCode' => $code];
        $windows = [
            ChatTableContext::hilosI18nLanguageNames => new TableWindowDescriptorDTO(
                filter: [HilosI18nLanguageNamesTable::FILTER_LANGUAGE => $code],
            ),
        ];
        Hilos::$sr->subscribeToPage(
            LanguageNamesPage::PAGE,
            new WebSocketPageSubscribeSignalDTO(
                $acceptKey,
                LanguageNamesPage::PAGE,
                $params,
                $windows,
            ),
        );
        Hilos::$sr->reportTableWindows($acceptKey, $windows);
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), function () use ($acceptKey, $params): void {
            new LanguageNamesPage($this->agent)->onSubscribe($acceptKey, new PageRouteParams($params));
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
            $payload = $signal->data->data->payload->toArray();
            if (array_key_exists(LanguageCardBrowserData::DATA, $payload[PagePayload::data] ?? [])) {
                $responses[] = $signal->data->data;
            }
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
