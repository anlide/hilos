<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Browser\Data\SelfConnectionBrowserData;
use Demo\Chat\Browser\List\ProfileDevicesBrowserList;
use Demo\Chat\Browser\List\ProfileSessionsBrowserList;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\SelfConnectionSignalData;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\ProfileDevicesPage;
use Demo\Chat\Pages\Hilos\ProfileNotificationsPage;
use Demo\Chat\Pages\Hilos\ProfilePage;
use Demo\Chat\Pages\Hilos\ProfileSessionsPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\AccountDeletion\AccountDeletionGroup;
use Hilos\Auth\AccountDeletion\AccountDeletionStateProjector;
use Hilos\Auth\SecondFactor\SecondFactorGroup;
use Hilos\Auth\SecondFactor\SecondFactorStateProjector;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\DataExport\DataExportGroup;
use Hilos\DataExport\DataExportStateProjector;
use Hilos\Database\Object\Item\Session;
use Hilos\Database\Object\Item\PushSubscription;
use Hilos\Database\Pages\HilosPageCatalog;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Legal\LegalAgreementsGroup;
use Hilos\Legal\LegalAgreementsProjector;
use Hilos\Legal\LegalStandingResolver;
use Hilos\Notification\NotificationChannelPreferenceProjector;
use Hilos\Pages\AbstractHilosProfileNotificationsPage;
use Hilos\Pages\AbstractHilosProfileSecurityPage;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Integration coverage for the current user's session list and its first-render page data.
 */
