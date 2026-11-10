<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\Users\UserPage as HilosUserPage;
use Demo\Chat\Pages\Hilos\Users\UserSessionsPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\PageServiceUnavailableException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Object\Item\Session;
use Hilos\Database\Object\Item\User;
use Hilos\Pages\Users\HilosUserActingSessionsBrowserList;
use Hilos\Pages\Users\HilosUserSessionsBrowserList;
use Hilos\Runtime\Exception\Rt\RtCollectionNotReadableException;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * The Sessions tab of a person's card in the chat demo, and the card's Overview reading the same list (HIL-1662).
 *
 * Both pages read the framework's sessions list over this demo's runtime connections. What is pinned
 * is what the chat delivers through it: the person's sessions and nobody else's, the tabs open in each,
 * the administrator acting in one and that administrator's name as it changes, a session leaving the
 * list once it is bound to somebody else, what a viewer of the admin view mode is shown and refused,
 * and a refusal rather than an empty list while the tabs have not reached the worker yet. What the
 * two endings do at the sessions library is pinned in the framework (AdminSessionsEndActionsTest).
 */
final class HilosUserSessionsPageTest extends IntegrationTestCase
{
    private const string TEST_AGENT = 'hilos-user-sessions-page-test';

    private const string ADMIN_KEY = 'ak-user-sessions-admin';

    private const string VIEWER_KEY = 'ak-user-sessions-viewer';

    private const string LAPTOP_KEY = 'ak-user-sessions-laptop';

    private const string LAPTOP_SECOND_KEY = 'ak-user-sessions-laptop-second';

    private const string LAPTOP_THIRD_KEY = 'ak-user-sessions-laptop-third';

    private const string ACTING_KEY = 'ak-user-sessions-acting';

    private const string OUTGOING_KEY = 'ak-user-sessions-outgoing';

    private const string STRANGER_KEY = 'ak-user-sessions-stranger';

    private const string ADMIN_NAME = 'Acting administrator';

    private const string STRANGER_NAME = 'Somebody else';

    private const string LAPTOP_DEVICE = 'Work laptop';

    private const string LAPTOP_ZONE = 'Europe/Kyiv';

    /** An address the laptop session keeps, which no list field may carry. */
    private const string LAPTOP_ADDRESS = '203.0.113.7';

    private int $personId;

    private int $strangerId;

    private int $adminId;

    private int $laptopSessionId;

    private int $actingSessionId;

    private int $idleSessionId;

    /** @var list<string> Every session token the fixture minted, none of which may travel */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        Hilos::initSignalRouter(new ChatSignalRouter());
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->connections->actions->clear();

        $admin = Hilos::$db->users->actions->createWithName(self::ADMIN_NAME);
        $admin->actions->setAdmin(true);
        $this->adminId = (int) $admin->id;
        $this->personId = (int) Hilos::$db->users->actions->createWithName('Card person')->id;
        $this->strangerId = (int) Hilos::$db->users->actions->createWithName(self::STRANGER_NAME)->id;
        $viewerId = (int) Hilos::$db->users->actions->createWithName('Viewer of the card')->id;

        $this->laptopSessionId = $this->signIn($this->personId, 'laptop', [self::LAPTOP_KEY, self::LAPTOP_SECOND_KEY]);
        $laptop = Hilos::$db->sessions[$this->laptopSessionId];
        $laptop->actions->setDeviceName(self::LAPTOP_DEVICE);
        $laptop->actions->setBrowserTimeZone(self::LAPTOP_ZONE);
        $laptop->actions->setIpAddress(self::LAPTOP_ADDRESS);
        $this->actingSessionId = $this->signIn($this->personId, 'acting', [self::ACTING_KEY]);
        Hilos::$db->sessions[$this->actingSessionId]->actions->setImpersonator($this->adminId);
        $this->idleSessionId = $this->signIn($this->personId, 'idle', []);
        $this->signIn($this->strangerId, 'stranger', [self::STRANGER_KEY]);
        $this->signIn($this->adminId, 'admin', [self::ADMIN_KEY]);
        $this->signIn($viewerId, 'viewer', [self::VIEWER_KEY]);
        // Fixture-only dates through the mounted ORM; no production write API accepts a chosen clock.
        $this->stampSession($this->laptopSessionId, 1767312000000);
        $this->stampSession($this->idleSessionId, null);

