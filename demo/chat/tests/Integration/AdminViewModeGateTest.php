<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosLegalAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\AdminBotsPage;
use Demo\Chat\Pages\AdminUsersPage;
use Demo\Chat\Pages\Hilos\Logs\LogsKeysPage;
use Demo\Chat\Pages\Hilos\Users\UserPage;
use Demo\Chat\Pages\Hilos\Users\UsersPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\ActionViewModeException;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageUnauthorizedException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableActionErrorSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Object\Item\User;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Log\ClusterLogNodeSlot;
use Hilos\Log\DTO\ClusterLogIndexPortionSignalData;
use Hilos\Log\LogKeySummary;
use Hilos\Log\NodeLogIndex;
use Hilos\Pages\Logs\AbstractHilosLogsKeysPage;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\DTO\HilosUserUpdateFailSignalData;
use Throwable;

/**
 * The gate of the admin view mode on the chat demo's real pages, real router and real verdicts (HIL-1251).
 *
 * The mode is the node's own runtime row, turned on the way the lever turns it on, and who is behind a
 * connection is answered by the demo's own browser context from its live connections. Nothing is a
 * double. On trial: every action of an admin page is refused to a viewer - with or without an account -
 * but the reading ones, and the refusal is impersonal and changes nothing; a viewer subscribes to an
 * admin page of the framework and of the project; taking or giving the admin flag switches an open page
 * between viewing and full; the freeze closes a viewer as it closes an admin; and a page with a
 * subscriber set of its own stops sending to someone whose rights were taken - hidden with the mode on.
 * With the mode off everything is refused as before.
 */
final class AdminViewModeGateTest extends IntegrationTestCase
{
    private const string TEST_AGENT = 'admin-view-mode-gate-test';

    private const string ANONYMOUS_KEY = 'admin-view-mode-gate-anonymous';

    private const string VISITOR_KEY = 'admin-view-mode-gate-visitor';

    private const string ADMIN_KEY = 'admin-view-mode-gate-admin';

    /** A second admin on the log keys, whose frame proves the tick pushed at all */
    private const string WITNESS_KEY = 'admin-view-mode-gate-witness';

    private const string REQUEST_ID = 'admin-view-mode-gate-request';

    /** Comfortably past the ~100ms throttle {@see AbstractHilosLogsKeysPage::onAgentTick()} keeps. */
    private const int PAST_THE_TICK_THROTTLE_MICROSECONDS = 150_000;

    /** Any fixed instant, so a timestamp in a fixture means something to read. */
    private const int T0 = 1_800_000_000;

    /** Weight of each fixture node's one stream */
    private const int STREAM_BYTES = 100;

    /**
     * Every action of an ADMIN page of the chat demo that a viewer is refused, sorted.
     *
     * Written out rather than derived, so an action that joins an admin page - or leaves the list by
     * being declared reading - is a decision somebody makes here, not a change nobody saw.
     *
     * @var list<string>
     */
    private const array REFUSED_ACTIONS = [
        'backup_bulk_delete',
        'backup_create',
        'backup_delete',
        'backup_reopen',
        'backup_restore',
        'backup_set_keep',
        'bot_create',
        'bot_delete',
        'bot_update',
        'communications_channel_reset',
        'communications_channel_set',
        'communications_channel_test',
        'communications_delivery_retry',
        'guardian_agent_run_start',
        'guardian_agent_run_stop',
        'hilos_impersonate_start',
        'hilos_user_admin_set',
        'hilos_user_block_set',
        'hilos_user_deletion_set',
        'hilos_user_merge',
        'hilos_user_update',
        'legal_setting_set',
        'logs_follow_start',
        'logs_follow_stop',
        'logs_read_lines',
        'logs_takeout_confirm',
        'logs_takeout_undo',
        'maintenance_circle_add',
        'maintenance_circle_remove',
        'moderator_piece_create',
        'moderator_piece_delete',
        'moderator_piece_update',
        'security_2fa_setting_set',
        'security_impersonation_scope_set',
        'security_impersonation_switch_set',
        'security_oauth_provider_reset',
        'security_oauth_provider_set',
        'security_oauth_redirect_reset',
        'security_oauth_redirect_set',
        'security_passkey_unproven_set',
        'security_sign_in_method_set',
        'security_step_up_operation_set',
        'setting_add',
        'setting_delete',
        'setting_preset_apply',
        'setting_reset',
        'setting_update',
        'user_update',
    ];

    private int $visitorId;

