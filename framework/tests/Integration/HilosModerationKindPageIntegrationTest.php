<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Hilos;
use Hilos\Moderation\ModerationInput;
use Hilos\Moderation\ModerationKind;
use Hilos\Moderation\ModerationKindDirectory;
use Hilos\Moderation\ModerationSettingsCatalog;
use Hilos\Pages\Moderation\AbstractHilosModerationKindPage;
use Hilos\Pages\Moderation\AbstractHilosModerationPage;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Tables\Moderation\HilosModerationKindsTable;
use Hilos\Users\AccountStandingResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/**
 * Real kind admission, canonical windows and source-driven delivery to root and detail.
 *
 * Uses the commit-capable lifecycle because actual action announcements must reach the
 * browser source bus; an always-rollback root would hold those announcements until cleanup.
 */
final class HilosModerationKindPageIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = [
        'hilos_user',
        'hilos_user_merge',
        'hilos_account_deletion',
        'hilos_moderation_rule',
        'hilos_setting',
    ];
    private const string OWNER_AGENT_ID = 'test-agent:moderation-kind-page-integration';

    private ?HilosDbContext $previousDb = null;
    private ?SignalRouter $previousSignalRouter = null;
    private ?SettingsAccessor $previousSettings = null;
    private ?BrowserContext $previousBrowser = null;
    private ?TableContext $previousTables = null;
    private string $boundAppClass = Hilos::class;
    /** @var array<string, mixed> Account-standing memory restored with the original context */
    private array $previousStanding = [];

    /** Prepares schema and real action writers before each case. */
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['standings', 'lapsedUserIds', 'epoch'] as $property) {
            $this->previousStanding[$property] = new ReflectionProperty(AccountStandingResolver::class, $property)->getValue();
        }
        new ReflectionProperty(AccountStandingResolver::class, 'standings')->setValue(null, []);

        self::runStubs(down: true);
        self::runStubs(down: false);
        Database::sqlRun("INSERT INTO hilos_user (id, name, admin) VALUES (7, 'Admin', 1), (5, 'Viewer', 0)");
        Schema::reset();
        Schema::initialize();

        $this->previousDb = Hilos::$db;
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousSettings = Hilos::$setting;
        $this->previousBrowser = Hilos::$browser;
        $this->previousTables = Hilos::$table;
        $this->boundAppClass = Hilos::appClass();
        self::bindAppClass(ModerationKindPageIntegrationTestHilos::class);
        $db = new ModerationKindPageIntegrationTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        Hilos::$sr = new ModerationKindPageTestSignalRouter();
        Hilos::$setting = new SettingsAccessor(ModerationKindPageTestSettingsCatalog::class);
        Hilos::$table = new ModerationKindPageIntegrationTestTableContext();
        Hilos::$table->configure();

        foreach ([HilosDbContext::moderationRules, HilosDbContext::settings] as $collection) {
            TruthSourceRegistry::register($collection, TruthSourceKeys::all(), self::OWNER_AGENT_ID);
        }
        ExecutionContext::setCurrentAgentId(self::OWNER_AGENT_ID);
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        SourceChangeBus::subscribe(new ModerationKindPageTestBrowserSubscriber());
    }

    /** Releases the fixture globals, claims and physical tables. */
    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        TruthSourceRegistry::unregisterAgent(self::OWNER_AGENT_ID);
        SourceChangeBus::reset();
        Hilos::$db = $this->previousDb;
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$setting = $this->previousSettings;
        Hilos::$table = $this->previousTables;
        $appClass = $this->boundAppClass;
        if ($this->previousBrowser === null) {
            Hilos::resetBrowser();
        } else {
            $appClass::initBrowser($this->previousBrowser);
        }
        self::bindAppClass($this->boundAppClass);
        foreach ($this->previousStanding as $property => $value) {
            new ReflectionProperty(AccountStandingResolver::class, $property)->setValue(null, $value);
        }
        self::runStubs(down: true);
        parent::tearDown();
    }

    public function testFirstResponseContainsTheKindHeadAndOneCompletePermittedRow(): void
    {
        $this->bootBrowser();
        $payload = $this->subscribe('detail', ['kind' => 'test_note']);
        $data = $payload['payload']['data'];
        self::assertSame([
            'page' => HilosPageConstants::HILOS_MODERATION_KIND,
            'label' => 'Kind',
            'lead' => 'One declared check and the rules in force.',
            'icon' => 'bi-shield-check',
        ], $data['pageHeadOwner']);
        self::assertSame('Kind', $data['pageLabel']);
        self::assertSame('One declared check and the rules in force.', $data['pageLead']);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_MODERATION, 'label' => 'Moderation'],
            ['page' => HilosPageConstants::HILOS_MODERATION_KIND, 'label' => 'Kind'],
        ], $data['pageBreadcrumb']);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_MODERATION_KIND, 'label' => 'Overview'],
        ], $data['pageTabs']);
        self::assertSame([], $data['pageChildren']);
        self::assertSame([
            'kind' => 'test_note', 'title' => 'Project note', 'owner' => 'project', 'input' => 'text_with_files',
            'model' => 'text-default', 'ownRuleCount' => 0, 'profile' => 'hilos.moderation.text', 'reasons' => ['rude'],
            'defaultRules' => ['Polite notes.'],
        ], $this->assertSelectedWindow($payload, 'test_note'));
        self::assertSame([], AbstractHilosModerationKindPage::ACTIONS);
        self::assertSame([], AbstractHilosModerationKindPage::SIGNALS);
        self::assertSame([], AbstractHilosModerationKindPage::READING_ACTIONS);
        self::assertNull(Hilos::$sr->pageResource('detail'));
    }

    public function testColdDirectEntryAndRepeatSubscribeSelectTheLastKindBeyondTheRootWindow(): void
    {
        $this->bootBrowser();
        $root = $this->subscribe('root', [], page: ModerationKindRootTestPage::PAGE);
        $window = $root['payload']['windows'][HilosModerationKindsTable::TABLE];
        self::assertSame(34, $window['totalCount']);
        self::assertSame(25, $window['limit']);
        self::assertCount(25, $window['rows']);
        self::assertNotContains('extra_29', array_column($window['rows'], 'rowKey'));
        $this->assertSelectedWindow($this->subscribe('direct', ['kind' => 'extra_29']), 'extra_29');
        $this->assertSelectedWindow($this->subscribe('direct', ['kind' => 'hilos_profile_name']), 'hilos_profile_name');
        $this->assertSelectedWindow($this->subscribe('direct', ['kind' => 'extra_29']), 'extra_29');
    }

    /** A root report still controls root; held and reported list windows cannot control detail. */
    public function testRootWindowAndReconnectReportAreLocallyNormalizedToTheUrl(): void
    {
        $this->bootBrowser();
        $reported = new TableWindowDescriptorDTO(filter: ['search' => 'Project'], limit: 2, rendered: ['title']);
        $root = $this->subscribe('root', [], reports: [HilosModerationKindsTable::TABLE => $reported],
            page: ModerationKindRootTestPage::PAGE);
        self::assertSame(['test_note', 'test_picture'], array_column(
            $root['payload']['windows'][HilosModerationKindsTable::TABLE]['rows'], 'rowKey',
        ));
        self::assertSame(['search' => 'Project'], Hilos::$sr->getTableViewport('root', HilosModerationKindsTable::TABLE)->filter);
        self::assertSame(2, Hilos::$sr->getTableViewport('root', HilosModerationKindsTable::TABLE)->limit);

        $stale = new TableWindowDescriptorDTO(
            filter: ['kind' => 'test_picture', 'search' => 'no matching text'],
            sort: TableSortOrderDTO::fromWire([['field' => 'model', 'direction' => 'desc']]),
            limit: 25,
            anchor: new TableAnchorDTO(['kind' => 'test_picture']),
            anchorDirection: TableAnchorDirection::Before,
            rendered: ['model'],
        );
        Hilos::$sr->setTableViewport('root', new TableViewportSubscription(
            tableKey: HilosModerationKindsTable::TABLE, filter: $stale->filter, sort: $stale->sort,
            limit: $stale->limit, anchor: $stale->anchor, anchorDirection: $stale->anchorDirection,
        ));
        $this->assertSelectedWindow($this->subscribe('root', ['kind' => 'extra_29']), 'extra_29');
        $this->assertCanonicalViewport('root', 'extra_29');

        // A new accept key has no server memory: only the URL and the stale client report remain.
        $this->assertSelectedWindow($this->subscribe('reconnected', ['kind' => 'test_note'], reports: [
            HilosModerationKindsTable::TABLE => $stale,
        ]), 'test_note');
        $this->assertCanonicalViewport('reconnected', 'test_note');
        $paged = new TableWindowDescriptorDTO(filter: ['kind' => 'test_picture'], limit: 100, pageIndex: 10);
        $this->assertSelectedWindow($this->subscribe('reconnected', ['kind' => 'extra_29'], reports: [
            HilosModerationKindsTable::TABLE => $paged,
        ]), 'extra_29');
        $this->assertCanonicalViewport('reconnected', 'extra_29');
    }

    public function testUpdateRevalidatesTheNewKindAndAnswersWithAWholePage(): void
    {
        $this->bootBrowser();
        $router = $this->createPageRouter();
        $this->subscribe('update', ['kind' => 'test_note'], router: $router);
        foreach (['test_picture', 'extra_29'] as $kind) {
            $router->dispatchPageUpdateSubscription(
                new WebSocketPageUpdateSubscriptionSignalDTO('update', ModerationKindTestPage::PAGE, ['kind' => $kind]),
                SignalSource::WORKER, ModerationKindTestPage::PAGE,
            );
            $frames = $this->takeFrames();
            self::assertCount(1, $frames);
            self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $frames[0]['name']);
            $this->assertSelectedWindow($frames[0]['data'], $kind);
            $this->assertCanonicalViewport('update', $kind);
            self::assertSame(['kind' => $kind], Hilos::$sr->pageSubscription('update')->params);
        }
        $router->dispatchPageUpdateSubscription(
            new WebSocketPageUpdateSubscriptionSignalDTO('update', ModerationKindTestPage::PAGE, ['kind' => 'unknown']),
            SignalSource::WORKER, ModerationKindTestPage::PAGE,
        );
        $frames = $this->takeFrames();
        self::assertCount(1, $frames);
        self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $frames[0]['name']);
        self::assertSame(404, $frames[0]['data']['httpCode']);
        self::assertSame(['kind' => 'extra_29'], Hilos::$sr->pageSubscription('update')->params);
    }

    /** @return list<array{?int, bool, array<string, string>, int, string}> Closed admission and invalid addresses */
    public static function refusedSubscriptions(): array
    {
        return [
            [null, false, ['kind' => 'test_note'], 401, 'unauthorized'],
            [5, false, ['kind' => 'test_note'], 403, 'forbidden'],
            [7, true, ['kind' => 'unknown_kind'], 404, 'not_found'],
            [7, true, [], 400, 'missing_page_route_param'],
            [7, true, ['kind' => ''], 400, 'missing_page_route_param'],
        ];
    }

    /**
     * @param ?int $userId Acting person or guest
     * @param bool $admin Whether the person proves admin
     * @param array<string, string> $params Requested address
     * @param int $status Expected status
     * @param string $error Expected error code
     */
    #[DataProvider('refusedSubscriptions')]
    public function testAdmissionRefusesWithoutAnEmptySuccessfulOverview(
        ?int $userId, bool $admin, array $params, int $status, string $error,
    ): void {
        $this->bootBrowser($userId, $admin);
        $this->createPageRouter()->dispatchPageSubscribe(
            new WebSocketPageSubscribeSignalDTO('refused', ModerationKindTestPage::PAGE, $params),
            SignalSource::WORKER, ModerationKindTestPage::PAGE,
        );
        $frames = $this->takeFrames();
        self::assertCount(1, $frames);
        self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $frames[0]['name']);
        self::assertSame($status, $frames[0]['data']['httpCode']);
        self::assertSame($error, $frames[0]['data']['errorCode']);
        self::assertArrayNotHasKey('payload', $frames[0]['data']);
        self::assertNull(Hilos::$sr->getTableViewport('refused', HilosModerationKindsTable::TABLE));
    }

    public function testViewerKeepsProfileAndReasonsAndReceivesOnlyThePermittedModel(): void
    {
        Hilos::$setting = new SettingsAccessor(ModerationKindPageTestTextHiddenCatalog::class);
        $this->bootBrowser(userId: 5, admin: false, viewer: true);
        $payload = $this->subscribe('viewer', ['kind' => 'test_note']);
        self::assertStringNotContainsString('text-default', json_encode($payload, JSON_THROW_ON_ERROR));
        $text = $this->assertSelectedWindow($payload, 'test_note');
        self::assertSame([
            'kind' => 'test_note', 'title' => 'Project note', 'owner' => 'project', 'input' => 'text_with_files',
            'model' => HiddenValue::mark(), 'ownRuleCount' => 0, 'profile' => 'hilos.moderation.text', 'reasons' => ['rude'],
            'defaultRules' => ['Polite notes.'],
        ], $text);
        $image = $this->assertSelectedWindow($this->subscribe('viewer', ['kind' => 'test_picture']), 'test_picture');
        self::assertSame('vision-default', $image['model']);
        self::assertSame('hilos.moderation.image', $image['profile']);
        self::assertSame(['nudity'], $image['reasons']);
    }

    public function testRealRuleAndModelFactsReachRootAndTheirOwnDetailWithoutReload(): void
    {
        $browser = $this->bootBrowser();
        $this->subscribe('root', [], page: ModerationKindRootTestPage::PAGE);
        $this->subscribe('note', ['kind' => 'test_note']);
        $this->subscribe('picture', ['kind' => 'test_picture']);
        $rule = Hilos::$db->moderationRules->actions->create('test_note', 'A note rule.');
        $frames = $this->flushBrowser($browser);
        $this->assertChangedKind($frames, ['root', 'note'], 'test_note', 1);
        $rule->actions->delete();
        $this->assertChangedKind($this->flushBrowser($browser), ['root', 'note'], 'test_note', 0);
        Hilos::$db->moderationRules->actions->create('test_picture', 'A picture rule.');
        $this->assertChangedKind($this->flushBrowser($browser), ['root', 'picture'], 'test_picture', 1);

        foreach ([
            [ModerationSettingsCatalog::TEXT_MODEL, 'text-live', 'note', 'test_note', 'picture'],
            [ModerationSettingsCatalog::IMAGE_MODEL, 'vision-live', 'picture', 'test_picture', 'note'],
        ] as [$key, $model, $target, $kind, $foreign]) {
            Hilos::$db->settings->actions->add($key, $model, Hilos::$setting->catalog());
            $frames = $this->flushBrowser($browser);
            $found = [];
            foreach ($frames as $frame) {
                self::assertSame(SignalTypeConstants::TABLE_VIEWPORT_DELTA, $frame['name']);
                self::assertNotSame($foreign, $frame['target']);
                self::assertSame($model, $frame['data']['row']['slots']['kind']['model']);
                if ($frame['target'] === $target) {
                    self::assertSame($kind, $frame['data']['rowKey']);
                }
                if ($frame['data']['rowKey'] === $kind) {
                    $found[] = $frame['target'];
                }
            }
            sort($found);
            $expected = ['root', $target];
            sort($expected);
            self::assertSame($expected, $found);
        }
    }

    /**
     * @param ?int $userId Acting person or guest
     * @param bool $admin Whether the person proves admin
     * @param bool $viewer Whether admin view mode admits the person
     * @return ModerationKindPageTestBrowser Mounted browser context
     */
    private function bootBrowser(?int $userId = 7, bool $admin = true, bool $viewer = false): ModerationKindPageTestBrowser
    {
        $browser = new ModerationKindPageTestBrowser($userId, $admin, $viewer);
        ModerationKindPageIntegrationTestHilos::initBrowser($browser);

        return $browser;
    }

    /**
     * @param string $acceptKey Connection to subscribe
     * @param array<string, string> $params Route address
     * @param array<string, TableWindowDescriptorDTO> $reports Client-held table windows
     * @param string $page Page being opened
     * @param ?PageSignalRouter $router Existing dispatcher or a fresh one
     * @return array<string, mixed> The single complete page response
     */
    private function subscribe(
        string $acceptKey,
        array $params,
        array $reports = [],
        string $page = ModerationKindTestPage::PAGE,
        ?PageSignalRouter $router = null,
    ): array {
        $request = new WebSocketPageSubscribeSignalDTO($acceptKey, $page, $params, $reports);
        ($router ?? $this->createPageRouter())->dispatchPageSubscribe($request, SignalSource::WORKER, $page);
        Hilos::$sr->subscribeToPage($page, $request);
        $frames = $this->takeFrames();
        self::assertCount(1, $frames);
        self::assertSame($acceptKey, $frames[0]['target']);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $frames[0]['name']);
        self::assertSame($page, $frames[0]['data']['page']);

        return $frames[0]['data'];
    }

    /**
     * @param array<string, mixed> $payload Page response under test
     * @param string $kind Expected selected kind
     * @return array<string, mixed> Complete selected slot
     */
    private function assertSelectedWindow(array $payload, string $kind): array
    {
        self::assertSame(ModerationKindTestPage::PAGE, $payload['page']);
        self::assertSame([HilosModerationKindsTable::TABLE], array_keys($payload['payload']['windows']));
        $window = $payload['payload']['windows'][HilosModerationKindsTable::TABLE];
        self::assertSame(1, $window['limit']);
        self::assertSame(1, $window['totalCount']);
        self::assertTrue($window['totalExact']);
        self::assertSame([], $window['sort']);
        self::assertSame([$kind], array_column($window['rows'], 'rowKey'));
        self::assertSame($kind, $window['rows'][0]['slots']['kind']['kind']);

        return $window['rows'][0]['slots']['kind'];
    }

    /**
     * @param string $acceptKey Connection holding the canonical window
     * @param string $kind URL-selected kind
     */
    private function assertCanonicalViewport(string $acceptKey, string $kind): void
    {
        $viewport = Hilos::$sr->getTableViewport($acceptKey, HilosModerationKindsTable::TABLE);
        self::assertNotNull($viewport);
        self::assertSame(['kind' => $kind], $viewport->filter);
        self::assertSame(1, $viewport->limit);
        self::assertNull($viewport->sort);
        self::assertNull($viewport->anchor);
        self::assertNull($viewport->pageIndex);
        self::assertSame(TableAnchorDirection::After, $viewport->anchorDirection);
        self::assertSame([
            'kind', 'title', 'owner', 'input', 'model', 'ownRuleCount', 'profile', 'reasons', 'defaultRules',
        ], $viewport->rendered);
    }

    /** @return list<array{target: string, name: string, data: array<string, mixed>}> Addressed browser frames */
    private function takeFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalSource->getSource() === SignalSource::DB) {
                continue;
            }
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $frames[] = [
                'target' => $signal->data->targetAcceptKey,
                'name' => $signal->signalName->getName(),
                'data' => $signal->data->data->toArray(),
            ];
        }

        return $frames;
    }

    /**
     * @param BrowserContext $browser Browser holding real action facts
     * @return list<array{target: string, name: string, data: array<string, mixed>}> Frames emitted by source fan-out
     */
    private function flushBrowser(BrowserContext $browser): array
    {
        self::assertSame([], $browser->flushToSignalRouter());

        return $this->takeFrames();
    }

    /**
     * @param list<array{target: string, name: string, data: array<string, mixed>}> $frames Source-driven updates
     * @param list<string> $targets Exactly the root and matching detail
     * @param string $kind Updated kind
     * @param int $count Current own-rule count
     */
    private function assertChangedKind(array $frames, array $targets, string $kind, int $count): void
    {
        $actual = [];
        foreach ($frames as $frame) {
            self::assertSame(SignalTypeConstants::TABLE_VIEWPORT_DELTA, $frame['name']);
            self::assertSame($kind, $frame['data']['rowKey']);
            self::assertSame($count, $frame['data']['row']['slots']['kind']['ownRuleCount']);
            $actual[] = $frame['target'];
        }
        sort($actual);
        sort($targets);
        self::assertSame($targets, $actual);
    }

    /** @return PageSignalRouter Ordinary page dispatcher over the fixture registry */
    private function createPageRouter(): PageSignalRouter
    {
        $agent = new ModerationKindPageTestAgent();
        $pageFactory = new HilosPageFactory($agent, ModerationKindPageIntegrationTestHilos::class);

        return new PageSignalRouter(
            $pageFactory,
            new ActionRouteConfig([]),
            new SignalRouteConfig([]),
        );
    }

    /** @param bool $down Whether to drop the existing stub tables */
    private static function runStubs(bool $down): void
    {
        // external-boundary: the up stub carries no suffix.
        $suffix = $down ? '_down' : '';
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            Database::sqlRun((string) file_get_contents(
                dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql"
            ));
        }
    }

    /** @param class-string<Hilos> $hilosClass Application facade to bind */
    private static function bindAppClass(string $hilosClass): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $hilosClass);
    }
}

