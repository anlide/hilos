<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Agents\Hilos\DemoHilosAnalyticsAgent;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\Database;
use Demo\Chat\Database\Pages\PageCatalog;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Analytics\PersonPage;
use Demo\Chat\Pages\Hilos\Users\UserPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Analytics\AnalyticsStore;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseConnectionConfig;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Files\HilosFiles;
use Hilos\Files\Upload\ProfilePhotoUploadTarget;
use Hilos\Hilos as HilosFacade;
use Hilos\Pages\Users\AccountStandingAudience;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketTableViewportSignalDTO;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionTableRow;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountStandingResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/** Real person-page subscriptions and session windows over a bounded analytics fixture, and the card's link to them. */
final class AnalyticsPersonPageIntegrationTest extends IntegrationTestCase
{
    private const string OWNER = 'analytics-person-page-test';
    private const string ADMIN = 'analytics-person-admin';
    private const string USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0.0.0 Safari/537.36';
    private const string SLOT = 'browserSession';
    private const array CLEANUP_ORDER = [
        'hilos_analytics_user_action', 'hilos_analytics_page_session', 'hilos_analytics_ws_connection',
        'hilos_analytics_browser_session_alias', 'hilos_analytics_browser_session', 'hilos_analytics_page',
        'hilos_analytics_page_params', 'hilos_analytics_action_name', 'hilos_analytics_payload_json', 'hilos_analytics_user_agent',
    ];

    private AnalyticsStore $store;
    private PageSignalRouter $analyticsRouter;
    private PageSignalRouter $cardRouter;

    /** @var class-string<HilosFacade> Facade class the process had bound before this case */
    private string $boundAppClass;

    /** @var array<string, int> Analytics high water marks before this case */
    private array $baseline = [];

    /** @var list<int> Account rows created by this case */
    private array $userIds = [];

    /** @var list<int> Authentication sessions created by this case */
    private array $sessionIds = [];

    /** @var array<int, int> Published fixture files, keyed by registry id with their person's id */
    private array $photoFiles = [];

