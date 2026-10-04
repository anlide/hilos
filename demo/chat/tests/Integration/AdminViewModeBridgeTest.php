<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosAgent;
use Demo\Chat\Agents\Hilos\DemoHilosLegalAgent;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\Hilos\DashboardPage;
use Demo\Chat\Pages\Hilos\Logs\LogsKeysPage;
use Demo\Chat\Pages\Hilos\Users\UserPage;
use Demo\Chat\Pages\Hilos\Users\UsersPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Object\Item\AccountDeletion;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Pages\Logs\DTO\HilosLogsKeysSignalData;
use Hilos\Pages\Users\AccountStandingAudience;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Users\AbstractHilosUsersTable;
use Hilos\Tables\Users\HilosMergeCandidatesTable;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use Hilos\Tables\Users\HilosUserTableRow;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountStanding;
use Hilos\Users\AccountStandingKind;
use Hilos\Users\AccountStandingResolver;
use Hilos\Users\DTO\AccountStandingStateSignalData;

/**
 * The bridge of the admin view mode on the chat demo's real pages and real verdicts (HIL-1250).
 *
 * The mode is the node's own: its runtime row is turned on here the way the lever turns it on, and the
 * one question - is this connection a viewer? - is answered by the demo's own browser context for a
 * person who is not an admin. Nothing is a double. The pages are subscribed straight, the way the
 * dispatcher does once the gate let the viewer look - the gate itself is on trial in
 * AdminViewModeGateTest; what is on trial here is what leaves for the connection: the person's name
 * is hidden by its `_pii` verdict, the last activity, the admin flag and the block are shown by
 * `_piiNotPersonal`, what the surfaces of people declared not personal is shown (HIL-1254) -
 * presence, whether a password is set, the standing with the date a deletion falls due - and
 * everything nobody declared is hidden: the addresses, the sign-in methods, the grace period. The
 * same pages sent to an admin carry no hidden mark at all.
 */
final class AdminViewModeBridgeTest extends IntegrationTestCase
{
    private const string ACCEPT_KEY = 'admin-view-mode-bridge';
    private const string PERSON_ACCEPT_KEY = 'admin-view-mode-bridge-person';
    private const string TEST_AGENT = 'admin-view-mode-bridge-test';

    private int $personId;