    private int $adminId;

    /**
     * How many nodes the fixture picture holds; each one more moves the header of the log keys.
     *
     * Static because the page's memory of the header it sent last is static too: a picture repeating
     * one of an earlier case would be the header already sent, and no tick would push it.
     */
    private static int $reportedNodes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        Hilos::initSignalRouter(new ChatSignalRouter());
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        $this->switchMode(true);

        $visitor = Hilos::$db->users->actions->createWithName('Visitor of the node');
        $this->visitorId = (int) $visitor->id;
        $admin = Hilos::$db->users->actions->createWithName('Admin of the node');
        $admin->actions->setAdmin(true);
        $this->adminId = (int) $admin->id;

        Hilos::$rt->connections->actions->register(self::ANONYMOUS_KEY, null);
        Hilos::$rt->connections->actions->register(self::VISITOR_KEY, $this->visitorId);
        Hilos::$rt->connections->actions->register(self::ADMIN_KEY, $this->adminId);
        $witness = Hilos::$db->users->actions->createWithName('Witness of the node');
        $witness->actions->setAdmin(true);
        Hilos::$rt->connections->actions->register(self::WITNESS_KEY, (int) $witness->id);
        $this->drainSignals();
    }

    protected function tearDown(): void
    {
        AbstractHilosLogsKeysPage::removeSubscriber(self::ADMIN_KEY);
        AbstractHilosLogsKeysPage::removeSubscriber(self::WITNESS_KEY);
        ClusterLogIndexMirror::forgetPicture();
        Hilos::$rt->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::create());
        $this->switchMode(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        Hilos::initBrowser();
        $this->drainSignals();
        Hilos::$sr = null;
        parent::tearDown();
    }

    public function testEveryActionOfAnAdminPageButTheReadingOnesIsRefusedToAViewer(): void
    {
        $actions = self::refusedActions();
        self::assertSame(self::REFUSED_ACTIONS, array_keys($actions));

        foreach ($actions as $action => $pageClass) {
            foreach ([self::ANONYMOUS_KEY, self::VISITOR_KEY] as $acceptKey) {
                self::assertSame(
                    ActionViewModeException::class,
                    self::refusalOf($pageClass, $acceptKey, $action),
                    "{$action} from {$acceptKey}",
                );
            }
            self::assertNull(self::refusalOf($pageClass, self::ADMIN_KEY, $action), "{$action} from the admin");
        }

        $this->switchMode(false);
        foreach ($actions as $action => $pageClass) {
            self::assertSame(PageUnauthorizedException::class, self::refusalOf($pageClass, self::ANONYMOUS_KEY, $action), $action);
            self::assertSame(PageForbiddenException::class, self::refusalOf($pageClass, self::VISITOR_KEY, $action), $action);
        }
    }

    public function testATrackedRenameFromAViewerIsRefusedImpersonallyAndRenamesNobody(): void
    {
        $person = Hilos::$db->users->actions->createWithName('Olena Kovalenko');

        $this->dispatch(
            AdminUsersPage::PAGE,
            self::VISITOR_KEY,
            ChatSignalConstants::USER_UPDATE,
            [User::id => (int) $person->id, User::name => 'Renamed by a viewer'],
            self::REQUEST_ID,
        );

        $error = $this->frameTo(self::VISITOR_KEY, SignalConstants::ACTION_ERROR);
        self::assertInstanceOf(PageActionErrorSignalData::class, $error);
        self::assertSame(self::REQUEST_ID, $error->requestId);
        self::assertSame(ActionViewModeException::ERROR_CODE, $error->errorCode);
        self::assertSame(SignalConstants::ACTION_FAILED_REASON, $error->reason);
        self::assertNull($error->errorType);
        self::assertNull($error->errorDetail);
        self::assertSame('Olena Kovalenko', Hilos::$db->users[(int) $person->id]?->name);
    }

    public function testAnUntrackedRenameFromAViewerIsAnsweredWithTheImpersonalSentence(): void
    {
        $person = Hilos::$db->users->actions->createWithName('Olena Kovalenko');

        $this->dispatch(
            AdminUsersPage::PAGE,
            self::ANONYMOUS_KEY,
            ChatSignalConstants::USER_UPDATE,
            [User::id => (int) $person->id, User::name => 'Renamed by a viewer'],
        );

        $error = $this->frameTo(self::ANONYMOUS_KEY, ChatSignalConstants::TABLE_ACTION_ERROR);
        self::assertInstanceOf(TableActionErrorSignalData::class, $error);
        self::assertSame(SignalConstants::ACTION_FAILED_REASON, $error->message);

        $this->dispatch(
            UserPage::PAGE,
            self::VISITOR_KEY,
            HilosSignalConstants::HILOS_USER_UPDATE,
            [User::id => (int) $person->id, User::name => 'Renamed by a viewer'],
        );

        $fail = $this->frameTo(self::VISITOR_KEY, HilosSignalConstants::HILOS_USER_UPDATE_FAIL);
        self::assertInstanceOf(HilosUserUpdateFailSignalData::class, $fail);
        self::assertSame(SignalConstants::ACTION_FAILED_REASON, $fail->reason);
        self::assertSame('Olena Kovalenko', Hilos::$db->users[(int) $person->id]?->name);
    }

    public function testAViewerSubscribesToAnAdminPageOfTheFrameworkAndOfTheProject(): void
    {
        foreach ([UsersPage::class, AdminUsersPage::class, AdminBotsPage::class] as $pageClass) {
            foreach ([self::ANONYMOUS_KEY, self::VISITOR_KEY] as $acceptKey) {
                self::assertAnsweredWithThePage($this->subscribe($pageClass, $acceptKey), "{$pageClass} for {$acceptKey}");
            }
        }
    }

    public function testWithTheModeOffAnAdminPageIsRefusedAsBefore(): void
    {
        $this->switchMode(false);

        foreach ([UsersPage::class, AdminUsersPage::class, AdminBotsPage::class] as $pageClass) {
            self::assertSame(401, $this->subscriptionError($this->subscribe($pageClass, self::ANONYMOUS_KEY))->httpCode, $pageClass);
            self::assertSame(403, $this->subscriptionError($this->subscribe($pageClass, self::VISITOR_KEY))->httpCode, $pageClass);
        }
    }

    public function testTakingTheFlagSwitchesAnOpenPageToViewingAndGivingItBackToFull(): void
    {
        $page = $this->subscribe(UsersPage::class, self::ADMIN_KEY);
        self::assertStringNotContainsString(HiddenValue::KEY, self::wire($page));

        Hilos::$db->users[$this->adminId]?->actions->setAdmin(false);
        $viewing = $this->reassess(UsersPage::class, self::ADMIN_KEY);
        self::assertAnsweredWithThePage($viewing, 'after the flag was taken');
        self::assertStringContainsString(HiddenValue::KEY, self::wire($viewing));

        Hilos::$db->users[$this->adminId]?->actions->setAdmin(true);
        $full = $this->reassess(UsersPage::class, self::ADMIN_KEY);
        self::assertAnsweredWithThePage($full, 'after the flag was given back');
        self::assertStringNotContainsString(HiddenValue::KEY, self::wire($full));
    }

    public function testWithTheModeOffTakingTheFlagRefusesTheOpenPageAsBefore(): void
    {
        $this->switchMode(false);
        $this->subscribe(UsersPage::class, self::ADMIN_KEY);

        Hilos::$db->users[$this->adminId]?->actions->setAdmin(false);

        self::assertSame(403, $this->subscriptionError($this->reassess(UsersPage::class, self::ADMIN_KEY))->httpCode);
    }

    public function testTheFreezeClosesAViewerAsItClosesAnAdmin(): void
    {
        Hilos::$rt->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE,
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedSessionTokenHashes => [],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
        ]));

        foreach ([self::ANONYMOUS_KEY, self::VISITOR_KEY, self::ADMIN_KEY] as $acceptKey) {
            self::assertSame(503, $this->subscriptionError($this->subscribe(UsersPage::class, $acceptKey))->httpCode, $acceptKey);
        }
    }

    public function testASubscriberOfTheLogKeysWhoseRightsWereTakenIsSentNothingWithTheModeOff(): void
    {
        $this->switchMode(false);
        $this->growTheClusterPicture();
        $this->subscribe(LogsKeysPage::class, self::ADMIN_KEY);
        $this->subscribe(LogsKeysPage::class, self::WITNESS_KEY);

        Hilos::$db->users[$this->adminId]?->actions->setAdmin(false);
        $this->growTheClusterPicture();
        $this->tickPastTheThrottle();

        $headers = $this->framesNamed(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_KEYS);
        self::assertArrayHasKey(self::WITNESS_KEY, $headers, 'The tick pushed the header');
        self::assertArrayNotHasKey(self::ADMIN_KEY, $headers);
    }

    public function testASubscriberOfTheLogKeysWhoseRightsWereTakenIsSentTheHiddenFrameWithTheModeOn(): void
    {
        $this->growTheClusterPicture();
        $this->subscribe(LogsKeysPage::class, self::ADMIN_KEY);

        Hilos::$db->users[$this->adminId]?->actions->setAdmin(false);
        $this->growTheClusterPicture();
        $this->tickPastTheThrottle();

        $frame = $this->frameTo(self::ADMIN_KEY, HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_KEYS);
        self::assertInstanceOf(SignalData::class, $frame);
        self::assertNotSame([], $frame->toArray());
        foreach ($frame->toArray() as $field => $value) {
            self::assertTrue(HiddenValue::isMark($value), "field {$field}");
        }
    }

    /**
     * Collects every action of an ADMIN page of the demo that the page does not declare reading.
     *
     * @return array<string, class-string<AbstractPage>> Page class owning each action, sorted by action name
     */
    private static function refusedActions(): array
    {
        $actions = [];
        foreach (Hilos::PAGES as $pageClass) {
            if ($pageClass::ACCESS_LEVEL !== PageAccessLevel::ADMIN) {
                continue;
            }
            foreach (array_keys($pageClass::ACTIONS) as $action) {
                if (!in_array($action, $pageClass::READING_ACTIONS, true)) {
                    $actions[$action] = $pageClass;
                }
            }
        }
        ksort($actions);

        return $actions;
    }

    /**
     * Asks the gate about one action and names what it raised.
     *
     * @param class-string<AbstractPage> $pageClass Page owning the action
     * @param string $acceptKey Connection asking
     * @param string $action Action asked for
     * @return ?class-string<Throwable> Class of the refusal, or null when the action may run
     */
    private static function refusalOf(string $pageClass, string $acceptKey, string $action): ?string
    {
        try {
            PageAccessGate::assertAction($pageClass, $acceptKey, $action);
        } catch (Throwable $e) {
            return $e::class;
        }

        return null;
    }

    /**
     * Sends one action frame through the real page router of the demo.
     *
     * @param string $page Page key owning the action
     * @param string $acceptKey Acting connection
     * @param string $action Action name
     * @param array<string, mixed> $payload Action payload
     * @param ?string $requestId Client-minted request id, or null for an untracked action
     */
    private function dispatch(string $page, string $acceptKey, string $action, array $payload, ?string $requestId = null): void
    {
        $agent = new DemoHilosLegalAgent();
        $router = new PageSignalRouter(new HilosPageFactory($agent, Hilos::class), new ActionRouteConfig([$action => $page]));

        $this->underAgent(
            $agent,
            static fn () => $router->dispatchAction(new WebSocketActionSignalDTO($acceptKey, $action, $payload, $requestId), 'websocket'),
        );
    }

    /**
     * Subscribes one connection to a page through the real page router, as the master hands it over.
     *
     * @param class-string<AbstractPage> $pageClass Page being opened
     * @param string $acceptKey Connection opening it
     * @return list<array{name: string, target: ?string, data: SignalDataInterface}> Frames queued in delivery order
     */
    private function subscribe(string $pageClass, string $acceptKey): array
    {
        $this->drainSignals();
        $subscription = new WebSocketPageSubscribeSignalDTO($acceptKey, $pageClass::PAGE, []);
        Hilos::$sr->subscribeToPage($pageClass::PAGE, $subscription);
        $this->router()->dispatchPageSubscribe($subscription, 'websocket', $pageClass::PAGE);

        return $this->queuedFrames();
    }

    /**
     * Re-decides an open page the way the sweep after a change of rights does.
     *
     * @param class-string<AbstractPage> $pageClass Page open on the connection
     * @param string $acceptKey Connection it is open on
     * @return list<array{name: string, target: ?string, data: SignalDataInterface}> Frames queued in delivery order
     */
    private function reassess(string $pageClass, string $acceptKey): array
    {
        $this->drainSignals();
        $this->router()->dispatchPageAccessReassess(
            new WebSocketPageSubscribeSignalDTO($acceptKey, $pageClass::PAGE, []),
            'websocket',
            $pageClass::PAGE,
        );

        return $this->queuedFrames();
    }

    /**
     * @return PageSignalRouter Page router of the demo, serving its pages by its own registry
     */
    private function router(): PageSignalRouter
    {
        return new PageSignalRouter(new HilosPageFactory(new DemoHilosLegalAgent(), Hilos::class), new ActionRouteConfig());
    }

    /**
     * @return list<array{name: string, target: ?string, data: SignalDataInterface}> Browser frames left in the queue
     */
    private function queuedFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                $frames[] = [
                    'name' => $signal->signalName->getName(),
                    'target' => $signal->data->targetAcceptKey,
                    'data' => $signal->data->data,
                ];
            }
        }

        return $frames;
    }

    /**
     * Takes the queued frames of one name, dropping the rest of the queue.
     *
     * @param string $name Signal name of the frames
     * @return array<string, SignalDataInterface> The last such frame to each connection, keyed by it
     */
    private function framesNamed(string $name): array
    {
        $frames = [];
        foreach ($this->queuedFrames() as $frame) {
            if ($frame['name'] === $name && $frame['target'] !== null) {
                $frames[$frame['target']] = $frame['data'];
            }
        }

        return $frames;
    }

    /**
     * Takes the first queued frame of one name to one connection, dropping the rest of the queue.
     *
     * @param string $acceptKey Connection the frame goes to
     * @param string $name Signal name of the frame
     * @return ?SignalDataInterface The frame, or null when none was queued
     */
    private function frameTo(string $acceptKey, string $name): ?SignalDataInterface
    {
        foreach ($this->queuedFrames() as $frame) {
            if ($frame['name'] === $name && $frame['target'] === $acceptKey) {
                return $frame['data'];
            }
        }

        return null;
    }

    /**
     * Asserts a subscription was answered with the page and not refused.
     *
     * @param list<array{name: string, target: ?string, data: SignalDataInterface}> $frames Frames of one answer
     * @param string $message What was subscribed, for the failure
     */
    private static function assertAnsweredWithThePage(array $frames, string $message): void
    {
        $names = array_column($frames, 'name');
        self::assertContains(SignalTypeConstants::PAGE_RESPONSE, $names, $message);
        self::assertNotContains(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $names, $message);
    }

    /**
     * @param list<array{name: string, target: ?string, data: SignalDataInterface}> $frames Frames of one answer
     * @return PageSubscriptionErrorSignalData The refusal the answer carried
     */
    private function subscriptionError(array $frames): PageSubscriptionErrorSignalData
    {
        foreach ($frames as $frame) {
            if ($frame['name'] === SignalConstants::SUBSCRIPTION_PAGE_ERROR && $frame['data'] instanceof PageSubscriptionErrorSignalData) {
                self::assertNotContains(SignalTypeConstants::PAGE_RESPONSE, array_column($frames, 'name'));

                return $frame['data'];
            }
        }
        self::fail('The answer carried no subscription error');
    }

    /**
     * @param list<array{name: string, target: ?string, data: SignalDataInterface}> $frames Frames of one answer
     * @return string Every frame of the answer as it would travel
     */
    private static function wire(array $frames): string
    {
        return json_encode(array_map(static fn (array $frame): array => $frame['data']->toArray(), $frames), JSON_THROW_ON_ERROR);
    }

    /**
     * Turns the node's mode on or off the way the master does.
     *
     * @param bool $enabled Whether the mode is on
     */
    private function switchMode(bool $enabled): void
    {
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set($enabled);
    }

    /**
     * Files one more picture of the cluster, with one node more than the last, so the header of the
     * log keys differs from what was sent and a tick has a reason to push it.
     */
    private function growTheClusterPicture(): void
    {
        self::$reportedNodes++;

        $slots = [];
        for ($node = 1; $node <= self::$reportedNodes; $node++) {
            $nodeId = 'node-' . $node;
            $slots[] = new ClusterLogNodeSlot(
                nodeId: $nodeId,
                index: new NodeLogIndex(
                    nodeId: $nodeId,
                    available: true,
                    sampledAt: self::T0,
                    batches: [],
                    keys: [new LogKeySummary('worker-0.log', LogKeySummary::CLASS_WORKER, true, [], self::STREAM_BYTES)],
                    workers: [],
                    growthBytesPerDay: [],
                ),
                receivedAt: self::T0,
            );
        }
        ClusterLogIndexMirror::applyPortion(ClusterLogIndexPortionSignalData::ofSlots($slots, true));
    }

    /**
     * Waits out the tick's throttle and runs one tick of the log keys.
     */
    private function tickPastTheThrottle(): void
    {
        $this->drainSignals();
        usleep(self::PAST_THE_TICK_THROTTLE_MICROSECONDS);
        AbstractHilosLogsKeysPage::onAgentTick(new DemoHilosLegalAgent());
    }
}