/** Stand-in for concrete moderation root page in project. */
final class ModerationKindTestPage extends AbstractHilosModerationKindPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = 'moderation_test_agent';
}

/** Test agent for page signal router. */
final class ModerationKindPageTestAgent implements PageAgentInterface
{
    /** @return string Fixture agent identity */
    public function getId(): string
    {
        return 'test-agent:moderation-page';
    }

    /** @return SignalSourceInterface Fixture signal source */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'test');
    }
}

/** Browser context supporting admin, viewer and guest modes. */
final class ModerationKindPageTestBrowser extends BrowserContext
{
    /**
     * @param ?int $userId Acting person or guest
     * @param bool $admin Whether the fixture proves admin
     * @param bool $viewer Whether the fixture admits a look-only viewer
     */
    public function __construct(
        private readonly ?int $userId,
        private readonly bool $admin,
        private readonly bool $viewer = false,
    ) {
        parent::__construct();
    }

    /**
     * @param string $pageClass Page being delivered
     * @param string $acceptKey Receiving connection
     * @return bool Fixture viewer verdict
     */
    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }

    /**
     * @param int $userId Acting person
     * @return bool Fixture admin verdict
     */
    public function isAdmin(int $userId): bool
    {
        return $this->admin;
    }

    /**
     * @param string $acceptKey Receiving connection
     * @return ConnectionIdentity Settled fixture identity
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved($this->userId);
    }
}

/** Test directory with two framework and two project kinds. */
final class ModerationKindPageTestDirectory extends ModerationKindDirectory
{
    public const string NOTE = 'test_note';
    public const string PICTURE = 'test_picture';

