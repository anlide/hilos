<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Browser\Data\SelfConnectionBrowserData;
use Demo\Chat\Browser\List\ProfileSessionsBrowserList;
use Demo\Chat\Core\Router\DTO\SelfConnectionSignalData;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\ProfileDevicesPage;
use Demo\Chat\Pages\Hilos\ProfileSessionsPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
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
use Hilos\Database\Object\Item\Session;
use Hilos\Notification\NotificationChannelPreferenceProjector;
use Hilos\Pages\AbstractHilosProfilePage;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;

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
        [$userId, $selfSessionId, $otherSessionId] = $this->createUserWithTwoSessions();

        Hilos::$browser?->subscribeSnapshot(
            ProfileSessionsPage::PAGE,
            self::ACCEPT_KEY,
            new PageRouteParams([]),
        );

        $payload = $this->nextPagePayload(ProfileSessionsPage::PAGE);
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
        }
        self::assertEqualsCanonicalizing(
            [self::ACCEPT_KEY, self::OTHER_ACCEPT_KEY],
            array_column($connections, SelfConnectionSignalData::acceptKey),
        );

        $selfConnection = $payload[PagePayload::data][SelfConnectionBrowserData::DATA];
        self::assertSame(self::ACCEPT_KEY, $selfConnection[SelfConnectionSignalData::acceptKey]);
        self::assertSame($selfSessionId, $selfConnection[SelfConnectionSignalData::sessionId]);
    }

    public function testEndingAnotherSessionRemovesItFromTheSubscribersList(): void
    {
        [$userId, $selfSessionId, $otherSessionId] = $this->createUserWithTwoSessions();
        $otherSession = Hilos::$db->sessions[$otherSessionId];
        self::assertNotNull($otherSession);
        $otherSession->actions->unbindUser();

        Hilos::$browser?->record(SourceChange::dbUpdated(
            ChatDbContext::sessions,
            (string)$otherSessionId,
            [Session::userId => null],
            previous: [Session::userId => $userId],
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
            AbstractHilosProfilePage::NOTIFICATION_SECTION,
        );
        self::assertSame(
            new NotificationChannelPreferenceProjector()->sectionData($userId)->toArray(),
            $payload[PagePayload::data][AbstractHilosProfilePage::NOTIFICATION_SECTION],
        );
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
        $otherSession = Hilos::$db->sessions->actions->createAnonymous($this->sessionToken('other'));
        $otherSession->actions->bindUser($userId);
        $otherSession->actions->setDeviceName('Other browser');

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
