<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAnalyticsAgent;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Analytics\PeoplePage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Analytics\AnalyticsPeriodFilter;
use Hilos\Core\Analytics\AnalyticsStore;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Pages\Users\AccountStandingAudience;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketTableViewportSignalDTO;
use Hilos\Tables\Analytics\HilosAnalyticsPeopleTable;
use Hilos\Tables\Analytics\HilosAnalyticsPersonTableRow;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountStandingResolver;

/** The mounted People page answers once with its head and table, including the admin-view-mode path. */
final class AnalyticsPeoplePageIntegrationTest extends IntegrationTestCase
{
    private const string OWNER = 'analytics-people-page-test';
    private const string ADMIN = 'analytics-people-admin';
    private const array CONTEXT = ['timeZone' => 'UTC', 'weekStartTs' => 0, 'dayStartTs' => 100, 'hourStartTs' => 200];

    private PageSignalRouter $router;

    /** @var list<int> Accounts created by the case */
    private array $userIds = [];

    /** @var list<int> Authentication sessions created by the case */
    private array $sessionIds = [];

    /** @var list<int> Analytics browser sessions created by the case */
    private array $browserSessionIds = [];

    /** Mounts the real project browser and router with one authenticated administrator. */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        Hilos::$sr = new ChatSignalRouter();
        $this->router = new PageSignalRouter(
            new HilosPageFactory(new DemoHilosAnalyticsAgent(), Hilos::class), new ActionRouteConfig(),
        );
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::OWNER);
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        Hilos::$rt->connections->actions->clear();
        AccountStandingAudience::reset();
        AccountStandingResolver::forgetAll();
        $this->connect(self::ADMIN, $this->user('People admin', true));
    }

    /** Releases the case's runtime claims and removes only its fixture rows. */
    protected function tearDown(): void
    {
        AccountStandingAudience::reset();
        AccountStandingResolver::forgetAll();
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        RtTruthSourceRegistry::unregisterAgent(self::OWNER);
        foreach ($this->browserSessionIds as $id) {
            Database::sqlRun('DELETE FROM `hilos_analytics_browser_session` WHERE `id` = ?', [$id]);
        }
        foreach ($this->sessionIds as $id) {
            Hilos::$db->sessions[$id]->actions->delete();
        }
        foreach ($this->userIds as $id) {
            if (isset(Hilos::$db->users[$id])) {
                Hilos::$db->users[$id]->actions->delete();
            }
        }
        Hilos::$sr = null;
        parent::tearDown();
    }

    /** The table context arrives on the viewport; opening and updating the page need no calendar route DTO. */
    public function testOpeningAndUpdateAnswerOnceWithTheCatalogAndOnlyThePeopleWindow(): void
    {
        $personId = $this->user('People window person');
        $this->browserSession('people-window-token', $personId, 300);
        $response = $this->subscribe(self::ADMIN);
        $this->assertPeoplePayload($response);
        $initial = $response->payload->windows[ChatTableContext::hilosAnalyticsPeople];
        self::assertSame([], $initial['rows']);
        self::assertSame(0, $initial['totalCount']);
        self::assertSame([ChatTableContext::hilosAnalyticsPeople => []], Hilos::PAGE_TABLES[PeoplePage::PAGE]);

        $window = $this->window(self::ADMIN);
        self::assertSame([$personId], array_column($window->rows, 'rowKey'));
        self::assertSame([
            HilosAnalyticsPersonTableRow::name => 'People window person',
            HilosAnalyticsPersonTableRow::sessionCount => 1,
            HilosAnalyticsPersonTableRow::lastSeenTs => 300,
        ], $window->rows[0]['slots']['person']);
        self::assertSame(1, $window->totalCount);

        ExecutionContext::run(new ExecutionFrame(acceptKey: self::ADMIN), function (): void {
            $this->router->dispatchPageUpdateSubscription(
                new WebSocketPageUpdateSubscriptionSignalDTO(self::ADMIN, PeoplePage::PAGE), '', PeoplePage::PAGE,
            );
        });
        $frames = $this->drain();
        self::assertCount(1, $frames);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $frames[0]['name']);
        self::assertInstanceOf(PageResponseSignalData::class, $frames[0]['data']);
        $this->assertPeoplePayload($frames[0]['data']);
    }

    /** Signed-in and anonymous viewers keep numeric row keys without any personal slot value. */
    public function testViewersReceiveTheSameHeadAndHiddenPersonSlots(): void
    {
        $first = $this->user('Private People name');
        $deleted = $this->user('Deleted private People name');
        $this->browserSession('people-private-token', $first, 300);
        $this->browserSession('people-deleted-token', $deleted, 400);
        Hilos::$db->users[$deleted]->actions->delete();
        $this->connect('people-viewer', $this->user('People viewer'));
        $this->connect('people-guest', null);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);

        foreach (['people-viewer', 'people-guest'] as $acceptKey) {
            $response = $this->subscribe($acceptKey);
            $this->assertPeoplePayload($response);
            $window = $this->window($acceptKey);
            self::assertSame([$first, $deleted], array_column($window->rows, 'rowKey'));
            foreach ($window->rows as $row) {
                self::assertSame([
                    HilosAnalyticsPersonTableRow::name, HilosAnalyticsPersonTableRow::sessionCount, HilosAnalyticsPersonTableRow::lastSeenTs,
                ], array_keys($row['slots']['person']));
                foreach ($row['slots']['person'] as $value) {
                    self::assertTrue(HiddenValue::isMark($value));
                }
            }
            self::assertNull(Hilos::$sr->getTableViewport($acceptKey, ChatTableContext::hilosAnalyticsPeople)->sort);
            $wire = json_encode([$response->toArray(), $window->toArray()], JSON_THROW_ON_ERROR);
            foreach (['Private People name', 'Deleted private People name', 'people-private-token', 'people-deleted-token'] as $secret) {
                self::assertStringNotContainsString($secret, $wire);
            }
        }
    }

    /** The inherited ADMIN gate remains closed while the view-mode switch is off. */
    public function testAdminGateStillRefusesGuestsAndNonAdminsWithoutViewMode(): void
    {
        $this->connect('people-user', $this->user('Ordinary People reader'));
        $this->connect('people-guest', null);
        foreach (['people-user' => 403, 'people-guest' => 401] as $acceptKey => $status) {
            $frames = $this->dispatchSubscribe($acceptKey);
            self::assertCount(1, $frames);
            self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $frames[0]['data']);
            self::assertSame($status, $frames[0]['data']->httpCode);
        }
    }

    /** @param PageResponseSignalData $response One complete page reply */
    private function assertPeoplePayload(PageResponseSignalData $response): void
    {
        self::assertSame(PeoplePage::PAGE, $response->pageKey);
        self::assertSame([
            PageCatalogConstants::WIRE_PAGE_LABEL => 'People',
            PageCatalogConstants::WIRE_PAGE_LEAD => 'People who last signed into browser sessions seen in the selected period.',
            PageCatalogConstants::WIRE_PAGE_BREADCRUMB => [
                ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
                ['page' => HilosPageConstants::HILOS_ANALYTICS, 'label' => 'Analytics'],
            ],
            PageCatalogConstants::WIRE_PAGE_CHILDREN => [],
            PageCatalogConstants::WIRE_PAGE_HEAD_OWNER => [
                'page' => HilosPageConstants::HILOS_ANALYTICS,
                'label' => 'Analytics',
                'lead' => 'What a browser session did, in order, and which sessions a person signed into.',
                'icon' => 'bi-bar-chart',
            ],
            PageCatalogConstants::WIRE_PAGE_TABS => [
                ['page' => HilosPageConstants::HILOS_ANALYTICS, 'label' => 'Overview'],
                ['page' => HilosPageConstants::HILOS_ANALYTICS_BROWSER_SESSIONS, 'label' => 'Browser sessions'],
                ['page' => HilosPageConstants::HILOS_ANALYTICS_PEOPLE, 'label' => 'People'],
            ],
        ], $response->payload->data);
        self::assertSame([ChatTableContext::hilosAnalyticsPeople], array_keys($response->payload->windows));
        self::assertSame([], $response->payload->refusedWindows);
        self::assertSame([], $response->payload->tables);
        self::assertSame([], $response->payload->entities);
        self::assertSame([], $response->payload->lists);
    }

    /**
     * @param string $acceptKey Subscriber
     * @return PageResponseSignalData Only opening response
     */
    private function subscribe(string $acceptKey): PageResponseSignalData
    {
        $frames = $this->dispatchSubscribe($acceptKey);
        self::assertCount(1, $frames);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $frames[0]['name']);
        self::assertInstanceOf(PageResponseSignalData::class, $frames[0]['data']);
        return $frames[0]['data'];
    }

    /**
     * @param string $acceptKey Subscriber
     * @return list<array{name: string, data: SignalDataInterface}> Frames from one subscription
     */
    private function dispatchSubscribe(string $acceptKey): array
    {
        $this->drain();
        $request = new WebSocketPageSubscribeSignalDTO($acceptKey, PeoplePage::PAGE, []);
        Hilos::$sr->subscribeToPage(PeoplePage::PAGE, $request);
        Hilos::$sr->reportTableWindows($acceptKey, []);
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), function () use ($request): void {
            $this->router->dispatchPageSubscribe($request, '', PeoplePage::PAGE);
        });
        return $this->drain();
    }

    /**
     * @param string $acceptKey Subscriber
     * @return TableWindowSignalData Only requested window
     */
    private function window(string $acceptKey): TableWindowSignalData
    {
        $this->drain();
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), function () use ($acceptKey): void {
            $this->router->dispatchTableViewport(new WebSocketTableViewportSignalDTO(
                acceptKey: $acceptKey, page: PeoplePage::PAGE, tableKey: ChatTableContext::hilosAnalyticsPeople,
                filter: [AnalyticsPeriodFilter::period => AnalyticsPeriodFilter::TODAY] + self::CONTEXT,
                sort: new HilosAnalyticsPeopleTable()->defaultSort(), limit: 25,
            ), '', PeoplePage::PAGE);
        });
        $frames = $this->drain();
        self::assertCount(1, $frames);
        self::assertInstanceOf(TableWindowSignalData::class, $frames[0]['data']);
        return $frames[0]['data'];
    }

    /**
     * @param string $name Fixture name
     * @param bool $admin Whether to grant admin rights
     * @return int Created account number
     */
    private function user(string $name, bool $admin = false): int
    {
        $user = Hilos::$db->users->actions->createWithName($name);
        $this->userIds[] = (int)$user->id;
        if ($admin) {
            $user->actions->setAdmin(true);
        }
        return (int)$user->id;
    }

    /**
     * @param string $acceptKey Connection address
     * @param ?int $userId Signed-in account, or guest
     */
    private function connect(string $acceptKey, ?int $userId): void
    {
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', $acceptKey), 0, 32));
        $this->sessionIds[] = (int)$session->id;
        if ($userId !== null) {
            $session->actions->bindUser($userId);
        }
        Hilos::$rt->connections->actions->register($acceptKey, $userId, $session->token, (int)$session->id);
    }

    /**
     * @param string $token Private fixture token
     * @param int $userId Last person to sign in
     * @param int $lastSeenTs Fixture activity moment
     */
    private function browserSession(string $token, int $userId, int $lastSeenTs): void
    {
        $store = new AnalyticsStore();
        $this->browserSessionIds[] = $store->ensureBrowserSession($token, null, null, 100);
        $store->setBrowserSessionIdentity($token, 'user_id', (string)$userId, $lastSeenTs);
    }

    /** @return list<array{name: string, data: SignalDataInterface}> Queued browser frames without worker control traffic */
    private function drain(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                $frames[] = ['name' => $signal->signalName->getName(), 'data' => $signal->data->data];
            }
        }
        return $frames;
    }
}
