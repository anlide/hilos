<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosLegalAgent;
use Demo\Chat\Browser\Table\UserDetailBrowserTable;
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
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Log\ClusterLogIndexMirror;
use Hilos\Pages\Logs\DTO\HilosLogsKeysSignalData;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Users\HilosUserTableRow;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * The bridge of the admin view mode on the chat demo's real pages and real verdicts (HIL-1250).
 *
 * The mode is the node's own: its runtime row is turned on here the way the lever turns it on, and the
 * one question - is this connection a viewer? - is answered by the demo's own browser context for a
 * person who is not an admin. Nothing is a double. The pages are subscribed straight, the way the
 * dispatcher does once the gate let the viewer look - the gate itself is on trial in
 * AdminViewModeGateTest; what is on trial here is what leaves for the connection: the person's name
 * is hidden by its `_pii` verdict, the last activity, the admin flag and the block are shown by
 * `_piiNotPersonal`, and everything nobody declared is hidden - while the same pages sent to an admin
 * carry no hidden mark at all.
 */
final class AdminViewModeBridgeTest extends IntegrationTestCase
{
    private const string ACCEPT_KEY = 'admin-view-mode-bridge';
    private const string TEST_AGENT = 'admin-view-mode-bridge-test';

    private int $personId;

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        Hilos::$rt->connections->actions->clear();
        // The demo's own router, so the dashboard serves the cards of the pages the demo registers.
        Hilos::$sr = new AdminViewModeBridgeRouter();
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);
        $person = Hilos::$db->users->actions->createWithName('Olena Kovalenko');
        $person->actions->setBlock(true);
        $this->personId = (int) $person->id;
    }

    protected function tearDown(): void
    {
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        ClusterLogIndexMirror::removeViewer(self::ACCEPT_KEY);
        new LogsKeysPage(new DemoHilosLegalAgent())->onUnsubscribe(self::ACCEPT_KEY);
        Hilos::initBrowser();
        Hilos::$rt->connections->actions->clear();
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
        // Presence comes from RT and is computed; nobody declared it not personal yet (HIL-1254).
        foreach ($slots[ChatRtContext::connections] ?? [] as $value) {
            self::assertTrue(HiddenValue::isMark($value));
        }

        $data = $this->pageData($frames);
        self::assertIsString($data[PageCatalogConstants::WIRE_PAGE_LABEL]);
        self::assertIsArray($data[PageCatalogConstants::WIRE_PAGE_BREADCRUMB]);
        self::assertTrue(HiddenValue::isMark($data[UserPage::ACCOUNT_DELETION_GRACE_DAYS]));
        self::assertStringNotContainsString('Olena Kovalenko', json_encode(self::payloads($frames), JSON_THROW_ON_ERROR));
    }

    public function testAViewerSeesThePeopleListWithEverythingButTheKeyHidden(): void
    {
        $this->connect(admin: false);

        $frames = $this->subscribe(UsersPage::class);

        $rows = $this->window($frames, ChatTableContext::hilosUsers)[TableWindowSignalData::rows];
        self::assertNotSame([], $rows);
        foreach ($rows as $row) {
            foreach ($row[PagePayload::slots] as $slot) {
                foreach ($slot as $field => $value) {
                    self::assertSame($field === HilosUserTableRow::id, !HiddenValue::isMark($value), "field {$field}");
                }
            }
        }
        self::assertStringNotContainsString('Olena Kovalenko', json_encode(self::payloads($frames), JSON_THROW_ON_ERROR));
    }

    public function testAViewerIsSentTheLogKeysFrameUntypedAndHidden(): void
    {
        $this->connect(admin: false);

        $frame = $this->frameNamed($this->subscribe(LogsKeysPage::class), HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LOGS_KEYS);

        self::assertInstanceOf(SignalData::class, $frame);
        self::assertNotSame([], $frame->toArray());
        foreach ($frame->toArray() as $field => $value) {
            self::assertTrue(HiddenValue::isMark($value), "field {$field}");
        }
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

        $frames = [
            ...$this->subscribe(UserPage::class, ['userId' => (string) $this->personId]),
            ...$this->subscribe(UsersPage::class),
            ...$this->subscribe(LogsKeysPage::class),
            ...$this->subscribe(DashboardPage::class),
        ];

        $user = $this->declarativeRow($frames)[PagePayload::slots][ChatDbContext::users];
        self::assertSame('Olena Kovalenko', $user[HilosUserTableRow::name]);
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
            $rows = $payload[PageResponseSignalData::payload][PagePayload::tables][UserDetailBrowserTable::TABLE][PagePayload::rows] ?? null;
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
