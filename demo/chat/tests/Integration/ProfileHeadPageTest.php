<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Browser\ChatBrowserList;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\ProfileAgreementsHistoryPage;
use Demo\Chat\Pages\Hilos\ProfileAgreementsPage;
use Demo\Chat\Pages\Hilos\ProfileDataPage;
use Demo\Chat\Pages\Hilos\ProfileDevicesPage;
use Demo\Chat\Pages\Hilos\ProfileNotificationsPage;
use Demo\Chat\Pages\Hilos\ProfilePage;
use Demo\Chat\Pages\Hilos\ProfileSecurityPage;
use Demo\Chat\Pages\Hilos\ProfileSessionsPage;
use Demo\Chat\Pages\Hilos\ProfileSignInPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\AccountDeletion\AccountDeletionGroup;
use Hilos\Auth\AccountDeletion\AccountDeletionStateProjector;
use Hilos\Auth\SecondFactor\DTO\SecondFactorStateSignalData;
use Hilos\Auth\SecondFactor\SecondFactorGroup;
use Hilos\Auth\SecondFactor\SecondFactorStateProjector;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Group\DTO\GroupJoinSignalData;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\DataExport\DataExportGroup;
use Hilos\DataExport\DataExportStateProjector;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\Identity;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Legal\LegalAgreementsGroup;
use Hilos\Legal\LegalAgreementsProjector;
use Hilos\Pages\AbstractHilosProfileSectionPage;
use Hilos\Pages\AbstractHilosProfileSignInPage;
use Hilos\Pages\Profile\HilosProfileIdentitiesBrowserList;
use Hilos\Pages\Profile\HilosProfileTimeZones;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

/** Cold subscriptions carry common personal data beside each section's own body and shared owner identity. */
final class ProfileHeadPageTest extends IntegrationTestCase
{
    private const string ACCEPT_KEY = 'profile-head-owner';
    private const string OWNER = 'test-agent';
    private const array FACTOR_COLLECTIONS = [
        HilosDbContext::secondFactors,
        HilosDbContext::secondFactorBackupCodes,
        HilosDbContext::secondFactorResets,
        HilosDbContext::secondFactorSettings,
    ];

    private int $userId;
    private int $foreignUserId;
    private int $identityId;
    private ?SignalRouter $previousRouter = null;
    /** @var list<string> Join announcements from the most recent answer */
    private array $joins = [];