    /** @return list<ModerationKind> Framework entries, two ordinary kinds and thirty further kinds */
    protected static function kinds(): array
    {
        $extra = [];
        for ($index = 0; $index < 30; $index++) {
            $extra[] = new ModerationKind(
                'extra_' . $index, 'Extra ' . $index, ModerationInput::TEXT, ['spam'], ['No spam.'], 'test_owner',
            );
        }

        return [
            ...parent::kinds(),
            new ModerationKind(self::NOTE, 'Project note', ModerationInput::TEXT_WITH_FILES, ['rude'], ['Polite notes.'], 'test_owner'),
            new ModerationKind(self::PICTURE, 'Project picture', ModerationInput::IMAGE, ['nudity'], ['Safe pictures.'], 'test_owner'),
            ...$extra,
        ];
    }
}

/** Settings catalog with visible model settings for tests. */
final class ModerationKindPageTestSettingsCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Fixture model settings */
    public static function getCatalog(): array
    {
        return [
            ModerationSettingsCatalog::TEXT_MODEL => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 'text-default',
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
            ],
            ModerationSettingsCatalog::IMAGE_MODEL => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_STRING,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 'vision-default',
                SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
            ],
        ];
    }
}

/** Settings catalog with text model hidden in admin view mode. */
final class ModerationKindPageTestTextHiddenCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Fixture model settings */
    public static function getCatalog(): array
    {
        return array_replace_recursive(ModerationKindPageTestSettingsCatalog::getCatalog(), [
            ModerationSettingsCatalog::TEXT_MODEL => [SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => false],
        ]);
    }
}

