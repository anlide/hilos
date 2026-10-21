<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAnalyticsAgent;
use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Analytics\BrowserSessionPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Analytics\AnalyticsStore;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketTableViewportSignalDTO;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** Real page subscriptions and table windows over a bounded analytics fixture. */
final class AnalyticsBrowserSessionPageIntegrationTest extends IntegrationTestCase
{
    private const string OWNER = 'analytics-page-test';
    private const string USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0.0.0 Safari/537.36';
    private const array CLEANUP_ORDER = [
        'hilos_analytics_user_action', 'hilos_analytics_page_session', 'hilos_analytics_ws_connection',
        'hilos_analytics_browser_session_alias', 'hilos_analytics_browser_session', 'hilos_analytics_page',
        'hilos_analytics_page_params', 'hilos_analytics_action_name', 'hilos_analytics_payload_json', 'hilos_analytics_user_agent',
    ];

    private AnalyticsStore $store;
    private PageSignalRouter $router;

    /** @var array<string, int> Analytics high water marks before this case */
    private array $baseline = [];

    /** @var list<int> Account rows created by this case */
    private array $userIds = [];

    /** @var list<int> Authentication sessions created by this case */
    private array $sessionIds = [];

    /** Prepares a real browser context, subscription dispatcher and one admin connection. */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        Hilos::$sr = new SignalRouter();
        $this->store = new AnalyticsStore();
        $this->router = new PageSignalRouter(new HilosPageFactory(new DemoHilosAnalyticsAgent(), Hilos::class), new ActionRouteConfig());
        foreach (self::CLEANUP_ORDER as $table) {
            Database::sql('SELECT COALESCE(MAX(`id`), 0) AS `id` FROM `' . $table . '`');
            $this->baseline[$table] = (int)Database::field('id');
        }
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::OWNER);
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        Hilos::$rt->connections->actions->clear();
        $this->connect('analytics-admin', $this->user('Analytics admin', true));
    }

    /** Removes only this case's fixture and restores the viewer switch. */
    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        RtTruthSourceRegistry::unregisterAgent(self::OWNER);
        foreach ($this->sessionIds as $id) {
            if (isset(Hilos::$db->sessions[$id])) {
                Hilos::$db->sessions[$id]->actions->delete();
            }
        }
        foreach ($this->userIds as $id) {
            if (isset(Hilos::$db->users[$id])) {
                Hilos::$db->users[$id]->actions->delete();
            }
        }
        foreach ($this->baseline as $table => $id) {
            Database::sqlRun('DELETE FROM `' . $table . '` WHERE `id` > ?', [$id]);
        }
        Hilos::$sr = null;
        parent::tearDown();
    }

    /** The first answer has the whole header; the preset then selects only this session's ordered actions. */
    public function testAdminHeaderColdWindowAndPresetUseOneSession(): void
    {
        $userId = $this->user('Last signed in');
        $id = $this->browserSession('private-session-token', $userId, self::USER_AGENT);
        $other = $this->browserSession('other-session-token');
        $this->store->openWsConnection('private-accept-key', '192.0.2.123', 100);
        $this->store->attachWsConnection('private-accept-key', $id, 100);
        $this->store->openPageSession(str_repeat('a', 32), 'private-accept-key', 'profile', ['private' => 'route-secret'], 100);
        $late = $this->store->insertUserAction(str_repeat('b', 32), 'private-accept-key', str_repeat('a', 32),
            'rename', ['private' => 'legacy-body-secret'], 300);
        $early = $this->store->insertUserAction(str_repeat('c', 32), 'private-accept-key', null, 'sign_in', null, 200);
        $this->store->openWsConnection('other-accept-key', null, 100);
        $this->store->attachWsConnection('other-accept-key', $other, 100);
        $this->store->insertUserAction(str_repeat('d', 32), 'other-accept-key', null, 'other_action', null, 150);
        $this->store->openWsConnection('unattached-accept-key', null, 100);
        $this->store->insertUserAction(str_repeat('e', 32), 'unattached-accept-key', null, 'unattached_action', null, 150);
        $this->store->renameBrowserSession('private-session-token', 'renamed-session-token', 400);

        $response = $this->subscribe('analytics-admin', $id);
        self::assertSame('Browser session #' . $id, $response->payload->data[PageCatalogConstants::WIRE_PAGE_LABEL]);
        self::assertArrayNotHasKey('label', $response->payload->data);
        self::assertSame([
            'id' => $id, 'browser' => 'Chrome 128 · Windows',
            'lastSignedInUserId' => $userId, 'lastSignedInUserLabel' => 'Last signed in',
        ], $response->payload->data[BrowserSessionPage::SECTION]);
        self::assertSame([], $response->payload->toArray()[PagePayload::windows][ChatTableContext::hilosAnalyticsActions]['rows']);
        $this->assertNoSecrets($response);

        $window = $this->window('analytics-admin', $id);
        self::assertSame(2, $window->totalCount);
        self::assertSame([$early, $late], array_column($window->rows, 'rowKey'));
        self::assertSame([
            ['rowKey' => $early, 'createdTs' => 200, 'pageName' => null, 'actionName' => 'sign_in'],
            ['rowKey' => $late, 'createdTs' => 300, 'pageName' => 'profile', 'actionName' => 'rename'],
        ], array_map(static fn(array $row): array => $row['slots']['action'], $window->rows));
        $this->assertNoSecrets($window);

        Hilos::$db->users[$userId]->actions->rename('Current name');
        self::assertSame('Current name',
            $this->subscribe('analytics-admin', $id)->payload->data[BrowserSessionPage::SECTION]['lastSignedInUserLabel']);
    }

    /** A guest session exists without actions; missing and merged-away numeric addresses are 404. */
    public function testEmptyGuestAndMissingOrMergedSession(): void
    {
        $id = $this->browserSession('empty-session-token');
        self::assertSame([
            'id' => $id, 'browser' => null, 'lastSignedInUserId' => null, 'lastSignedInUserLabel' => null,
        ], $this->subscribe('analytics-admin', $id)->payload->data[BrowserSessionPage::SECTION]);
        self::assertSame([], $this->window('analytics-admin', $id)->rows);
        $this->assertSubscriptionError(['browserSessionId' => (string)PHP_INT_MAX], 404, 'not_found');
        $survivor = $this->browserSession('survivor-session-token');
        $this->store->renameBrowserSession('empty-session-token', 'survivor-session-token', 200);
        $this->assertSubscriptionError(['browserSessionId' => (string)$id], 404, 'not_found');
        self::assertSame($survivor, $this->subscribe('analytics-admin', $survivor)->payload->data[BrowserSessionPage::SECTION]['id']);

        $this->expectException(PageResourceNotFoundException::class);
        ExecutionContext::run(new ExecutionFrame(acceptKey: 'analytics-admin'), static function () use ($id): void {
            new BrowserSessionPage(new DemoHilosAnalyticsAgent())->onSubscribe(
                'analytics-admin', new PageRouteParams(['browserSessionId' => (string)$id]),
            );
        });
    }

    /** Deleting the account keeps its last signed-in number and never reuses its old name. */
    public function testDeletedAccountLabelIsReadAfresh(): void
    {
        $userId = $this->user('Name before deletion');
        $id = $this->browserSession('deleted-account-session', $userId);
        self::assertSame('Name before deletion',
            $this->subscribe('analytics-admin', $id)->payload->data[BrowserSessionPage::SECTION]['lastSignedInUserLabel']);
        Hilos::$db->users[$userId]->actions->delete();
        self::assertSame([
            'id' => $id, 'browser' => null, 'lastSignedInUserId' => $userId, 'lastSignedInUserLabel' => 'Deleted user #' . $userId,
        ], $this->subscribe('analytics-admin', $id)->payload->data[BrowserSessionPage::SECTION]);
    }

    /** Anonymous and signed-in viewers receive the catalog label, hidden header and hidden action values. */
    public function testViewersReceiveNoAnalyticsValues(): void
    {
        $id = $this->browserSession('private-session-token', $this->user('Private account'), self::USER_AGENT);
        $this->store->openWsConnection('private-accept-key', '192.0.2.123', 100);
        $this->store->attachWsConnection('private-accept-key', $id, 100);
        $actionId = $this->store->insertUserAction(str_repeat('f', 32), 'private-accept-key', null,
            'private_action', ['private' => 'legacy-body-secret'], 200);
        $this->connect('analytics-viewer', $this->user('Viewer'));
        $this->connect('analytics-anonymous', null);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);

        foreach (['analytics-viewer', 'analytics-anonymous'] as $acceptKey) {
            $response = $this->subscribe($acceptKey, $id);
            self::assertSame('Browser session', $response->payload->data[PageCatalogConstants::WIRE_PAGE_LABEL]);
            self::assertTrue(HiddenValue::isMark($response->payload->data[BrowserSessionPage::SECTION]));
            self::assertSame([], $response->payload->toArray()[PagePayload::windows][ChatTableContext::hilosAnalyticsActions]['rows']);
            $window = $this->window($acceptKey, $id);
            self::assertCount(1, $window->rows);
            self::assertSame(['rowKey', 'createdTs', 'pageName', 'actionName'], array_keys($window->rows[0]['slots']['action']));
            self::assertSame($actionId, $window->rows[0]['rowKey']);
            self::assertSame($actionId, $window->rows[0]['slots']['action']['rowKey']);
            foreach (['createdTs', 'pageName', 'actionName'] as $field) {
                self::assertTrue(HiddenValue::isMark($window->rows[0]['slots']['action'][$field]));
            }
            self::assertSame(['rowKey' => $actionId], $window->firstAnchor->values);
            self::assertSame(['rowKey' => $actionId], $window->lastAnchor->values);
            self::assertNull(Hilos::$sr->getTableViewport($acceptKey, ChatTableContext::hilosAnalyticsActions)->sort);
            $this->assertNoSecrets($response);
            $this->assertNoSecrets($window);
            $wire = json_encode([$response->toArray(), $window->toArray()], JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('Private account', $wire);
            self::assertStringNotContainsString('private_action', $wire);
            self::assertStringNotContainsString('Browser session #', $wire);
        }
    }

    /** Route validation and the inherited admin gate retain the framework's subscription error codes. */
    public function testInvalidParamsAndClosedAdminGate(): void
    {
        foreach ([[], ['browserSessionId' => '']] as $params) {
            $this->assertSubscriptionError($params, 400, 'missing_page_route_param');
        }
        foreach (['text', '1.5', '0', '-1'] as $value) {
            $this->assertSubscriptionError(['browserSessionId' => $value], 400, 'invalid_page_route_param');
        }
        $id = $this->browserSession('gate-session');
        $this->connect('analytics-viewer', $this->user('Viewer'));
        $this->assertSubscriptionError(['browserSessionId' => (string)$id], 403, 'forbidden', 'analytics-viewer');
        $this->connect('analytics-anonymous', null);
        $this->assertSubscriptionError(['browserSessionId' => (string)$id], 401, 'unauthorized', 'analytics-anonymous');
    }

    /**
     * @param string $name Fixture name
     * @param bool $admin Whether the account is an admin
     * @return int Account number
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
     * @param ?int $userId Signed-in account, or a guest
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
     * @param ?int $userId Last signed-in account
     * @param ?string $userAgent Raw browser description
     * @return int Analytics session number
     */
    private function browserSession(string $token, ?int $userId = null, ?string $userAgent = null): int
    {
        $id = $this->store->ensureBrowserSession($token, $userAgent, null, 100);
        if ($userId !== null) {
            $this->store->setBrowserSessionIdentity($token, 'user_id', (string)$userId, 100);
        }
        return $id;
    }

    /**
     * @param string $acceptKey Subscriber
     * @param int $id Numeric session address
     * @return PageResponseSignalData Only opening response
     */
    private function subscribe(string $acceptKey, int $id): PageResponseSignalData
    {
        $frames = $this->dispatchSubscribe($acceptKey, [HilosPageRouteParams::HILOS_ANALYTICS_BROWSER_SESSION_ID => (string)$id]);
        self::assertCount(1, $frames);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $frames[0]['name']);
        self::assertInstanceOf(PageResponseSignalData::class, $frames[0]['data']);
        return $frames[0]['data'];
    }

    /**
     * @param string $acceptKey Subscriber
     * @param int $id Preset subject
     * @return TableWindowSignalData Only requested table window
     */
    private function window(string $acceptKey, int $id): TableWindowSignalData
    {
        $this->drain();
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), function () use ($acceptKey, $id): void {
            $this->router->dispatchTableViewport(new WebSocketTableViewportSignalDTO(
                acceptKey: $acceptKey, page: BrowserSessionPage::PAGE, tableKey: ChatTableContext::hilosAnalyticsActions,
                filter: [HilosAnalyticsActionsTable::FILTER_BROWSER_SESSION => $id],
                sort: new HilosAnalyticsActionsTable()->defaultSort(), limit: 25,
            ), '', BrowserSessionPage::PAGE);
        });
        $frames = $this->drain();
        self::assertCount(1, $frames);
        self::assertInstanceOf(TableWindowSignalData::class, $frames[0]['data']);
        return $frames[0]['data'];
    }

    /**
     * @param array<string, string> $params Raw route parameters
     * @param int $httpCode Expected HTTP equivalent
     * @param string $errorCode Expected framework error
     * @param string $acceptKey Subscriber
     */
    private function assertSubscriptionError(array $params, int $httpCode, string $errorCode, string $acceptKey = 'analytics-admin'): void
    {
        $frames = $this->dispatchSubscribe($acceptKey, $params);
        self::assertCount(1, $frames);
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $frames[0]['data']);
        self::assertSame($httpCode, $frames[0]['data']->httpCode);
        self::assertSame($errorCode, $frames[0]['data']->errorCode);
    }

    /**
     * @param string $acceptKey Subscriber
     * @param array<string, string> $params Raw route parameters
     * @return list<array{name: string, data: SignalDataInterface}> Browser frames from one subscription
     */
    private function dispatchSubscribe(string $acceptKey, array $params): array
    {
        $this->drain();
        $request = new WebSocketPageSubscribeSignalDTO($acceptKey, BrowserSessionPage::PAGE, $params);
        Hilos::$sr->subscribeToPage(BrowserSessionPage::PAGE, $request);
        Hilos::$sr->reportTableWindows($acceptKey, []);
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), function () use ($request): void {
            $this->router->dispatchPageSubscribe($request, '', BrowserSessionPage::PAGE);
        });
        return $this->drain();
    }

    /** @return list<array{name: string, data: SignalDataInterface}> Queued browser frames, ignoring worker control traffic */
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

    /** @param SignalDataInterface $frame Page or window payload whose secrets must stay server-side */
    private function assertNoSecrets(SignalDataInterface $frame): void
    {
        $wire = json_encode($frame->toArray(), JSON_THROW_ON_ERROR);
        foreach (['private-session-token', 'renamed-session-token', 'private-accept-key', '192.0.2.123', self::USER_AGENT,
            'legacy-body-secret', 'route-secret', 'session_token', 'old_token', 'new_token', 'accept_key', 'user_agent',
            'payload_json', 'firstSeenTs', 'lastSeenTs', 'actionCount', 'other_action', 'unattached_action'] as $secret) {
            self::assertStringNotContainsString($secret, $wire);
        }
    }
}