    /** The existing Chat harness owns the handler and DB claims; this case adds its personal-factor fixture. */
    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::FACTOR_COLLECTIONS as $collection) {
            TruthSourceRegistry::register($collection, TruthSourceKeys::all(), self::OWNER);
        }
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::OWNER);
        Hilos::$rt->connections->actions->clear();
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new ChatSignalRouter();
        $this->userId = (int)Hilos::$db->users->actions->createWithName('Profile head owner')->id;
        $this->foreignUserId = (int)Hilos::$db->users->actions->createWithName('Foreign profile owner')->id;
        $this->identityId = (int)Hilos::$db->identities->createOauthIdentity($this->userId, 'github', 'head-' . $this->userId)->id;
        Hilos::$db->identities->createSmsIdentity($this->foreignUserId, '+1000000' . $this->foreignUserId);
        Hilos::$db->secondFactors->actions->startEnrolment($this->userId, 'Own app', 'JBSWY3DPEHPK3PXP')->actions->confirm('Own app');
        Hilos::$db->secondFactors->actions
            ->startEnrolment($this->foreignUserId, 'Foreign app', 'KRSXG5DSNFXGOIDB')->actions->confirm('Foreign app');
        Hilos::$db->secondFactorBackupCodes->actions->issueSet($this->userId, ['own-code-a', 'own-code-b']);
        Hilos::$db->secondFactorBackupCodes->actions->issueSet($this->foreignUserId, ['foreign-code']);
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', $this->nameWithDataSet()), 0, 32));
        $session->actions->bindUser($this->userId);
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $this->userId, $session->token, (int)$session->id);
    }

    /** Restores the router and releases the case's connections; the existing harness ends the handler. */
    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        Hilos::$sr = $this->previousRouter;
        parent::tearDown();
    }

    /** @return iterable<string, array{class-string<AbstractHilosProfileSectionPage>}> Every served Chat profile page */
    public static function pages(): iterable
    {
        yield 'root' => [ProfilePage::class];
        yield 'sign in' => [ProfileSignInPage::class];
        yield 'notifications' => [ProfileNotificationsPage::class];
        yield 'sessions' => [ProfileSessionsPage::class];
        yield 'devices' => [ProfileDevicesPage::class];
        yield 'security' => [ProfileSecurityPage::class];
        yield 'agreements' => [ProfileAgreementsPage::class];
        yield 'history' => [ProfileAgreementsHistoryPage::class];
        yield 'data' => [ProfileDataPage::class];
    }

    /** @param class-string<AbstractHilosProfileSectionPage> $pageClass Subscribed section */
    #[DataProvider('pages')]
    public function testOneColdAnswerCarriesCommonStateAndTheExistingBody(string $pageClass): void
    {
        $expected = SecondFactorStateProjector::stateFor($this->userId)->toArray();
        Hilos::$db->forgetLoadedDataForTest();
        $payload = $this->subscribe(new $pageClass(new ChatAgent()));
        $data = $payload->data;
        self::assertSame(Hilos::hasFeature(HilosFeature::PROFILE_PHOTO), $data[$pageClass::PROFILE_PHOTO_SECTION]);
        self::assertSame($expected, $data[$pageClass::SECOND_FACTOR_SECTION]);
        self::assertSame($expected, SecondFactorStateSignalData::fromArray($data[$pageClass::SECOND_FACTOR_SECTION])->toArray());
        self::assertSame(['Own app'], array_column($data[$pageClass::SECOND_FACTOR_SECTION]['authenticators'], 'label'));
        self::assertSame(2, $data[$pageClass::SECOND_FACTOR_SECTION]['backupCodesLeft']);
        self::assertSame(2, $data[$pageClass::SECOND_FACTOR_SECTION]['backupCodesTotal']);
        $items = $payload->lists[HilosProfileIdentitiesBrowserList::LIST][PagePayload::items];
        self::assertCount(1, $items);
        self::assertSame([$this->identityId], array_column($items[0][PagePayload::slots][HilosDbContext::identities], Identity::id));
        self::assertSame(SecondFactorGroup::forUser($this->userId),
            Hilos::$sr->groupSubscriptionName(self::ACCEPT_KEY, SecondFactorGroup::NAME));
        self::assertSame(1, count(array_keys($this->joins, SecondFactorGroup::forUser($this->userId), true)));
        self::assertNotContains(SecondFactorGroup::forUser($this->foreignUserId), $this->joins);
        self::assertArrayHasKey(PageCatalogConstants::WIRE_PAGE_LABEL, $data);
        self::assertArrayHasKey(PageCatalogConstants::WIRE_PAGE_BREADCRUMB, $data);
        self::assertSame([
            'page' => ProfilePage::PAGE,
            'label' => 'Profile',
            'lead' => 'Your account and everything that belongs to it.',
        ], $data[PageCatalogConstants::WIRE_PAGE_HEAD_OWNER]);
        self::assertSame([['page' => ProfilePage::PAGE, 'label' => 'Profile']], $data[PageCatalogConstants::WIRE_PAGE_BREADCRUMB]);
        self::assertSame([
            ProfilePage::PAGE, ProfileSignInPage::PAGE, ProfileNotificationsPage::PAGE,
            ProfileSessionsPage::PAGE, ProfileDevicesPage::PAGE, ProfileSecurityPage::PAGE,
            ProfileAgreementsPage::PAGE, ProfileAgreementsHistoryPage::PAGE, ProfileDataPage::PAGE,
        ], array_column($data[PageCatalogConstants::WIRE_PAGE_TABS], 'page'));
        self::assertSame([], $data[PageCatalogConstants::WIRE_PAGE_CHILDREN]);

        if ($pageClass === ProfilePage::class) {
            foreach ([
                ProfileNotificationsPage::NOTIFICATION_SECTION,
                LegalAgreementsProjector::SECTION,
                DataExportStateProjector::SECTION,
            ] as $slot) {
                self::assertArrayNotHasKey($slot, $data);
            }
            self::assertArrayHasKey(AccountDeletionStateProjector::SECTION, $data);
            self::assertArrayHasKey(HilosProfileTimeZones::LIST, $payload->lists);
            self::assertArrayNotHasKey(ChatBrowserList::PROFILE_SESSIONS, $payload->lists);
            self::assertArrayNotHasKey(ChatBrowserList::PROFILE_DEVICES, $payload->lists);
            self::assertNotContains(LegalAgreementsGroup::forUser($this->userId), $this->joins);
            self::assertNotContains(DataExportGroup::forUser($this->userId), $this->joins);
            self::assertContains(AccountDeletionGroup::forUser($this->userId), $this->joins);
        } elseif ($pageClass === ProfileSessionsPage::class) {
            self::assertArrayHasKey(ChatBrowserList::PROFILE_SESSIONS, $payload->lists);
        } elseif ($pageClass === ProfileDevicesPage::class) {
            self::assertArrayHasKey(ChatBrowserList::PROFILE_DEVICES, $payload->lists);
            self::assertArrayHasKey(ProfileNotificationsPage::NOTIFICATION_SECTION, $data);
        } elseif ($pageClass === ProfileNotificationsPage::class) {
            self::assertArrayHasKey(ProfileNotificationsPage::NOTIFICATION_SECTION, $data);
            self::assertArrayHasKey(ProfileNotificationsPage::ADDRESS_SECTION, $data);
        } elseif ($pageClass === ProfileAgreementsPage::class) {
            self::assertArrayHasKey(LegalAgreementsProjector::TEXTS_SECTION, $data);
            self::assertContains(LegalAgreementsGroup::forUser($this->userId), $this->joins);
        } elseif ($pageClass === ProfileAgreementsHistoryPage::class) {
            self::assertArrayHasKey(LegalAgreementsProjector::REVISIONS_SECTION, $data);
            self::assertContains(LegalAgreementsGroup::forUser($this->userId), $this->joins);
        } elseif ($pageClass === ProfileDataPage::class) {
            self::assertArrayHasKey(DataExportStateProjector::SECTION, $data);
            self::assertContains(DataExportGroup::forUser($this->userId), $this->joins);
        }
    }

    /** @param class-string<AbstractHilosProfileSectionPage> $pageClass Section refused to a guest */
    #[DataProvider('pages')]
    public function testAGuestGetsNoPersonalResponseOrGroupMembership(string $pageClass): void
    {
        Hilos::$rt->connections->actions->register('profile-head-guest', null);
        Hilos::$sr = new ChatSignalRouter();
        $subscription = new WebSocketPageSubscribeSignalDTO('profile-head-guest', $pageClass::PAGE);
        Hilos::$sr->subscribeToPage($pageClass::PAGE, $subscription);
        new PageSignalRouter(new HilosPageFactory(new ChatAgent(), Hilos::class), new ActionRouteConfig())
            ->dispatchPageSubscribe($subscription, 'websocket', $pageClass::PAGE);
        $refusal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($refusal);
        self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $refusal->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $refusal->data);
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $refusal->data->data);
        self::assertSame(401, $refusal->data->data->httpCode);
        self::assertSame($pageClass::PAGE, $refusal->data->data->page);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        self::assertNull(Hilos::$sr->groupSubscriptionName('profile-head-guest', SecondFactorGroup::NAME));
    }

    public function testProjectHooksPreserveEveryPayloadSectionAndCannotReplaceTheCommonSnapshot(): void
    {
        $page = new ProfileHeadProjectHookPage(new ChatAgent());
        $contribution = $page->snapshot(self::ACCEPT_KEY);
        self::assertNotNull($contribution);
        self::assertSame(['entity' => ['id' => 1]], $contribution->entities);
        self::assertSame(['projectList' => ['items' => []]], $contribution->lists);
        self::assertSame(['projectTable' => ['rows' => []]], $contribution->tables);
        self::assertSame(['projectWindow' => ['rows' => []]], $contribution->windows);
        self::assertSame(['projectRefusal' => ['errorCode' => 'forbidden']], $contribution->refusedWindows);
        $payload = $this->subscribe($page);
        self::assertSame(SecondFactorStateProjector::stateFor($this->userId)->toArray(), $payload->data[$page::SECOND_FACTOR_SECTION]);
        self::assertSame(Hilos::hasFeature(HilosFeature::PROFILE_PHOTO), $payload->data[$page::PROFILE_PHOTO_SECTION]);
        self::assertSame('project body', $payload->data['project']);
        self::assertSame(['entity' => ['id' => 1]], $payload->entities);
        self::assertSame(['items' => []], $payload->lists['projectList']);
        self::assertArrayHasKey(HilosProfileIdentitiesBrowserList::LIST, $payload->lists);
        self::assertSame(['projectTable' => ['rows' => []]], $payload->tables);
        self::assertTrue($page->commonJoinedBeforeOwnHook);
        self::assertSame(1, count(array_keys($this->joins, SecondFactorGroup::forUser($this->userId), true)));
    }

    /**
     * @param AbstractPage $page Real section or project-hook fixture
     * @return PagePayload The single complete page answer
     */
    private function subscribe(AbstractPage $page): PagePayload
    {
        Hilos::$sr = new ChatSignalRouter();
        Hilos::$sr->subscribeToPage($page::PAGE, new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, $page::PAGE));
        ExecutionContext::run(new ExecutionFrame(acceptKey: self::ACCEPT_KEY), function () use ($page): void {
            $page->onSubscribe(self::ACCEPT_KEY, new PageRouteParams(['userId' => (string)$this->foreignUserId]));
        });
        $answers = [];
        $this->joins = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof GroupJoinSignalData) {
                self::assertSame(self::ACCEPT_KEY, $signal->data->acceptKey);
                $this->joins[] = $signal->data->group;
            } elseif ($signal->signalName->getName() === SignalTypeConstants::PAGE_RESPONSE) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
                self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
                self::assertSame($page::PAGE, $signal->data->data->pageKey);
                $answers[] = $signal->data->data->payload;
            } else {
                self::fail('Unexpected companion frame: ' . $signal->signalName->getName());
            }
        }
        self::assertCount(1, $answers);

        return $answers[0];
    }
}