/** Test database context. */
final class ModerationKindPageIntegrationTestDbContext extends HilosDbContext
{
}

/** Test table context registering the kinds table. */
final class ModerationKindPageIntegrationTestTableContext extends TableContext
{
    /** Registers the existing framework kinds table. */
    public function configure(): void
    {
        $this->register(HilosModerationKindsTable::TABLE, new HilosModerationKindsTable());
    }
}

/** Test application facade. */
abstract class ModerationKindPageIntegrationTestHilos extends Hilos
{
    public const array PAGES = [
        ModerationKindTestPage::PAGE => ModerationKindTestPage::class,
        ModerationKindRootTestPage::PAGE => ModerationKindRootTestPage::class,
    ];

    public const array TABLES = [
        HilosModerationKindsTable::TABLE => HilosModerationKindsTable::class,
    ];

    public const array PAGE_TABLES = [
        ModerationKindRootTestPage::PAGE => [HilosModerationKindsTable::TABLE => []],
        ModerationKindTestPage::PAGE => [
            HilosModerationKindsTable::TABLE => [],
        ],
    ];

    protected const array FEATURES = [HilosFeature::MODERATION, HilosFeature::PROFILE_PHOTO];
    protected const string MODERATION_KIND_DIRECTORY = ModerationKindPageTestDirectory::class;
}

/** Root window beside the kind page, using the existing framework root. */
final class ModerationKindRootTestPage extends AbstractHilosModerationPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = 'moderation_test_agent';
}

/** Bridges the actual ORM bus to the mounted browser without starting worker IPC. */
final class ModerationKindPageTestBrowserSubscriber implements SourceChangeSubscriberInterface
{
    /**
     * @param SourceChange $change Actual ORM fact
     * @param SourceChangeProvenance $provenance Origin supplied by the bus
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->kind === SourceChange::KIND_DB) {
            Hilos::$browser?->record($change);
        }
    }
}

/** Uses the same project topology for served head tabs and live delivery. */
final class ModerationKindPageTestSignalRouter extends SignalRouter
{
    /** @return class-string<Hilos> The fixture application facade */
    protected function hilosClass(): string
    {
        return ModerationKindPageIntegrationTestHilos::class;
    }
}