        Hilos::$sr->subscribeToPage(
            UserSessionsPage::PAGE,
            new WebSocketPageSubscribeSignalDTO(self::ADMIN_KEY, UserSessionsPage::PAGE, $this->params()->toArray()),
        );
        $this->drainSignals();
    }

    protected function tearDown(): void
    {
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        Hilos::initBrowser();
        $this->drainSignals();
        Hilos::$sr = null;
        parent::tearDown();
    }

    /**
     * A cold entry draws every session of the person and only theirs: the tabs open in each, the moments
     * as milliseconds or null, the administrator acting in one by name, and nothing secret.
     */
    public function testTheFirstAnswerCarriesThePersonsSessionsTheirTabsAndTheActingAdministrator(): void
    {
        $snapshot = $this->snapshot(UserSessionsPage::PAGE, self::ADMIN_KEY);
        $items = self::slotsByItemKey($snapshot[PagePayload::lists][HilosUserSessionsBrowserList::LIST][PagePayload::items]);

        self::assertEqualsCanonicalizing(
            [$this->laptopSessionId, $this->actingSessionId, $this->idleSessionId],
            array_keys($items),
        );
        self::assertSame([
            Session::id => $this->laptopSessionId,
            Session::userId => $this->personId,
            Session::deviceName => self::LAPTOP_DEVICE,
            Session::browserTimeZone => self::LAPTOP_ZONE,
            Session::createdAt => 1767225600000,
            Session::lastSeenAt => 1767227400000,
            Session::expiresAt => 1767312000000,
            Session::impersonatorUserId => null,
        ], $items[$this->laptopSessionId][ChatDbContext::sessions]);
        self::assertSame([self::LAPTOP_KEY, self::LAPTOP_SECOND_KEY], self::tabKeys($items[$this->laptopSessionId]));
        foreach ($items[$this->laptopSessionId][HilosUserSessionsBrowserList::CONNECTIONS] as $tab) {
            self::assertSame($this->laptopSessionId, $tab[HilosSessionConnection::sessionId]);
            self::assertIsInt($tab[HilosConnection::connectedAt]);
            self::assertArrayNotHasKey(HilosSessionConnection::sessionToken, $tab);
        }
        self::assertArrayNotHasKey(ChatDbContext::users, $items[$this->laptopSessionId]);

        $acting = $items[$this->actingSessionId];
        self::assertSame($this->adminId, $acting[ChatDbContext::sessions][Session::impersonatorUserId]);
        self::assertSame([User::id => $this->adminId, User::name => self::ADMIN_NAME], $acting[ChatDbContext::users]);
        self::assertSame([self::ACTING_KEY], self::tabKeys($acting));

        $idle = $items[$this->idleSessionId];
        self::assertNull($idle[ChatDbContext::sessions][Session::expiresAt]);
        self::assertSame([], self::tabKeys($idle));

        foreach ($items as $slots) {
            self::assertArrayNotHasKey(Session::token, $slots[ChatDbContext::sessions]);
            self::assertArrayNotHasKey(Session::ipAddress, $slots[ChatDbContext::sessions]);
        }
        $sent = json_encode($snapshot[PagePayload::lists], JSON_THROW_ON_ERROR);
        foreach ([...$this->tokens, self::LAPTOP_ADDRESS, self::STRANGER_NAME, self::STRANGER_KEY] as $secret) {
            self::assertStringNotContainsString($secret, $sent);
        }

        self::assertSame($items, $this->listItems(HilosUserPage::PAGE, self::ADMIN_KEY), 'The Overview reads the same list');
    }

    /** The second list carries the card person's outgoing takeover, the acted person's live name and only their tabs. */
    public function testTheActingSessionsListCarriesTheActedPersonAndTheirTabs(): void
    {
        $outgoingSessionId = $this->startActingSession();
        Hilos::$db->sessions[$outgoingSessionId]->actions->setDeviceName(self::LAPTOP_DEVICE);
        Hilos::$db->sessions[$outgoingSessionId]->actions->setBrowserTimeZone(self::LAPTOP_ZONE);
        Hilos::$db->sessions[$outgoingSessionId]->actions->setIpAddress(self::LAPTOP_ADDRESS);
        $this->stampSession($outgoingSessionId, null);
        // A tab still carrying P on the same session must not be mistaken for a tab acting as C.
        Hilos::$rt->connections->actions->register(
            self::LAPTOP_THIRD_KEY,
            $this->personId,
            Hilos::$db->sessions[$outgoingSessionId]->token,
            $outgoingSessionId,
        );
        $this->drainSignals();

        $items = $this->listItems(UserSessionsPage::PAGE, self::ADMIN_KEY, HilosUserActingSessionsBrowserList::LIST);

        self::assertSame([$outgoingSessionId], array_keys($items));
        self::assertSame([
            Session::id => $outgoingSessionId,
            Session::userId => $this->strangerId,
            Session::deviceName => self::LAPTOP_DEVICE,
            Session::browserTimeZone => self::LAPTOP_ZONE,
            Session::createdAt => 1767225600000,
            Session::lastSeenAt => 1767227400000,
            Session::expiresAt => null,
            Session::impersonatorUserId => $this->personId,
        ], $items[$outgoingSessionId][ChatDbContext::sessions]);
        self::assertSame(
            [User::id => $this->strangerId, User::name => self::STRANGER_NAME],
            $items[$outgoingSessionId][ChatDbContext::users],
        );
        self::assertSame([self::OUTGOING_KEY], self::tabKeys($items[$outgoingSessionId]));
        self::assertSame(
            $items,
            $this->listItems(HilosUserPage::PAGE, self::ADMIN_KEY, HilosUserActingSessionsBrowserList::LIST),
            'The Overview reads the same outgoing sessions',
        );
        self::assertArrayNotHasKey($outgoingSessionId, $this->listItems(UserSessionsPage::PAGE, self::ADMIN_KEY));
        $sent = json_encode($items, JSON_THROW_ON_ERROR);
        foreach ([...$this->tokens, self::LAPTOP_ADDRESS, self::STRANGER_KEY, self::LAPTOP_THIRD_KEY, self::ADMIN_NAME] as $secret) {
            self::assertStringNotContainsString($secret, $sent);
        }

        Hilos::$db->users[$this->strangerId]->actions->rename('Renamed acted person');
        $this->forwardDbSync(ChatDbContext::users);

        $sent = $this->sentList(self::ADMIN_KEY, HilosUserActingSessionsBrowserList::LIST);
        self::assertSame([$outgoingSessionId], array_keys($sent[PagePayload::items]));
        self::assertSame(
            [User::id => $this->strangerId, User::name => 'Renamed acted person'],
            $sent[PagePayload::items][$outgoingSessionId][ChatDbContext::users],
        );
        self::assertArrayNotHasKey(PagePayload::deleted, $sent);
    }

    /** Stop restores the session to P and clears its marker; DB_SYNC removes it from the outgoing list on the open card. */
    public function testStoppingATakeoverRemovesTheActingSessionWithoutAReload(): void
    {
        $outgoingSessionId = $this->startActingSession();
        self::assertSame(
            [$outgoingSessionId],
            array_keys($this->listItems(UserSessionsPage::PAGE, self::ADMIN_KEY, HilosUserActingSessionsBrowserList::LIST)),
        );
        $this->drainSignals();

        Hilos::$db->sessions[$outgoingSessionId]->actions->bindUser($this->personId);
        Hilos::$db->sessions[$outgoingSessionId]->actions->setImpersonator(null);
        Hilos::$rt->connections[self::OUTGOING_KEY]->actions->bindUser($this->personId);
        $this->forwardDbSync(ChatDbContext::sessions);

        $sent = $this->sentList(self::ADMIN_KEY, HilosUserActingSessionsBrowserList::LIST);
        self::assertSame([$outgoingSessionId], $sent[PagePayload::deleted]);
        self::assertArrayNotHasKey($outgoingSessionId, $sent[PagePayload::items] ?? []);
        self::assertSame([], $this->listItems(UserSessionsPage::PAGE, self::ADMIN_KEY, HilosUserActingSessionsBrowserList::LIST));
        $ordinary = $this->listItems(UserSessionsPage::PAGE, self::ADMIN_KEY);
        self::assertArrayHasKey($outgoingSessionId, $ordinary);
        self::assertNull($ordinary[$outgoingSessionId][ChatDbContext::sessions][Session::impersonatorUserId]);
        self::assertSame([self::OUTGOING_KEY], self::tabKeys($ordinary[$outgoingSessionId]));
    }

    /** Starting a takeover moves a session of P to C; its source announcement adds it to the already open outgoing list. */
    public function testStartingATakeoverAddsTheActingSessionWithoutAReload(): void
    {
        Hilos::$db->users[$this->personId]->actions->setAdmin(true);
        $outgoingSessionId = $this->signIn($this->personId, 'outgoing', [self::OUTGOING_KEY]);
        self::assertSame([], $this->listItems(UserSessionsPage::PAGE, self::ADMIN_KEY, HilosUserActingSessionsBrowserList::LIST));
        $this->drainSignals();

        Hilos::$db->sessions[$outgoingSessionId]->actions->setImpersonator($this->personId);
        Hilos::$db->sessions[$outgoingSessionId]->actions->bindUser($this->strangerId);
        Hilos::$rt->connections[self::OUTGOING_KEY]->actions->bindUser($this->strangerId);
        $this->forwardDbSync(ChatDbContext::sessions);

        $sent = $this->sentList(self::ADMIN_KEY, HilosUserActingSessionsBrowserList::LIST);
        self::assertSame([$outgoingSessionId], array_keys($sent[PagePayload::items]));
        self::assertSame($this->personId, $sent[PagePayload::items][$outgoingSessionId][ChatDbContext::sessions][Session::impersonatorUserId]);
        self::assertSame($this->strangerId, $sent[PagePayload::items][$outgoingSessionId][ChatDbContext::sessions][Session::userId]);
        self::assertSame(
            [User::id => $this->strangerId, User::name => self::STRANGER_NAME],
            $sent[PagePayload::items][$outgoingSessionId][ChatDbContext::users],
        );
        self::assertSame([self::OUTGOING_KEY], self::tabKeys($sent[PagePayload::items][$outgoingSessionId]));
        self::assertArrayNotHasKey(PagePayload::deleted, $sent);
        self::assertSame(
            [$outgoingSessionId],
            array_keys($this->listItems(UserSessionsPage::PAGE, self::ADMIN_KEY, HilosUserActingSessionsBrowserList::LIST)),
        );
        self::assertArrayNotHasKey($outgoingSessionId, $this->listItems(UserSessionsPage::PAGE, self::ADMIN_KEY));
    }

    /** A session is one item however many tabs it has open, so the person's count is their sign-ins. */
    public function testTabsOfOneSessionStayInsideItsOneItem(): void
    {
        Hilos::$rt->connections->actions->register(
            self::LAPTOP_THIRD_KEY,
            $this->personId,
            Hilos::$db->sessions[$this->laptopSessionId]->token,
            $this->laptopSessionId,
        );

        $items = $this->listItems(UserSessionsPage::PAGE, self::ADMIN_KEY);

        self::assertCount(3, $items);
        self::assertSame(
            [self::LAPTOP_KEY, self::LAPTOP_SECOND_KEY, self::LAPTOP_THIRD_KEY],
            self::tabKeys($items[$this->laptopSessionId]),
        );
    }

    /**
     * A tab is its session's by the session and by the person both: moved to another session of the person
     * it leaves the old item for the new one on the open tab, and taken by somebody else it leaves the
     * person's list though its session stays.
     */
    public function testATabMovedToAnotherSessionOrPersonFollowsWithoutAReload(): void
    {
        Hilos::$rt->connections->actions->repointSessionToken(
            self::LAPTOP_SECOND_KEY,
            Hilos::$db->sessions[$this->idleSessionId]->token,
            $this->idleSessionId,
        );
        $this->forwardRtSync(ChatRtContext::connections);

        $sent = $this->sentList(self::ADMIN_KEY);
        self::assertEqualsCanonicalizing([$this->laptopSessionId, $this->idleSessionId], array_keys($sent[PagePayload::items]));
        self::assertSame([self::LAPTOP_KEY], self::tabKeys($sent[PagePayload::items][$this->laptopSessionId]));
        self::assertSame([self::LAPTOP_SECOND_KEY], self::tabKeys($sent[PagePayload::items][$this->idleSessionId]));

        Hilos::$rt->connections[self::ACTING_KEY]->actions->bindUser($this->strangerId);
        $this->forwardRtSync(ChatRtContext::connections);

        $sent = $this->sentList(self::ADMIN_KEY);
        self::assertSame([$this->actingSessionId], array_keys($sent[PagePayload::items]));
        self::assertSame([], self::tabKeys($sent[PagePayload::items][$this->actingSessionId]));
        self::assertArrayNotHasKey(PagePayload::deleted, $sent);
    }

    /** The administrator renamed reaches the session they act in on the open tab, and only that session. */
    public function testRenamingTheActingAdministratorReachesTheMarkWithoutAReload(): void
    {
        Hilos::$db->users[$this->adminId]->actions->rename('Renamed administrator');
        $this->forwardDbSync(ChatDbContext::users);

        $sent = $this->sentList(self::ADMIN_KEY);
        self::assertSame([$this->actingSessionId], array_keys($sent[PagePayload::items]));
        self::assertSame(
            [User::id => $this->adminId, User::name => 'Renamed administrator'],
            $sent[PagePayload::items][$this->actingSessionId][ChatDbContext::users],
        );
        self::assertArrayNotHasKey(PagePayload::deleted, $sent);
    }

    /** A session signed into somebody else leaves the person's list on the open tab, and the next answer agrees. */
    public function testASessionBoundToSomebodyElseLeavesThePersonsList(): void
    {
        Hilos::$db->sessions[$this->laptopSessionId]->actions->bindUser($this->strangerId);
        $this->forwardDbSync(ChatDbContext::sessions);

        $sent = $this->sentList(self::ADMIN_KEY);
        self::assertSame([$this->laptopSessionId], $sent[PagePayload::deleted]);
        self::assertArrayNotHasKey($this->laptopSessionId, $sent[PagePayload::items] ?? []);
        self::assertEqualsCanonicalizing(
            [$this->actingSessionId, $this->idleSessionId],
            array_keys($this->listItems(UserSessionsPage::PAGE, self::ADMIN_KEY)),
        );
    }

    /**
     * A viewer of the admin view mode is shown each session of the person with every field hidden, no
     * administrator or acted person in either list, and is refused both endings before anything leaves the page.
     */
    public function testAViewerIsShownTheSessionsHiddenAndRefusedBothEndings(): void
    {
        $outgoingSessionId = $this->startActingSession();
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);

        $items = $this->listItems(UserSessionsPage::PAGE, self::VIEWER_KEY);

        self::assertEqualsCanonicalizing(
            [$this->laptopSessionId, $this->actingSessionId, $this->idleSessionId],
            array_keys($items),
        );
        foreach ($items as $sessionId => $slots) {
            self::assertViewerSession($slots, (string) $sessionId);
        }
        $outgoing = $this->listItems(UserSessionsPage::PAGE, self::VIEWER_KEY, HilosUserActingSessionsBrowserList::LIST);
        self::assertSame([$outgoingSessionId], array_keys($outgoing));
        self::assertViewerSession($outgoing[$outgoingSessionId], (string) $outgoingSessionId);
        $sent = json_encode([$items, $outgoing], JSON_THROW_ON_ERROR);
        foreach ([
            self::LAPTOP_DEVICE, self::LAPTOP_ZONE, self::ADMIN_NAME, self::LAPTOP_KEY, self::ACTING_KEY,
            self::OUTGOING_KEY, self::STRANGER_NAME, ...$this->tokens,
        ] as $personal) {
            self::assertStringNotContainsString($personal, $sent);
        }

        foreach ([
            HilosSignalConstants::HILOS_USER_SESSION_END => ['userId' => $this->personId, 'sessionId' => $this->laptopSessionId],
            HilosSignalConstants::HILOS_USER_SESSIONS_END_ALL => ['userId' => $this->personId],
        ] as $action => $payload) {
            $this->drainSignals();
            $this->dispatch(self::VIEWER_KEY, $action, $payload);
            $frames = [];
            while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
                $frames[] = $frame;
            }
            self::assertCount(1, $frames, "{$action}: only a refusal, no ask to the sessions library");
            self::assertSame(SignalConstants::ACTION_ERROR, $frames[0]->signalName->getName(), $action);
            self::assertInstanceOf(WebSocketSignalData::class, $frames[0]->data);
            self::assertSame(self::VIEWER_KEY, $frames[0]->data->targetAcceptKey, $action);
        }
    }

    /**
     * A viewer's open tab follows the sessions live and is still shown no administrator: renaming the one
     * acting sends the viewer nothing, and a session taken over or let go arrives as hidden as the rest,
     * while the administrator's own tab is sent the name each time.
     */
    public function testAViewerFollowsTheSessionsLiveWithoutTheActingAdministrator(): void
    {
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);
        Hilos::$sr->subscribeToPage(
            UserSessionsPage::PAGE,
            new WebSocketPageSubscribeSignalDTO(self::VIEWER_KEY, UserSessionsPage::PAGE, $this->params()->toArray()),
        );
        $this->drainSignals();

        Hilos::$db->users[$this->adminId]->actions->rename('Renamed administrator');
        $this->forwardDbSync(ChatDbContext::users);

        $sent = $this->sentLists();
        self::assertSame([self::ADMIN_KEY], array_keys($sent), 'The rename names no session to the viewer');
        self::assertSame(
            [User::id => $this->adminId, User::name => 'Renamed administrator'],
            $sent[self::ADMIN_KEY][PagePayload::items][$this->actingSessionId][ChatDbContext::users],
        );

        Hilos::$db->sessions[$this->laptopSessionId]->actions->setImpersonator($this->adminId);
        Hilos::$db->sessions[$this->actingSessionId]->actions->setImpersonator(null);
        $this->forwardDbSync(ChatDbContext::sessions);

        $sent = $this->sentLists();
        self::assertSame(
            [User::id => $this->adminId, User::name => 'Renamed administrator'],
            $sent[self::ADMIN_KEY][PagePayload::items][$this->laptopSessionId][ChatDbContext::users],
        );
        $viewer = $sent[self::VIEWER_KEY][PagePayload::items];
        self::assertEqualsCanonicalizing([$this->laptopSessionId, $this->actingSessionId], array_keys($viewer));
        foreach ($viewer as $sessionId => $slots) {
            self::assertViewerSession($slots, (string) $sessionId);
        }
        self::assertStringNotContainsString('Renamed administrator', json_encode($viewer, JSON_THROW_ON_ERROR));
    }

    /**
     * While the worker holds no copy of the tabs yet, both pages of the card are refused as a moment
     * early, and their list is never drawn empty; once the copy lands, the same answer carries the tabs.
     */
    public function testWhileTheTabsAreNotHereTheCardIsRefusedAndComesBackWithThem(): void
    {
        foreach ([UserSessionsPage::PAGE, HilosUserPage::PAGE] as $page) {
            self::assertContains(ChatRtContext::connections, Hilos::$browser->rtSourceKeysOfPage($page), $page);
        }
        $consumer = SourceConsumer::page(self::ADMIN_KEY);
        $borrowed = $this->borrowInterest(SourceChange::KIND_RT, ChatRtContext::connections);
        SourceInterestRegistry::readsWhatIsDelivered();
        try {
            $this->takeUpAllButTheTabs($consumer, UserSessionsPage::PAGE);

            $refusal = null;
            try {
                Hilos::$browser->assertSubscriptionAccess(UserSessionsPage::PAGE, self::ADMIN_KEY, $this->params());
            } catch (PageServiceUnavailableException $exception) {
                $refusal = $exception;
            }
            self::assertInstanceOf(PageServiceUnavailableException::class, $refusal);
            $unread = null;
            try {
                $this->snapshot(UserSessionsPage::PAGE, self::ADMIN_KEY);
            } catch (RtCollectionNotReadableException $exception) {
                $unread = $exception;
            }
            self::assertInstanceOf(RtCollectionNotReadableException::class, $unread, 'The list is refused, not drawn empty');

            SourceInterestRegistry::markReady(SourceChange::KIND_RT, ChatRtContext::connections);

            Hilos::$browser->assertSubscriptionAccess(UserSessionsPage::PAGE, self::ADMIN_KEY, $this->params());
            $items = $this->listItems(UserSessionsPage::PAGE, self::ADMIN_KEY);
            self::assertCount(3, $items);
            self::assertSame([self::LAPTOP_KEY, self::LAPTOP_SECOND_KEY], self::tabKeys($items[$this->laptopSessionId]));
        } finally {
            SourceInterestRegistry::releaseConsumer($consumer);
            SourceInterestRegistry::readsWhatItMounts();
            $this->putInterestBack($borrowed);
        }
    }

    /**
     * Signs one person in on a new session and opens the given tabs in it.
     *
     * @param int $userId Person the session is signed into
     * @param string $role Name of the session inside the case, which its token is minted from
     * @param list<string> $acceptKeys Tabs open in the session
     * @return int The session's id
     */
    private function signIn(int $userId, string $role, array $acceptKeys): int
    {
        $token = substr(hash('sha256', $this->name() . ':' . $role), 0, 32);
        $session = Hilos::$db->sessions->actions->createAnonymous($token);
        $session->actions->bindUser($userId);
        $this->tokens[] = $token;
        foreach ($acceptKeys as $acceptKey) {
            Hilos::$rt->connections->actions->register($acceptKey, $userId, $token, (int) $session->id);
        }

        return (int) $session->id;
    }

    /** @return int A session in which the person of the card acts as the stranger */
    private function startActingSession(): int
    {
        Hilos::$db->users[$this->personId]->actions->setAdmin(true);
        $sessionId = $this->signIn($this->strangerId, 'outgoing', [self::OUTGOING_KEY]);
        Hilos::$db->sessions[$sessionId]->actions->setImpersonator($this->personId);

        return $sessionId;
    }

    /**
     * Gives one session fixed moments, the way no production write can.
     *
     * @param int $sessionId Session to stamp
     * @param ?int $expiresAt Epoch milliseconds the session expires at, or null for none
     */
    private function stampSession(int $sessionId, ?int $expiresAt): void
    {
        $session = Hilos::$db->getObjectCollection(ChatDbContext::sessions)->get($sessionId);
        $session->createdAt = 1767225600000;
        $session->lastSeenAt = 1767227400000;
        $session->expiresAt = $expiresAt;
        $session->sync();
    }

    /** @return PageRouteParams The card's route, opened on the person */
    private function params(): PageRouteParams
    {
        return new PageRouteParams([HilosPageRouteParams::HILOS_USER_USER_ID => (string) $this->personId]);
    }

    /**
     * @param string $page Page of the card
     * @param string $acceptKey Connection reading it
     * @return array<string, mixed> Browser part of the page's first answer
     */
    private function snapshot(string $page, string $acceptKey): array
    {
        return Hilos::$browser->buildSubscribeSnapshot($page, $acceptKey, $this->params())->toArray();
    }

    /**
     * @param string $page Page of the card
     * @param string $acceptKey Connection reading it
     * @param string $listName Sessions list of the card
     * @return array<int|string, array<string, mixed>> Slots of each session of the first answer, by session id
     */
    private function listItems(string $page, string $acceptKey, string $listName = HilosUserSessionsBrowserList::LIST): array
    {
        $lists = $this->snapshot($page, $acceptKey)[PagePayload::lists];
        self::assertArrayHasKey($listName, $lists);

        return self::slotsByItemKey(
            $lists[$listName][PagePayload::items] ?? [],
        );
    }

    /**
     * Hands the database's own announcements of one collection to the browser and flushes them.
     *
     * @param string $collectionKey Collection whose updates are delivered
     */
    private function forwardDbSync(string $collectionKey): void
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof DbSyncUpdatedSignalData && $signal->data->collectionKey === $collectionKey) {
                Hilos::$browser->record(SourceChange::dbUpdated(
                    $signal->data->collectionKey,
                    $signal->data->idString,
                    $signal->data->row,
                    previous: $signal->data->previous,
                ));
            }
        }
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
    }

    /**
     * Hands the runtime's own announcements of one collection to the browser, previous values included, and flushes them.
     *
     * @param string $collectionKey Collection whose updates are delivered
     */
    private function forwardRtSync(string $collectionKey): void
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof RtSyncUpdatedSignalData && $signal->data->collectionKey === $collectionKey) {
                Hilos::$browser->record(SourceChange::rtUpdated(
                    $signal->data->collectionKey,
                    $signal->data->stateId,
                    $signal->data->row,
                    previous: $signal->data->previous,
                ));
            }
        }
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
    }

    /**
     * @param string $acceptKey Connection the update is addressed to
     * @param string $listName Sessions list of the card
     * @return array<string, mixed> The sessions list section it was sent, items keyed by session id
     */
    private function sentList(string $acceptKey, string $listName = HilosUserSessionsBrowserList::LIST): array
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if (
                $signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE
                || !$signal->data instanceof WebSocketSignalData
                || $signal->data->targetAcceptKey !== $acceptKey
                || !$signal->data->data instanceof PageResponseSignalData
            ) {
                continue;
            }
            $list = $signal->data->data->payload->toArray()[PagePayload::lists][$listName] ?? null;
            if (!is_array($list)) {
                continue;
            }
            if (isset($list[PagePayload::items])) {
                $list[PagePayload::items] = self::slotsByItemKey($list[PagePayload::items]);
            }

            return $list;
        }

        self::fail("No sessions list update reached {$acceptKey}");
    }

    /**
     * Reads every queued update of the sessions list, by the connection it was addressed to.
     *
     * @return array<string, array<string, mixed>> The list section each connection was sent, items keyed by session id
     */
    private function sentLists(): array
    {
        $lists = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if (
                $signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE
                || !$signal->data instanceof WebSocketSignalData
                || !$signal->data->data instanceof PageResponseSignalData
            ) {
                continue;
            }
            $list = $signal->data->data->payload->toArray()[PagePayload::lists][HilosUserSessionsBrowserList::LIST] ?? null;
            if (!is_array($list)) {
                continue;
            }
            if (isset($list[PagePayload::items])) {
                $list[PagePayload::items] = self::slotsByItemKey($list[PagePayload::items]);
            }
            $lists[$signal->data->targetAcceptKey] = $list;
        }
        ksort($lists);

        return $lists;
    }

    /**
     * Sends one action frame through the real page router of the demo, as the tab's.
     *
     * @param string $acceptKey Acting connection
     * @param string $action Action name
     * @param array<string, int> $payload Action payload
     */
    private function dispatch(string $acceptKey, string $action, array $payload): void
    {
        $agent = new DemoHilosAgent();
        $router = new PageSignalRouter(
            new HilosPageFactory($agent, Hilos::class),
            new ActionRouteConfig([$action => UserSessionsPage::PAGE]),
        );

        $this->underAgent(
            $agent,
            static fn () => $router->dispatchAction(new WebSocketActionSignalDTO($acceptKey, $action, $payload, 'request-1'), 'websocket'),
        );
    }

    /**
     * Takes up what one page reads the way a worker does, with every collection's copy landed but the tabs'.
     *
     * @param string $consumer Consumer holding the interest
     * @param string $page Page being subscribed to
     */
    private function takeUpAllButTheTabs(string $consumer, string $page): void
    {
        foreach ([...Hilos::$browser->dbSourceKeysOfPage($page), ...UserSessionsPage::READS_DB] as $collectionKey) {
            SourceInterestRegistry::register(SourceChange::KIND_DB, $collectionKey, $consumer);
            SourceInterestRegistry::markReady(SourceChange::KIND_DB, $collectionKey);
        }
        foreach (Hilos::$browser->rtSourceKeysOfPage($page) as $collectionKey) {
            SourceInterestRegistry::register(SourceChange::KIND_RT, $collectionKey, $consumer);
            if ($collectionKey !== ChatRtContext::connections) {
                SourceInterestRegistry::markReady(SourceChange::KIND_RT, $collectionKey);
            }
        }
    }

    /**
     * Lets go of every interest already held in one collection, so its absence has something to be
     * about, and remembers each holder's whole interest - every kind, readiness included - to put back.
     *
     * @param string $kind Source kind of the collection
     * @param string $collectionKey Collection whose holders are released
     * @return array<string, array<string, array<string, bool>>> Whether each collection was ready, per holder and kind
     */
    private function borrowInterest(string $kind, string $collectionKey): array
    {
        $borrowed = [];
        foreach (SourceInterestRegistry::consumersOf($kind, $collectionKey) as $consumerId) {
            foreach ([SourceChange::KIND_DB, SourceChange::KIND_RT] as $heldKind) {
                foreach (SourceInterestRegistry::collectionsOfConsumer($consumerId, $heldKind) as $held) {
                    $borrowed[$consumerId][$heldKind][$held] = SourceInterestRegistry::isReady($heldKind, $held);
                }
            }
            SourceInterestRegistry::releaseConsumer($consumerId);
        }

        return $borrowed;
    }

    /**
     * Puts every borrowed interest back exactly as it was found.
     *
     * @param array<string, array<string, array<string, bool>>> $borrowed Whether each collection was ready, per holder and kind
     */
    private function putInterestBack(array $borrowed): void
    {
        foreach ($borrowed as $consumerId => $kinds) {
            foreach ($kinds as $kind => $collections) {
                foreach ($collections as $collectionKey => $wasReady) {
                    SourceInterestRegistry::register($kind, $collectionKey, $consumerId);
                    if ($wasReady) {
                        SourceInterestRegistry::markReady($kind, $collectionKey);
                    }
                }
            }
        }
    }

    /**
     * Holds one session item as a viewer receives it: every session and tab field the hidden mark, and no
     * administrator - neither by name, nor by id, nor by a fragment some sessions carry and others do not.
     *
     * @param array<string, mixed> $slots Slots of the item
     * @param string $sessionId Session the item is, for the failure message
     */
    private static function assertViewerSession(array $slots, string $sessionId): void
    {
        foreach ($slots[ChatDbContext::sessions] as $field => $value) {
            self::assertTrue(HiddenValue::isMark($value), "{$sessionId}.{$field}");
        }
        foreach ($slots[HilosUserSessionsBrowserList::CONNECTIONS] ?? [] as $tab) {
            foreach ($tab as $field => $value) {
                self::assertTrue(HiddenValue::isMark($value), "{$sessionId} tab {$field}");
            }
        }
        self::assertArrayNotHasKey(ChatDbContext::users, $slots, "{$sessionId} carries no administrator");
    }

    /**
     * @param array<string, mixed> $slots Slots of one session
     * @return list<string> Accept keys of the tabs open in it, sorted
     */
    private static function tabKeys(array $slots): array
    {
        $keys = array_column($slots[HilosUserSessionsBrowserList::CONNECTIONS] ?? [], HilosConnection::acceptKey);
        sort($keys);

        return $keys;
    }

    /**
     * @param list<array<string, mixed>> $items List items as the wire carries them
     * @return array<int|string, array<string, mixed>> Slots of each item, by item key, in key order
     */
    private static function slotsByItemKey(array $items): array
    {
        $slots = array_column($items, PagePayload::slots, PagePayload::itemKey);
        ksort($slots);

        return $slots;
    }
}