    /** Prepares a real browser context, both subscription dispatchers and one admin connection. */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        $this->boundAppClass = HilosFacade::appClass();
        Hilos::$sr = new ChatSignalRouter();
        $this->store = new AnalyticsStore();
        $this->analyticsRouter = new PageSignalRouter(
            new HilosPageFactory(new DemoHilosAnalyticsAgent(), Hilos::class), new ActionRouteConfig(),
        );
        $this->cardRouter = new PageSignalRouter(new HilosPageFactory(new DemoHilosAgent(), Hilos::class), new ActionRouteConfig());
        foreach (self::CLEANUP_ORDER as $table) {
            Database::sql('SELECT COALESCE(MAX(`id`), 0) AS `id` FROM `' . $table . '`');
            $this->baseline[$table] = (int)Database::field('id');
        }
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::OWNER);
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        Hilos::$rt->connections->actions->clear();
        AccountStandingAudience::reset();
        AccountStandingResolver::forgetAll();
        $this->connect(self::ADMIN, $this->user('Analytics person admin', true));
    }

    /** Removes only this case's fixture and restores the viewer switch and the bound facade. */
    protected function tearDown(): void
    {
        $this->bindAppClass($this->boundAppClass);
        AccountStandingAudience::reset();
        AccountStandingResolver::forgetAll();
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        RtTruthSourceRegistry::unregisterAgent(self::OWNER);
        foreach ($this->photoFiles as $fileId => $userId) {
            $this->underAgent(
                $this->personAgent($userId),
                static fn(): ?int => Hilos::$db->userPhotos->actions->deleteForUser($userId),
            );
            $storedName = Hilos::$db->files[$fileId]->storedName;
            Hilos::$db->files[$fileId]->actions->delete();
            Hilos::$fs->files[$storedName]->unlink();
        }
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

    /** The first answer names the person under the catalog shell; the preset then selects only their sessions, newest first. */
    public function testAdminHeaderColdWindowAndPresetSelectOnePersonsSessions(): void
    {
        $personId = $this->user('Olena Kovalenko');
        $otherId = $this->user('Somebody else');
        $older = $this->browserSession('person-token-a', $personId, 100, 300, self::USER_AGENT);
        $this->store->openWsConnection('person-accept-key', '192.0.2.44', 100);
        $this->store->attachWsConnection('person-accept-key', $older, 100);
        $this->store->openPageSession(str_repeat('a', 32), 'person-accept-key', 'profile', ['private' => 'route-secret'], 100);
        $this->store->insertUserAction(str_repeat('b', 32), 'person-accept-key', str_repeat('a', 32),
            'rename', ['private' => 'legacy-body-secret'], 200);
        $this->store->insertUserAction(str_repeat('c', 32), 'person-accept-key', null, 'sign_in', null, 150);
        $this->store->renameBrowserSession('person-token-a', 'person-token-renamed', 300);
        $newest = $this->browserSession('person-token-b', $personId, 200, 500);
        $tied = $this->browserSession('person-token-c', $personId, 250, 300);
        $this->browserSession('other-person-token', $otherId, 100, 400);
        $this->browserSession('guest-token', null, 100, 600);

        $response = $this->subscribe(self::ADMIN, $personId);
        $data = $response->payload->data;
        self::assertSame('Olena Kovalenko (#' . $personId . ')', $data[PageCatalogConstants::WIRE_PAGE_LABEL]);
        self::assertSame('Olena Kovalenko', $data[PersonPage::NAME]);
        self::assertNull($data[PersonPage::PHOTO]);
        self::assertContains(HilosDbContext::users, PersonPage::READS_DB);
        self::assertContains(HilosDbContext::userPhotos, PersonPage::READS_DB);
        self::assertSame(
            'Browser sessions where this person signed in last. Other people may have performed actions in the same browser.',
            $data[PageCatalogConstants::WIRE_PAGE_LEAD],
        );
        self::assertSame(
            [
                HilosPageConstants::HILOS_DASHBOARD, HilosPageConstants::HILOS_ANALYTICS,
                HilosPageConstants::HILOS_ANALYTICS_PEOPLE, HilosPageConstants::HILOS_ANALYTICS_PERSON,
            ],
            array_column($data[PageCatalogConstants::WIRE_PAGE_BREADCRUMB], PageCatalogConstants::WIRE_CRUMB_PAGE),
        );
        self::assertSame([], $data[PageCatalogConstants::WIRE_PAGE_CHILDREN]);
        self::assertSame([
            PageCatalogConstants::WIRE_PAGE_LABEL, PersonPage::NAME, PersonPage::PHOTO, PageCatalogConstants::WIRE_PAGE_LEAD,
            PageCatalogConstants::WIRE_PAGE_BREADCRUMB, PageCatalogConstants::WIRE_PAGE_CHILDREN,
            PageCatalogConstants::WIRE_PAGE_HEAD_OWNER, PageCatalogConstants::WIRE_PAGE_TABS,
        ], array_keys($data));
        $this->assertPersonHead($data);
        self::assertSame([], $response->payload->toArray()[PagePayload::windows][ChatTableContext::hilosAnalyticsBrowserSessions]['rows']);
        $this->assertNoSecrets($response);

        $window = $this->window(self::ADMIN, $personId);
        self::assertSame(3, $window->totalCount);
        self::assertSame([$newest, $tied, $older], array_column($window->rows, 'rowKey'));
        self::assertSame([
            HilosAnalyticsBrowserSessionTableRow::rowKey => $older,
            HilosAnalyticsBrowserSessionTableRow::firstSeenTs => 100,
            HilosAnalyticsBrowserSessionTableRow::lastSeenTs => 300,
            HilosAnalyticsBrowserSessionTableRow::browser => 'Chrome 128 · Windows',
            HilosAnalyticsBrowserSessionTableRow::actionCount => 2,
        ], $window->rows[2]['slots'][self::SLOT]);
        self::assertSame([
            HilosAnalyticsBrowserSessionTableRow::rowKey => $newest,
            HilosAnalyticsBrowserSessionTableRow::firstSeenTs => 200,
            HilosAnalyticsBrowserSessionTableRow::lastSeenTs => 500,
            HilosAnalyticsBrowserSessionTableRow::browser => null,
            HilosAnalyticsBrowserSessionTableRow::actionCount => 0,
        ], $window->rows[0]['slots'][self::SLOT]);
        $this->assertNoSecrets($window);
    }

    /** A living account with no sessions still has its page, with an empty list. */
    public function testLivingPersonWithoutSessionsHasAnEmptyPage(): void
    {
        $personId = $this->user('Nobody signed in');
        $this->browserSession('guest-only-token', null, 100, 200);

        self::assertSame('Nobody signed in (#' . $personId . ')',
            $this->subscribe(self::ADMIN, $personId)->payload->data[PageCatalogConstants::WIRE_PAGE_LABEL]);
        $window = $this->window(self::ADMIN, $personId);
        self::assertSame(0, $window->totalCount);
        self::assertSame([], $window->rows);
    }

    /** Every subscription captures the current raw name and published avatar, without changing an earlier answer. */
    public function testRenameAndPhotoChangesShowOnTheNextSubscription(): void
    {
        $personId = $this->user('Name  before <rename>');
        $withoutPhoto = $this->subscribe(self::ADMIN, $personId);
        self::assertSame('Name  before <rename>', $withoutPhoto->payload->data[PersonPage::NAME]);
        self::assertNull($withoutPhoto->payload->data[PersonPage::PHOTO]);

        $firstFileId = $this->putPhoto($personId);
        $before = $this->subscribe(self::ADMIN, $personId);
        self::assertSame('Name  before <rename> (#' . $personId . ')', $before->payload->data[PageCatalogConstants::WIRE_PAGE_LABEL]);
        self::assertSame('Name  before <rename>', $before->payload->data[PersonPage::NAME]);
        self::assertSame(HilosFiles::downloadPath($firstFileId, ProfilePhotoUploadTarget::VARIANT), $before->payload->data[PersonPage::PHOTO]);
        $this->assertPersonHead($before->payload->data);
        Hilos::$db->users[$personId]->actions->rename('Name after rename');
        $secondFileId = $this->putPhoto($personId);

        $after = $this->subscribe(self::ADMIN, $personId);
        self::assertSame('Name after rename (#' . $personId . ')', $after->payload->data[PageCatalogConstants::WIRE_PAGE_LABEL]);
        self::assertSame('Name after rename', $after->payload->data[PersonPage::NAME]);
        self::assertSame(HilosFiles::downloadPath($secondFileId, ProfilePhotoUploadTarget::VARIANT), $after->payload->data[PersonPage::PHOTO]);
        $this->assertPersonHead($after->payload->data);
        self::assertSame('Name  before <rename>', $before->payload->data[PersonPage::NAME]);
        self::assertSame(HilosFiles::downloadPath($firstFileId, ProfilePhotoUploadTarget::VARIANT), $before->payload->data[PersonPage::PHOTO]);
        self::assertNull($withoutPhoto->payload->data[PersonPage::PHOTO]);

        $this->underAgent(
            $this->personAgent($personId),
            static fn(): ?int => Hilos::$db->userPhotos->actions->deleteForUser($personId),
        );
        self::assertNull($this->subscribe(self::ADMIN, $personId)->payload->data[PersonPage::PHOTO]);
    }

    /** An existing published photo stays absent from the answer when the project disables profile photos. */
    public function testProfilePhotoFeatureOffReturnsNull(): void
    {
        $personId = $this->user('Photo feature off');
        $fileId = $this->putPhoto($personId);
        self::assertSame(HilosFiles::downloadPath($fileId, ProfilePhotoUploadTarget::VARIANT),
            $this->subscribe(self::ADMIN, $personId)->payload->data[PersonPage::PHOTO]);

        $this->bindAppClass(AnalyticsPersonPageWithoutPhotoHilos::class);
        try {
            $response = $this->subscribe(self::ADMIN, $personId);
            self::assertSame('Photo feature off', $response->payload->data[PersonPage::NAME]);
            self::assertNull($response->payload->data[PersonPage::PHOTO]);
            self::assertStringNotContainsString(HilosFiles::DOWNLOAD_PATH, json_encode($response->toArray(), JSON_THROW_ON_ERROR));
        } finally {
            $this->bindAppClass($this->boundAppClass);
        }
    }

    /** A deleted account keeps a page while analytics names it, under its number only and never its old name. */
    public function testDeletedPersonWithHistoryKeepsANumberedPage(): void
    {
        $personId = $this->user('Name before deletion');
        $id = $this->browserSession('deleted-person-token', $personId, 100, 200);
        $fileId = $this->putPhoto($personId);
        self::assertSame(HilosFiles::downloadPath($fileId, ProfilePhotoUploadTarget::VARIANT),
            $this->subscribe(self::ADMIN, $personId)->payload->data[PersonPage::PHOTO]);
        $this->underAgent(
            $this->personAgent($personId),
            static fn(): ?int => Hilos::$db->userPhotos->actions->deleteForUser($personId),
        );
        Hilos::$db->users[$personId]->actions->delete();

        $response = $this->subscribe(self::ADMIN, $personId);
        self::assertSame('Deleted user #' . $personId, $response->payload->data[PageCatalogConstants::WIRE_PAGE_LABEL]);
        self::assertNull($response->payload->data[PersonPage::NAME]);
        self::assertNull($response->payload->data[PersonPage::PHOTO]);
        $this->assertPersonHead($response->payload->data);
        self::assertStringNotContainsString(HilosFiles::DOWNLOAD_PATH, json_encode($response->toArray(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Name before deletion', json_encode($response->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame([$id], array_column($this->window(self::ADMIN, $personId)->rows, 'rowKey'));
    }

    /** A number that names neither an account nor any session's last sign-in is not found. */
    public function testNoAccountAndNoSessionsIsNotFound(): void
    {
        $this->assertSubscriptionError([HilosPageRouteParams::HILOS_ANALYTICS_PERSON_USER_ID => (string)PHP_INT_MAX], 404, 'not_found');
        $goneId = $this->user('Gone without a trace');
        Hilos::$db->users[$goneId]->actions->delete();
        $this->assertSubscriptionError([HilosPageRouteParams::HILOS_ANALYTICS_PERSON_USER_ID => (string)$goneId], 404, 'not_found');

        $this->expectException(PageResourceNotFoundException::class);
        ExecutionContext::run(new ExecutionFrame(acceptKey: self::ADMIN), static function () use ($goneId): void {
            new PersonPage(new DemoHilosAnalyticsAgent())->onSubscribe(
                self::ADMIN, new PageRouteParams([HilosPageRouteParams::HILOS_ANALYTICS_PERSON_USER_ID => (string)$goneId]),
            );
        });
    }

    /** A failed read of the page's own sources reaches the router as the failure it is, never as an empty page. */
    public function testDatabaseFailureIsNotAnEmptyPage(): void
    {
        $goneId = $this->user('Unreadable person');
        $this->browserSession('unreadable-person-token', $goneId, 100, 200);
        Hilos::$db->users[$goneId]->actions->delete();
        $this->drain();
        $config = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $failure = null;
        try {
            $this->pointPrimaryAt('information_schema', $config);
            ExecutionContext::run(new ExecutionFrame(acceptKey: self::ADMIN), static function () use ($goneId): void {
                new PersonPage(new DemoHilosAnalyticsAgent())->onSubscribe(
                    self::ADMIN, new PageRouteParams([HilosPageRouteParams::HILOS_ANALYTICS_PERSON_USER_ID => (string)$goneId]),
                );
            });
        } catch (DatabaseException $e) {
            $failure = $e;
        } finally {
            $this->pointPrimaryAt($config->database, $config);
        }
        self::assertInstanceOf(DatabaseException::class, $failure);
        self::assertMatchesRegularExpression("/Unknown table '(hilos_user|hilos_analytics_browser_session)'/", $failure->getMessage());
        self::assertSame([], $this->drain());
    }

    /** A photo-source SQL failure cannot become a successful no-photo response for a live person. */
    public function testPhotoDatabaseFailureIsNotNoPhoto(): void
    {
        $personId = $this->user('Unreadable photo');
        $this->drain();
        $config = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $failure = null;
        try {
            $this->pointPrimaryAt('information_schema', $config);
            ExecutionContext::run(new ExecutionFrame(acceptKey: self::ADMIN), static function () use ($personId): void {
                new PersonPage(new DemoHilosAnalyticsAgent())->onSubscribe(
                    self::ADMIN, new PageRouteParams([HilosPageRouteParams::HILOS_ANALYTICS_PERSON_USER_ID => (string)$personId]),
                );
            });
        } catch (DatabaseException $e) {
            $failure = $e;
        } finally {
            $this->pointPrimaryAt($config->database, $config);
        }
        self::assertInstanceOf(DatabaseException::class, $failure);
        self::assertStringContainsString("Unknown table 'hilos_user_photo'", $failure->getMessage());
        self::assertSame([], $this->drain());
    }

    /** @return array<string, array{bool}> Cases where a personal photo is present or absent */
    public static function photoPresence(): array
    {
        return ['without photo' => [false], 'with photo' => [true]];
    }

    /**
     * Anonymous and signed-in viewers receive the catalog label and hidden name, photo and session values.
     *
     * @param bool $withPhoto Whether the person has a published photo
     */
    #[DataProvider('photoPresence')]
    public function testViewersReceiveNoPersonalValues(bool $withPhoto): void
    {
        $personId = $this->user('Private person');
        $fileId = $withPhoto ? $this->putPhoto($personId) : null;
        $first = $this->browserSession('private-person-token-a', $personId, 100, 300, self::USER_AGENT);
        $second = $this->browserSession('private-person-token-b', $personId, 100, 400);
        $this->connect('analytics-person-viewer', $this->user('Viewer'));
        $this->connect('analytics-person-anonymous', null);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);

        foreach (['analytics-person-viewer', 'analytics-person-anonymous'] as $acceptKey) {
            $response = $this->subscribe($acceptKey, $personId);
            self::assertSame('Person', $response->payload->data[PageCatalogConstants::WIRE_PAGE_LABEL]);
            self::assertSame(['_hidden' => true], $response->payload->data[PersonPage::NAME]);
            self::assertSame(['_hidden' => true], $response->payload->data[PersonPage::PHOTO]);
            $this->assertPersonHead($response->payload->data);
            self::assertSame([], $response->payload->toArray()[PagePayload::windows][ChatTableContext::hilosAnalyticsBrowserSessions]['rows']);
            $window = $this->window($acceptKey, $personId);
            self::assertSame([$first, $second], array_column($window->rows, 'rowKey'));
            foreach ($window->rows as $row) {
                $slot = $row['slots'][self::SLOT];
                self::assertSame([
                    HilosAnalyticsBrowserSessionTableRow::rowKey, HilosAnalyticsBrowserSessionTableRow::firstSeenTs,
                    HilosAnalyticsBrowserSessionTableRow::lastSeenTs, HilosAnalyticsBrowserSessionTableRow::browser,
                    HilosAnalyticsBrowserSessionTableRow::actionCount,
                ], array_keys($slot));
                self::assertSame($row['rowKey'], $slot[HilosAnalyticsBrowserSessionTableRow::rowKey]);
                foreach ([
                    HilosAnalyticsBrowserSessionTableRow::firstSeenTs, HilosAnalyticsBrowserSessionTableRow::lastSeenTs,
                    HilosAnalyticsBrowserSessionTableRow::browser, HilosAnalyticsBrowserSessionTableRow::actionCount,
                ] as $field) {
                    self::assertTrue(HiddenValue::isMark($slot[$field]));
                }
            }
            self::assertNull(Hilos::$sr->getTableViewport($acceptKey, ChatTableContext::hilosAnalyticsBrowserSessions)->sort);
            $this->assertNoSecrets($response);
            $this->assertNoSecrets($window);
            $wire = json_encode([$response->toArray(), $window->toArray()], JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('Private person', $wire);
            self::assertStringNotContainsString('(#' . $personId . ')', $wire);
            self::assertStringNotContainsString('Chrome', $wire);
            self::assertStringNotContainsString(HilosFiles::DOWNLOAD_PATH, $wire);
            if ($fileId !== null) {
                self::assertStringNotContainsString(HilosFiles::downloadPath($fileId, ProfilePhotoUploadTarget::VARIANT), $wire);
            }
        }
    }

    /** Route validation and the inherited admin gate retain the framework's subscription error codes. */
    public function testInvalidParamsAndClosedAdminGate(): void
    {
        foreach ([[], [HilosPageRouteParams::HILOS_ANALYTICS_PERSON_USER_ID => '']] as $params) {
            $this->assertSubscriptionError($params, 400, 'missing_page_route_param');
        }
        foreach (['text', '1.5', '0', '-1'] as $value) {
            $this->assertSubscriptionError([HilosPageRouteParams::HILOS_ANALYTICS_PERSON_USER_ID => $value], 400, 'invalid_page_route_param');
        }
        $personId = $this->user('Gated person');
        $this->connect('analytics-person-viewer', $this->user('Viewer'));
        $this->assertSubscriptionError(
            [HilosPageRouteParams::HILOS_ANALYTICS_PERSON_USER_ID => (string)$personId], 403, 'forbidden', 'analytics-person-viewer',
        );
        $this->connect('analytics-person-anonymous', null);
        $this->assertSubscriptionError(
            [HilosPageRouteParams::HILOS_ANALYTICS_PERSON_USER_ID => (string)$personId], 401, 'unauthorized', 'analytics-person-anonymous',
        );
    }

    /** The card links to Analytics in its first answer, and the flag stays open to a viewer of the admin view mode. */
    public function testTheCardLinksToAnalyticsForAdminsAndViewers(): void
    {
        $personId = $this->user('Card person');
        self::assertTrue($this->cardData(self::ADMIN, $personId)[UserPage::ANALYTICS_ENABLED]);

        $this->connect('analytics-card-viewer', $this->user('Card viewer'));
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);
        $viewerData = $this->cardData('analytics-card-viewer', $personId);
        self::assertTrue($viewerData[UserPage::ANALYTICS_ENABLED]);
        self::assertStringNotContainsString('Card person', json_encode($viewerData, JSON_THROW_ON_ERROR));
        self::assertSame('Person', $this->subscribe('analytics-card-viewer', $personId)->payload->data[PageCatalogConstants::WIRE_PAGE_LABEL]);
        self::assertSame('Card person (#' . $personId . ')',
            $this->subscribe(self::ADMIN, $personId)->payload->data[PageCatalogConstants::WIRE_PAGE_LABEL]);
    }

    /** Without the feature, or without the person page among the project's pages, the card has no link. */
    public function testTheCardHasNoLinkWithoutTheFeatureOrThePage(): void
    {
        $personId = $this->user('Card person without analytics');
        foreach ([AnalyticsPersonPageWithoutFeatureHilos::class, AnalyticsPersonPageNotRegisteredHilos::class] as $facade) {
            $this->bindAppClass($facade);
            try {
                self::assertFalse($this->cardData(self::ADMIN, $personId)[UserPage::ANALYTICS_ENABLED], $facade);
            } finally {
                $this->bindAppClass($this->boundAppClass);
            }
        }
        self::assertTrue($this->cardData(self::ADMIN, $personId)[UserPage::ANALYTICS_ENABLED]);
    }

    /**
     * The shared catalog identity stays public; the client binds the permitted pageLabel to its owner and last crumb.
     *
     * @param array<string, mixed> $data Person page's routed response data
     */
    private function assertPersonHead(array $data): void
    {
        self::assertSame([
            'page' => HilosPageConstants::HILOS_ANALYTICS_PERSON,
            'label' => 'Person',
            'lead' => 'Browser sessions where this person signed in last. '
                . 'Other people may have performed actions in the same browser.',
        ], $data[PageCatalogConstants::WIRE_PAGE_HEAD_OWNER]);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_ANALYTICS, 'label' => 'Analytics'],
            ['page' => HilosPageConstants::HILOS_ANALYTICS_PEOPLE, 'label' => 'People'],
            ['page' => HilosPageConstants::HILOS_ANALYTICS_PERSON, 'label' => 'Person'],
        ], $data[PageCatalogConstants::WIRE_PAGE_BREADCRUMB]);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_ANALYTICS_PERSON, 'label' => 'Overview'],
        ], $data[PageCatalogConstants::WIRE_PAGE_TABS]);
        self::assertSame([], $data[PageCatalogConstants::WIRE_PAGE_CHILDREN]);
    }

    /**
     * Publishes a photo fixture through the registry and the owner of that person's photo row.
     *
     * @param int $userId Person whose photo is set
     * @return int Published registry file key
     */
    private function putPhoto(int $userId): int
    {
        $fileId = (int)$this->keepRegistryFile($userId, 'face.jpg', 'analytics person face')->id;
        $this->photoFiles[$fileId] = $userId;
        $this->underAgent(
            $this->personAgent($userId),
            static fn(): ?int => Hilos::$db->userPhotos->actions->put($userId, $fileId),
        );
        $this->drain();

        return $fileId;
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
     * @param ?int $userId Account that signed in last, or nobody
     * @param int $firstSeenTs Moment the session opened
     * @param int $lastSeenTs Moment the session was last seen
     * @param ?string $userAgent Raw browser description
     * @return int Analytics session number
     */
    private function browserSession(string $token, ?int $userId, int $firstSeenTs, int $lastSeenTs, ?string $userAgent = null): int
    {
        $id = $this->store->ensureBrowserSession($token, $userAgent, null, $firstSeenTs);
        if ($userId !== null) {
            $this->store->setBrowserSessionIdentity($token, 'user_id', (string)$userId, $lastSeenTs);
        } else {
            $this->store->ensureBrowserSession($token, null, null, $lastSeenTs);
        }
        return $id;
    }

    /**
     * @param string $acceptKey Subscriber
     * @param int $userId Numeric person address
     * @return PageResponseSignalData Only opening response
     */
    private function subscribe(string $acceptKey, int $userId): PageResponseSignalData
    {
        $frames = $this->dispatchSubscribe($acceptKey, [HilosPageRouteParams::HILOS_ANALYTICS_PERSON_USER_ID => (string)$userId]);
        self::assertCount(1, $frames);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $frames[0]['name']);
        self::assertInstanceOf(PageResponseSignalData::class, $frames[0]['data']);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_ANALYTICS, 'label' => 'Analytics'],
            ['page' => HilosPageConstants::HILOS_ANALYTICS_PEOPLE, 'label' => 'People'],
            ['page' => HilosPageConstants::HILOS_ANALYTICS_PERSON, 'label' => 'Person'],
        ], $frames[0]['data']->payload->data[PageCatalogConstants::WIRE_PAGE_BREADCRUMB]);
        return $frames[0]['data'];
    }

    /**
     * @param string $acceptKey Subscriber
     * @param int $userId Preset subject
     * @return TableWindowSignalData Only requested table window
     */
    private function window(string $acceptKey, int $userId): TableWindowSignalData
    {
        $this->drain();
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), function () use ($acceptKey, $userId): void {
            $this->analyticsRouter->dispatchTableViewport(new WebSocketTableViewportSignalDTO(
                acceptKey: $acceptKey, page: PersonPage::PAGE, tableKey: ChatTableContext::hilosAnalyticsBrowserSessions,
                filter: [HilosAnalyticsBrowserSessionsTable::FILTER_USER => $userId],
                sort: new HilosAnalyticsBrowserSessionsTable()->defaultSort(), limit: 25,
            ), '', PersonPage::PAGE);
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
    private function assertSubscriptionError(array $params, int $httpCode, string $errorCode, string $acceptKey = self::ADMIN): void
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
        $request = new WebSocketPageSubscribeSignalDTO($acceptKey, PersonPage::PAGE, $params);
        Hilos::$sr->subscribeToPage(PersonPage::PAGE, $request);
        Hilos::$sr->reportTableWindows($acceptKey, []);
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), function () use ($request): void {
            $this->analyticsRouter->dispatchPageSubscribe($request, '', PersonPage::PAGE);
        });
        return $this->drain();
    }

    /**
     * Subscribes to the person's real admin card and reads its page data.
     *
     * @param string $acceptKey Subscriber
     * @param int $userId Person on the card
     * @return array<string, mixed> Data of the card's opening response
     */
    private function cardData(string $acceptKey, int $userId): array
    {
        $this->drain();
        $request = new WebSocketPageSubscribeSignalDTO($acceptKey, UserPage::PAGE, [HilosPageRouteParams::HILOS_USER_USER_ID => (string)$userId]);
        Hilos::$sr->subscribeToPage(UserPage::PAGE, $request);
        Hilos::$sr->reportTableWindows($acceptKey, []);
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), function () use ($request): void {
            $this->cardRouter->dispatchPageSubscribe($request, '', UserPage::PAGE);
        });
        $responses = array_values(array_filter(
            $this->drain(),
            static fn(array $frame): bool => $frame['name'] === SignalTypeConstants::PAGE_RESPONSE,
        ));
        self::assertCount(1, $responses);
        self::assertInstanceOf(PageResponseSignalData::class, $responses[0]['data']);
        return $responses[0]['data']->payload->data;
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
        foreach (['person-token-a', 'person-token-renamed', 'person-token-b', 'person-accept-key', '192.0.2.44', self::USER_AGENT,
            'legacy-body-secret', 'route-secret', 'session_token', 'old_token', 'new_token', 'alias', 'accept_key',
            'user_agent', 'payload_json', 'createdTs', 'actionName', 'rename', 'sign_in', 'Somebody else', 'other-person-token',
            'guest-token'] as $secret) {
            self::assertStringNotContainsString($secret, $wire);
        }
    }

    /**
     * Points the primary connection at another database, so every read of the fixture fails as SQL.
     *
     * @param string $database Database to address
     * @param DatabaseConnectionConfig $config Connection whose other settings stay
     */
    private function pointPrimaryAt(string $database, DatabaseConnectionConfig $config): void
    {
        Database::configure(
            index: DatabaseConnectionDefaults::PRIMARY_INDEX,
            host: $config->host,
            user: $config->user,
            password: $config->password,
            database: $database,
            port: $config->port,
            charset: $config->charset,
            socket: $config->socket,
            reconnectAttempts: $config->reconnectAttempts,
            reconnectDelay: $config->reconnectDelay,
        );
    }

    /** @param class-string<HilosFacade> $hilosClass Facade the bare framework calls answer for */
    private function bindAppClass(string $hilosClass): void
    {
        // The binding is private to the facade and captured once per process; a fixture facade
        // standing in for one case has no other way in.
        new ReflectionProperty(HilosFacade::class, 'appClass')->setValue(null, $hilosClass);
    }
}

/** A project that registers the person page but does not declare Analytics. */
abstract class AnalyticsPersonPageWithoutFeatureHilos extends HilosFacade
{
    public const array PAGES = [
        UserPage::PAGE => UserPage::class,
        PersonPage::PAGE => PersonPage::class,
    ];
}

/** A project that declares Analytics but has not registered the person page. */
abstract class AnalyticsPersonPageNotRegisteredHilos extends HilosFacade
{
    public const array PAGES = [
        UserPage::PAGE => UserPage::class,
    ];

    protected const array FEATURES = [HilosFeature::ANALYTICS];
}

/** A project that serves the same Person page and catalog with profile photos disabled. */
abstract class AnalyticsPersonPageWithoutPhotoHilos extends HilosFacade
{
    public const array PAGES = [
        PersonPage::PAGE => PersonPage::class,
    ];

    protected const string PAGE_CATALOG = PageCatalog::class;
    protected const array FEATURES = [HilosFeature::ANALYTICS];
}