final class ProfileSessionsListTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'profile-sessions-test-agent';
    private const string ACCEPT_KEY = 'ak-profile-sessions';
    private const string OTHER_ACCEPT_KEY = 'ak-profile-sessions-other';

    protected function setUp(): void
    {
        parent::setUp();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$sr = new SignalRouter();
        Hilos::$sr->subscribeToPage(
            ProfileSessionsPage::PAGE,
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, ProfileSessionsPage::PAGE),
        );
    }

    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testSnapshotCarriesOwnSessionsAndTabsWithoutTokens(): void
    {
        $before = TimeHelper::nowMs();
        [$userId, $selfSessionId, $otherSessionId] = $this->createUserWithTwoSessions();
        $after = TimeHelper::nowMs();
        $foreignUserId = (int)Hilos::$db->users->actions->createWithName('Foreign snapshot owner')->id;
        $foreignSession = Hilos::$db->sessions->actions->createAnonymous($this->sessionToken('foreign'));
        $foreignSession->actions->bindUser($foreignUserId);

        $payload = Hilos::$browser?->buildSubscribeSnapshot(
            ProfileSessionsPage::PAGE,
            self::ACCEPT_KEY,
            new PageRouteParams([]),
        )->toArray();

        self::assertIsArray($payload);
        $item = $payload[PagePayload::lists][ProfileSessionsBrowserList::LIST][PagePayload::items][0];
        $sessions = $item[PagePayload::slots][ChatDbContext::sessions];
        $connections = $item[PagePayload::slots][ChatRtContext::connections];

        self::assertSame($userId, $item[PagePayload::itemKey]);
        self::assertEqualsCanonicalizing(
            [$selfSessionId, $otherSessionId],
            array_column($sessions, Session::id),
        );
        foreach ($sessions as $session) {
            self::assertArrayNotHasKey(Session::token, $session);
            self::assertArrayNotHasKey(Session::ipAddress, $session);
            self::assertSame(1767225600000, $session[Session::createdAt]);
            self::assertSame(1767227400000, $session[Session::lastSeenAt]);
            self::assertSame(
                $session[Session::id] === $selfSessionId ? 1767312000000 : null,
                $session[Session::expiresAt],
            );
            self::assertSame(
                $session[Session::id] === $selfSessionId ? 'US/Eastern' : null,
                $session[Session::browserTimeZone],
            );
            self::assertSame(
                $session[Session::id] === $selfSessionId ? 'Current browser' : 'Other browser',
                $session[Session::deviceName],
            );
        }
        self::assertEqualsCanonicalizing(
            [self::ACCEPT_KEY, self::OTHER_ACCEPT_KEY],
            array_column($connections, SelfConnectionSignalData::acceptKey),
        );
        foreach ($connections as $connection) {
            self::assertGreaterThanOrEqual($before, $connection[SelfConnectionSignalData::connectedAt]);
            self::assertLessThanOrEqual($after, $connection[SelfConnectionSignalData::connectedAt]);
        }

        $selfConnection = $payload[PagePayload::data][SelfConnectionBrowserData::DATA];
        self::assertSame(self::ACCEPT_KEY, $selfConnection[SelfConnectionSignalData::acceptKey]);
        self::assertSame($selfSessionId, $selfConnection[SelfConnectionSignalData::sessionId]);
        self::assertSame(
            Hilos::$rt->connections[self::ACCEPT_KEY]->connectedAt,
            $selfConnection[SelfConnectionSignalData::connectedAt],
        );
    }

    public function testTimeZoneAndActivityChangesReachTheOpenListInMilliseconds(): void
    {
        [, $selfSessionId] = $this->createUserWithTwoSessions();
        $initial = Hilos::$browser?->buildSubscribeSnapshot(
            ProfileSessionsPage::PAGE,
            self::ACCEPT_KEY,
            new PageRouteParams([]),
        )->toArray();
        $initialSessions = $initial[PagePayload::lists][ProfileSessionsBrowserList::LIST][PagePayload::items][0]
            [PagePayload::slots][ChatDbContext::sessions];
        $initialSession = array_column($initialSessions, null, Session::id)[$selfSessionId];
        $this->drainSignals();

        foreach (['Europe/Warsaw', null] as $zone) {
            Hilos::$db->sessions[$selfSessionId]->actions->setBrowserTimeZone($zone);
            // Fixture-only clock control: the public action stamps now, not a chosen SQL instant.
            $session = Hilos::$db->getObjectCollection(ChatDbContext::sessions)->get($selfSessionId);
            $session->lastSeenAt = '2026-01-01 01:30:00';
            $session->sync();
            while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
                if ($signal->data instanceof DbSyncUpdatedSignalData && $signal->data->collectionKey === ChatDbContext::sessions) {
                    Hilos::$browser?->record(SourceChange::dbUpdated(
                        $signal->data->collectionKey,
                        $signal->data->idString,
                        $signal->data->row,
                        previous: $signal->data->previous,
                    ));
                }
            }
            Hilos::$browser?->flushToSignalRouter();

            $payload = $this->nextPagePayload(ProfileSessionsPage::PAGE);
            $sessions = $payload[PagePayload::lists][ProfileSessionsBrowserList::LIST][PagePayload::items][0]
                [PagePayload::slots][ChatDbContext::sessions];
            $liveSession = array_column($sessions, null, Session::id)[$selfSessionId];
            self::assertSame(
                array_replace($initialSession, [Session::browserTimeZone => $zone, Session::lastSeenAt => 1767231000000]),
                $liveSession,
            );
        }
    }

    public function testDeviceSqlMomentsAreNumericAndSecretsStayOffTheWire(): void
    {
        [$userId] = $this->createUserWithTwoSessions();
        TruthSourceRegistry::register(ChatDbContext::pushSubscriptions, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        // Test fixture boundary: the subscribe action stamps now; these rows need fixed SQL instants.
        foreach ([null, '2026-01-01 00:30:00'] as $index => $goneAt) {
            $device = PushSubscription::create();
            $device->userId = $userId;
            $device->endpoint = 'https://push.example.test/' . $this->name() . '/' . $index;
            $device->endpointHash = hash('sha256', $device->endpoint);
            $device->p256dh = 'private-p256dh';
            $device->auth = 'private-auth';
            $device->deviceName = 'Device ' . $index;
            $device->createdAt = '2026-01-01 00:00:00';
            $device->goneAt = $goneAt;
            $device->sync();
        }

        $snapshot = Hilos::$browser?->buildSubscribeSnapshot(ProfileDevicesPage::PAGE, self::ACCEPT_KEY, new PageRouteParams([]));
        $devices = $snapshot->lists[ProfileDevicesBrowserList::LIST][PagePayload::items][0]
            [PagePayload::slots][ChatDbContext::pushSubscriptions];
        self::assertCount(2, $devices);
        self::assertSame([null, 1767227400000], array_column($devices, PushSubscription::goneAt));
        foreach ($devices as $device) {
            self::assertSame(1767225600000, $device[PushSubscription::createdAt]);
            foreach ([PushSubscription::endpoint, PushSubscription::p256dh, PushSubscription::auth, PushSubscription::lastSeenAt] as $secret) {
                self::assertArrayNotHasKey($secret, $device);
            }
        }
    }

    public function testEndingAnotherSessionRemovesItFromTheSubscribersList(): void
    {
        [$userId, $selfSessionId, $otherSessionId] = $this->createUserWithTwoSessions();
        self::assertArrayHasKey($otherSessionId, Hilos::$db->sessions);
        Hilos::$db->sessions[$otherSessionId]->actions->unbindUser();

        $syncData = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if (
                $signal->data instanceof DbSyncUpdatedSignalData
                && $signal->data->collectionKey === ChatDbContext::sessions
                && $signal->data->idString === (string) $otherSessionId
            ) {
                $syncData = $signal->data;
                break;
            }
        }
        self::assertNotNull($syncData);

        Hilos::$browser?->record(SourceChange::dbUpdated(
            $syncData->collectionKey,
            $syncData->idString,
            $syncData->row,
            previous: $syncData->previous,
        ));
        Hilos::$browser?->flushToSignalRouter();

        $payload = $this->nextPagePayload(ProfileSessionsPage::PAGE);
        $item = $payload[PagePayload::lists][ProfileSessionsBrowserList::LIST][PagePayload::items][0];
        self::assertSame(
            [$selfSessionId],
            array_column($item[PagePayload::slots][ChatDbContext::sessions], Session::id),
        );
    }

    public function testAnotherUsersSessionSendsNeitherItemNorDelete(): void
    {
        $this->createUserWithTwoSessions();
        $foreignUserId = (int)Hilos::$db->users->actions->createWithName('Foreign session owner')->id;
        $foreignSession = Hilos::$db->sessions->actions->createAnonymous($this->sessionToken('foreign'));
        $foreignSession->actions->bindUser($foreignUserId);
        $this->drainSignals();

        Hilos::$browser?->record(SourceChange::dbUpdated(
            ChatDbContext::sessions,
            (string)$foreignSession->id,
            [Session::deviceName => 'Foreign browser'],
        ));
        Hilos::$browser?->flushToSignalRouter();

        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testDevicesPageCarriesNotificationPreferencesOnFirstResponse(): void
    {
        [$userId] = $this->createUserWithTwoSessions();
        Hilos::$sr = new SignalRouter();

        ExecutionContext::run(
            new ExecutionFrame(acceptKey: self::ACCEPT_KEY),
            static function (): void {
                new ProfileDevicesPage(new ChatAgent())->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
            },
        );

        $payload = $this->nextPagePayloadWithData(
            ProfileDevicesPage::PAGE,
            AbstractHilosProfileNotificationsPage::NOTIFICATION_SECTION,
        );
        self::assertSame(
            new NotificationChannelPreferenceProjector()->sectionData($userId)->toArray(),
            $payload[PagePayload::data][AbstractHilosProfileNotificationsPage::NOTIFICATION_SECTION],
        );
    }

    public function testNotificationsPageCarriesNotificationPreferencesOnFirstResponse(): void
    {
        [$userId] = $this->createUserWithTwoSessions();
        Hilos::$sr = new SignalRouter();

        ExecutionContext::run(
            new ExecutionFrame(acceptKey: self::ACCEPT_KEY),
            static function (): void {
                new ProfileNotificationsPage(new ChatAgent())->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
            },
        );

        $payload = $this->nextPagePayloadWithData(
            ProfileNotificationsPage::PAGE,
            AbstractHilosProfileNotificationsPage::NOTIFICATION_SECTION,
        );
        self::assertSame(
            new NotificationChannelPreferenceProjector()->sectionData($userId)->toArray(),
            $payload[PagePayload::data][AbstractHilosProfileNotificationsPage::NOTIFICATION_SECTION],
        );
    }

    public function testNotificationsPageNamesTheSignInSectionForAnAddress(): void
    {
        $this->createUserWithTwoSessions();
        Hilos::$sr = new ChatSignalRouter();

        ExecutionContext::run(
            new ExecutionFrame(acceptKey: self::ACCEPT_KEY),
            static function (): void {
                new ProfileNotificationsPage(new ChatAgent())->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
            },
        );

        $payload = $this->nextPagePayloadWithData(
            ProfileNotificationsPage::PAGE,
            AbstractHilosProfileNotificationsPage::ADDRESS_SECTION,
        );
        self::assertSame(
            [
                PageCatalogConstants::WIRE_CHILD_PAGE => HilosPageConstants::HILOS_PROFILE_SIGN_IN,
                PageCatalogConstants::CATALOG_ENTRY_LABEL
                    => HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_PROFILE_SIGN_IN][PageCatalogConstants::CATALOG_ENTRY_LABEL],
                PageCatalogConstants::CATALOG_ENTRY_LEAD
                    => HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_PROFILE_SIGN_IN][PageCatalogConstants::CATALOG_ENTRY_LEAD],
            ],
            $payload[PagePayload::data][AbstractHilosProfileNotificationsPage::ADDRESS_SECTION],
        );
    }

    public function testProfilePageCarriesTheSecondFactorSection(): void
    {
        [$userId] = $this->createUserWithTwoSessions();
        Hilos::$sr = new SignalRouter();

        ExecutionContext::run(
            new ExecutionFrame(acceptKey: self::ACCEPT_KEY),
            static function (): void {
                new ProfilePage(new ChatAgent())->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
            },
        );

        $payload = $this->nextPagePayloadWithData(
            ProfilePage::PAGE,
            AbstractHilosProfileSecurityPage::SECOND_FACTOR_SECTION,
        );
        self::assertSame(
            SecondFactorStateProjector::stateFor($userId)->toArray(),
            $payload[PagePayload::data][AbstractHilosProfileSecurityPage::SECOND_FACTOR_SECTION],
        );
        self::assertSame(
            SecondFactorGroup::forUser($userId),
            Hilos::$sr->groupSubscriptionName(self::ACCEPT_KEY, SecondFactorGroup::forUser($userId)),
        );
    }

    public function testProfilePageCarriesTheDeletionAndDataSections(): void
    {
        [$userId] = $this->createUserWithTwoSessions();
        Hilos::$sr = new SignalRouter();

        ExecutionContext::run(
            new ExecutionFrame(acceptKey: self::ACCEPT_KEY),
            static function (): void {
                new ProfilePage(new ChatAgent())->onSubscribe(self::ACCEPT_KEY, new PageRouteParams([]));
            },
        );

        $payload = $this->nextPagePayloadWithData(ProfilePage::PAGE, AccountDeletionStateProjector::SECTION);
        self::assertSame(
            AccountDeletionStateProjector::stateFor($userId)->toArray(),
            $payload[PagePayload::data][AccountDeletionStateProjector::SECTION],
        );
        self::assertSame(
            LegalAgreementsProjector::stateFor($userId, LegalStandingResolver::today())->toArray(),
            $payload[PagePayload::data][LegalAgreementsProjector::SECTION],
        );
        self::assertArrayHasKey(DataExportStateProjector::SECTION, $payload[PagePayload::data]);
        self::assertSame(
            DataExportStateProjector::nodeFor(Hilos::$db->dataExports->ofUser($userId)),
            $payload[PagePayload::data][DataExportStateProjector::SECTION],
        );
        foreach ([
            AccountDeletionGroup::forUser($userId),
            LegalAgreementsGroup::forUser($userId),
            DataExportGroup::forUser($userId),
        ] as $group) {
            self::assertSame($group, Hilos::$sr->groupSubscriptionName(self::ACCEPT_KEY, $group));
        }
    }

    /**
     * @return array{0: int, 1: int, 2: int} User id, own session id, and other session id
     */
    private function createUserWithTwoSessions(): array
    {
        $userId = (int)Hilos::$db->users->actions->createWithName('Profile sessions owner')->id;
        $selfSession = Hilos::$db->sessions->actions->createAnonymous($this->sessionToken('self'));
        $selfSession->actions->bindUser($userId);
        $selfSession->actions->setDeviceName('Current browser');
        $selfSession->actions->setBrowserTimeZone('US/Eastern');
        $otherSession = Hilos::$db->sessions->actions->createAnonymous($this->sessionToken('other'));
        $otherSession->actions->bindUser($userId);
        $otherSession->actions->setDeviceName('Other browser');

        // Fixture-only dates through the mounted ORM; no production write API accepts a chosen clock.
        foreach ([$selfSession, $otherSession] as $session) {
            $object = Hilos::$db->getObjectCollection(ChatDbContext::sessions)->get($session->id);
            $object->createdAt = '2026-01-01 00:00:00';
            $object->lastSeenAt = '2026-01-01 00:30:00';
            $object->expiresAt = $session->id === $selfSession->id ? '2026-01-02 00:00:00' : null;
            $object->sync();
        }

        $selfSessionId = (int)$selfSession->id;
        $otherSessionId = (int)$otherSession->id;
        Hilos::$rt->connections->actions->register(
            self::ACCEPT_KEY,
            $userId,
            $selfSession->token,
            $selfSessionId,
        );
        Hilos::$rt->connections->actions->register(
            self::OTHER_ACCEPT_KEY,
            $userId,
            $otherSession->token,
            $otherSessionId,
        );

        return [$userId, $selfSessionId, $otherSessionId];
    }

    /**
     * @param string $suffix Role of the session inside the current test
     * @return string Stable valid token unique to this test and role
     */
    private function sessionToken(string $suffix): string
    {
        return substr(hash('sha256', $this->name() . ':' . $suffix), 0, 32);
    }

    /**
     * @param string $page Page key whose response is expected
     * @return array<string, mixed> Page payload
     */
    private function nextPagePayload(string $page): array
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE) {
                continue;
            }
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
            $response = $signal->data->data->toArray();
            if (($response[PageResponseSignalData::page] ?? null) !== $page) {
                continue;
            }

            $payload = $response[PageResponseSignalData::payload] ?? [];

            return is_array($payload) ? $payload : [];
        }

        self::fail("Page {$page} answered with no page response");
    }

    /**
     * @param string $page Page key whose response is expected
     * @param string $dataKey Data key the response must carry
     * @return array<string, mixed> Page payload carrying the requested data key
     */
    private function nextPagePayloadWithData(string $page, string $dataKey): array
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE) {
                continue;
            }
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
            $response = $signal->data->data->toArray();
            $payload = $response[PageResponseSignalData::payload] ?? [];
            if (
                ($response[PageResponseSignalData::page] ?? null) === $page
                && is_array($payload)
                && isset($payload[PagePayload::data][$dataKey])
            ) {
                return $payload;
            }
        }

        self::fail("Page {$page} answered with no {$dataKey} data");
    }
}