/** Uses an existing page address only inside this test; no product registration changes. */
final class ProfileHeadProjectHookPage extends AbstractHilosProfileSignInPage
{
    public bool $commonJoinedBeforeOwnHook = false;

    /**
     * @param string $acceptKey Subscribing connection
     * @return ?PagePayload Common composition before the catalog shell is applied
     */
    public function snapshot(string $acceptKey): ?PagePayload
    {
        return $this->buildPagePayload($acceptKey, new PageRouteParams([]));
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route parameters
     * @return ?PagePayload Every kind of project contribution, including conflicting common keys
     */
    protected function buildProfilePagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
    {
        return new PagePayload(
            entities: ['entity' => ['id' => 1]],
            data: [
                self::PROFILE_PHOTO_SECTION => 'project override',
                self::SECOND_FACTOR_SECTION => 'project override',
                'project' => 'project body',
            ],
            lists: ['projectList' => ['items' => []]],
            tables: ['projectTable' => ['rows' => []]],
            windows: ['projectWindow' => ['rows' => []]],
            refusedWindows: ['projectRefusal' => ['errorCode' => 'forbidden']],
        );
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Route parameters
     */
    protected function onProfileSubscribeAfterResponse(string $acceptKey, PageRouteParams $params): void
    {
        $userId = Hilos::$browser->resolveActionUserId($acceptKey);
        $this->commonJoinedBeforeOwnHook = $userId !== null
            && Hilos::$sr->groupSubscriptionName($acceptKey, SecondFactorGroup::NAME) === SecondFactorGroup::forUser($userId);
    }
}