    private string $personAddress;

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        TruthSourceRegistry::register(HilosDbContext::accountDeletions, TruthSourceKeys::all(), self::TEST_AGENT);
        Hilos::$rt->connections->actions->clear();
        AccountStandingAudience::reset();
        AccountStandingResolver::forgetAll();
        // The demo's own router, so the dashboard serves the cards of the pages the demo registers.
        Hilos::$sr = new AdminViewModeBridgeRouter();
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);
        $person = Hilos::$db->users->actions->createWithName('Olena Kovalenko');
        $person->actions->setBlock(true);
        $this->personId = (int) $person->id;
        // Online, with an unconfirmed password and a deletion scheduled: every field the card opens has a value.
        Hilos::$rt->connections->actions->register(self::PERSON_ACCEPT_KEY, $this->personId);
        $this->personAddress = "olena-{$this->personId}@example.test";
        Hilos::$db->identities->createPasswordIdentity($this->personId, $this->personAddress, 'correct horse battery');
        Hilos::$db->accountDeletions->actions->request($this->personId, date('Y-m-d H:i:s', time() + TimeConstants::SECONDS_PER_DAY));
    }

    protected function tearDown(): void
    {
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        ClusterLogIndexMirror::removeViewer(self::ACCEPT_KEY);
        new LogsKeysPage(new DemoHilosLegalAgent())->onUnsubscribe(self::ACCEPT_KEY);
        Hilos::initBrowser();
        Hilos::$rt->connections->actions->clear();
        AccountStandingAudience::reset();
        AccountStandingResolver::forgetAll();
        TruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        Hilos::$sr = null;
        parent::tearDown();
    }

    public function testAViewerSeesAPersonWithTheNameHiddenAndWhatIsNotPersonalShown(): void
    {
        $this->connect(admin: false);

        $frames = $this->subscribe(UserPage::class, ['userId' => (string) $this->personId]);

        $slots = $this->declarativeRow($frames)[PagePayload::slots];
        $user = $slots[ChatDbContext::users];
        self::assertTrue(HiddenValue::isMark($user[HilosUserTableRow::name]));
        self::assertSame($this->personId, $user[HilosUserTableRow::id]);
        self::assertFalse($user[HilosUserTableRow::admin]);
        self::assertTrue($user[HilosUserTableRow::block]);
        self::assertArrayHasKey(HilosUserTableRow::lastActivity, $user);
        self::assertFalse(HiddenValue::isMark($user[HilosUserTableRow::lastActivity]));
        $connections = $slots[HilosUserDetailBrowserTable::CONNECTIONS];
        self::assertSame($this->personId, $connections[HilosConnection::userId]);
        self::assertSame(HilosUserPresenceSummary::PRESENCE_ONLINE, $connections[HilosUserPresenceSummary::presence]);
        self::assertSame(1, $connections[HilosUserPresenceSummary::onlineSessionCount]);
        $identities = $slots[HilosDbContext::identities];
        self::assertTrue($identities[HilosMergeCandidatesTable::FIELD_HAS_PASSWORD]);
        self::assertTrue(HiddenValue::isMark($identities[HilosMergeCandidatesTable::FIELD_UNVERIFIED_PASSWORD_ADDRESS]));
        $deletion = $slots[HilosDbContext::accountDeletions];
        self::assertIsInt($deletion[HilosUserTableRow::FIELD_DELETION_EFFECTIVE_AT]);
        // The table is under PURGE; only the computed date was opened (the owner, 2026-10-01).
        self::assertTrue(HiddenValue::isMark($deletion[AccountDeletion::userId]));

        $data = $this->pageData($frames);
        self::assertIsString($data[PageCatalogConstants::WIRE_PAGE_LABEL]);
        self::assertIsArray($data[PageCatalogConstants::WIRE_PAGE_BREADCRUMB]);
        self::assertTrue(HiddenValue::isMark($data[UserPage::ACCOUNT_DELETION_GRACE_DAYS]));
        $standing = $data[UserPage::ACCOUNT_STANDING];
        self::assertSame(AccountStandingKind::BLOCKED->value, $standing[AccountStanding::shown]);
        self::assertTrue($standing[AccountStanding::blocked]);
        self::assertFalse($standing[AccountStanding::frozen]);
        self::assertIsInt($standing[AccountStanding::deletionEffectiveAt]);
        self::assertStringNotContainsString(HiddenValue::KEY, json_encode($standing, JSON_THROW_ON_ERROR));
        $sent = json_encode(self::payloads($frames), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Olena Kovalenko', $sent);
        self::assertStringNotContainsString($this->personAddress, $sent);
    }

    public function testAViewerSeesThePeopleListWithTheNameHiddenAndTheRestShown(): void
    {
        $this->connect(admin: false);

        $frames = $this->subscribe(UsersPage::class);

        $rows = $this->window($frames, ChatTableContext::hilosUsers)[TableWindowSignalData::rows];
        self::assertNotSame([], $rows);
        foreach ($rows as $row) {
            $user = $row[PagePayload::slots][AbstractHilosUsersTable::SLOT_USER];
            self::assertTrue(HiddenValue::isMark($user[HilosUserTableRow::name]));
            foreach ([HilosUserTableRow::id, HilosUserTableRow::admin, HilosUserTableRow::block, HilosUserTableRow::lastActivity] as $field) {
                self::assertFalse(HiddenValue::isMark($user[$field]), "field {$field}");
            }
            $connections = $row[PagePayload::slots][AbstractHilosUsersTable::SLOT_CONNECTIONS];
            self::assertFalse(HiddenValue::isMark($connections[HilosUserTableRow::presence]));
            self::assertFalse(HiddenValue::isMark($connections[HilosUserTableRow::onlineSessionCount]));
        }
        self::assertStringNotContainsString('Olena Kovalenko', json_encode(self::payloads($frames), JSON_THROW_ON_ERROR));
    }

    public function testAViewerSeesTheMergeCandidatesWithTheirSignInMethodsAndAddressHidden(): void
    {
        $this->connect(admin: false);

        $frames = $this->subscribe(UserPage::class, ['userId' => (string) $this->personId]);

        $rows = $this->window($frames, ChatTableContext::hilosMergeCandidates)[TableWindowSignalData::rows];
        self::assertNotSame([], $rows);
        foreach ($rows as $row) {
            $user = $row[PagePayload::slots][HilosMergeCandidatesTable::SLOT_USER];
            self::assertTrue(HiddenValue::isMark($user[HilosUserTableRow::name]));
            foreach ([HilosUserTableRow::id, HilosUserTableRow::admin, HilosUserTableRow::block, HilosUserTableRow::lastActivity] as $field) {
                self::assertFalse(HiddenValue::isMark($user[$field]), "field {$field}");
            }
            $merge = $row[PagePayload::slots][HilosMergeCandidatesTable::SLOT_MERGE];
            // The methods go whole: the merge window reads the list as one value (HIL-1263).
            self::assertTrue(HiddenValue::isMark($merge[HilosMergeCandidatesTable::FIELD_IDENTITIES]));
            self::assertIsBool($merge[HilosMergeCandidatesTable::FIELD_HAS_PASSWORD]);
            self::assertTrue(HiddenValue::isMark($merge[HilosMergeCandidatesTable::FIELD_UNVERIFIED_PASSWORD_ADDRESS]));
        }
    }

    public function testAViewerIsSentTheLiveStandingOfTheCardShown(): void
    {
        $this->connect(admin: false);
        $this->subscribe(UserPage::class, ['userId' => (string) $this->personId]);

        Hilos::$db->users[$this->personId]->actions->setBlock(false);
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());

        $frame = $this->frameNamed($this->queuedFrames(), HilosSignalConstants::HILOS_ACCOUNT_STANDING_STATE)->toArray();
        self::assertSame($this->personId, $frame[AccountStandingStateSignalData::userId]);
        $standing = $frame[AccountStandingStateSignalData::accountStanding];
        self::assertSame(AccountStandingKind::DELETION_SCHEDULED->value, $standing[AccountStanding::shown]);
        self::assertFalse($standing[AccountStanding::blocked]);
        self::assertIsInt($standing[AccountStanding::deletionEffectiveAt]);
        self::assertStringNotContainsString(HiddenValue::KEY, json_encode($frame, JSON_THROW_ON_ERROR));
    }

    public function testAViewerIsSentTheLogKeysFrameUntypedWithClusterFieldsVisible(): void
    {
        $this->connect(admin: false);

        $frame = $this->frameNamed($this->subscribe(LogsKeysPage::class), HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_KEYS);

        self::assertInstanceOf(SignalData::class, $frame);
        self::assertNotSame([], $frame->toArray());
        self::assertFalse(HiddenValue::isMark($frame->toArray()[HilosLogsKeysSignalData::available]));
        self::assertFalse(HiddenValue::isMark($frame->toArray()[HilosLogsKeysSignalData::nodes]));
    }

    public function testAViewerSeesTheSectionsOfTheDashboard(): void
    {
        $this->connect(admin: false);

        $sections = $this->pageData($this->subscribe(DashboardPage::class))[PageCatalogConstants::WIRE_DASHBOARD_SECTIONS];

        self::assertIsArray($sections);
        self::assertNotSame([], $sections);
        self::assertFalse(HiddenValue::isMark($sections));
    }

    public function testAnAdminIsSentThePagesWithoutASingleMark(): void
    {
        $this->connect(admin: true);

        $card = $this->subscribe(UserPage::class, ['userId' => (string) $this->personId]);
        Hilos::$db->users[$this->personId]->actions->setBlock(false);
        AccountStandingAudience::onAgentTick(new DemoHilosAgent());
        $standing = $this->frameNamed($this->queuedFrames(), HilosSignalConstants::HILOS_ACCOUNT_STANDING_STATE);
        $frames = [
            ...$card,
            ...$this->subscribe(UsersPage::class),
            ...$this->subscribe(LogsKeysPage::class),
            ...$this->subscribe(DashboardPage::class),
        ];

        $user = $this->declarativeRow($frames)[PagePayload::slots][ChatDbContext::users];
        self::assertSame('Olena Kovalenko', $user[HilosUserTableRow::name]);
        self::assertNotSame([], $this->window($card, ChatTableContext::hilosMergeCandidates)[TableWindowSignalData::rows]);
        self::assertInstanceOf(AccountStandingStateSignalData::class, $standing);
        self::assertStringNotContainsString(HiddenValue::KEY, json_encode($standing->toArray(), JSON_THROW_ON_ERROR));
        self::assertInstanceOf(
            HilosLogsKeysSignalData::class,
            $this->frameNamed($frames, HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_KEYS),
        );
        self::assertStringNotContainsString(HiddenValue::KEY, json_encode(self::payloads($frames), JSON_THROW_ON_ERROR));
    }

    /**
     * Opens a connection for a person who is an admin or is not.
     *
     * @param bool $admin Whether the person behind the connection is an admin
     */
    private function connect(bool $admin): void
    {
        $user = Hilos::$db->users->actions->createWithName($admin ? 'Admin of the node' : 'Visitor of the node');
        $user->actions->setAdmin($admin);
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', $this->name()), 0, 32));
        $session->actions->bindUser((int) $user->id);
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, (int) $user->id, $session->token, (int) $session->id);
    }

    /**
     * @param class-string<AbstractPage> $pageClass Page being opened
     * @param array<string, string> $params Route parameters
     * @return list<array{name: string, data: SignalDataInterface}> Queued browser frames in delivery order
     */
    private function subscribe(string $pageClass, array $params = []): array
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        Hilos::$sr->subscribeToPage($pageClass::PAGE, new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, $pageClass::PAGE, $params));
        ExecutionContext::run(new ExecutionFrame(acceptKey: self::ACCEPT_KEY), static function () use ($pageClass, $params): void {
            new $pageClass(new DemoHilosLegalAgent())->onSubscribe(self::ACCEPT_KEY, new PageRouteParams($params));
        });

        return $this->queuedFrames();
    }

    /**
     * @return list<array{name: string, data: SignalDataInterface}> Browser frames queued since the last read, in delivery order
     */
    private function queuedFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                $frames[] = ['name' => $signal->signalName->getName(), 'data' => $signal->data->data];
            }
        }

        return $frames;
    }

    /**
     * @param list<array{name: string, data: SignalDataInterface}> $frames Frames of one subscription
     * @return array<string, mixed> The one row of the person-detail table
     */
    private function declarativeRow(array $frames): array
    {
        foreach (self::payloads($frames) as $payload) {
            $rows = $payload[PageResponseSignalData::payload][PagePayload::tables][HilosUserDetailBrowserTable::TABLE][PagePayload::rows] ?? null;
            if (is_array($rows)) {
                self::assertCount(1, $rows);

                return $rows[0];
            }
        }
        self::fail('No page answer carried the person-detail table');
    }

    /**
     * @param list<array{name: string, data: SignalDataInterface}> $frames Frames of one subscription
     * @param string $tableKey Viewport table whose window is read
     * @return array<string, mixed> The window section of that table
     */
    private function window(array $frames, string $tableKey): array
    {
        foreach (self::payloads($frames) as $payload) {
            $window = $payload[PageResponseSignalData::payload][PagePayload::windows][$tableKey] ?? null;
            if (is_array($window)) {
                return $window;
            }
        }
        self::fail("No page answer carried the window of {$tableKey}");
    }

    /**
     * @param list<array{name: string, data: SignalDataInterface}> $frames Frames of one subscription
     * @return array<string, mixed> The data section of the page's own answer, the one carrying the shell
     */
    private function pageData(array $frames): array
    {
        foreach (self::payloads($frames) as $payload) {
            $data = $payload[PageResponseSignalData::payload][PagePayload::data] ?? null;
            if (is_array($data) && array_key_exists(PageCatalogConstants::WIRE_PAGE_LABEL, $data)) {
                return $data;
            }
        }
        self::fail('No page answer carried the shell');
    }

    /**
     * @param list<array{name: string, data: SignalDataInterface}> $frames Frames of one subscription
     * @param string $name Signal name of the frame
     * @return SignalDataInterface The first frame under that name
     */
    private function frameNamed(array $frames, string $name): SignalDataInterface
    {
        foreach ($frames as $frame) {
            if ($frame['name'] === $name) {
                return $frame['data'];
            }
        }
        self::fail("No frame named {$name}");
    }

    /**
     * @param list<array{name: string, data: SignalDataInterface}> $frames Frames of one subscription
     * @return list<array<string, mixed>> Wire arrays of the page answers among them
     */
    private static function payloads(array $frames): array
    {
        return array_values(array_map(
            static fn(array $frame): array => $frame['data']->toArray(),
            array_filter($frames, static fn(array $frame): bool => $frame['name'] === SignalTypeConstants::PAGE_RESPONSE),
        ));
    }
}

/**
 * Router answering from the chat demo's topology rather than the framework's bare one.
 */
final class AdminViewModeBridgeRouter extends SignalRouter
{
    /**
     * @return class-string<Hilos> The chat demo's facade
     */
    protected function hilosClass(): string
    {
        return Hilos::class;
    }
}
