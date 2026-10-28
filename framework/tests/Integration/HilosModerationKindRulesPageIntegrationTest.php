<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Pages\Moderation\DTO\HilosModerationRuleAddActionDTO;
use Hilos\Pages\Moderation\DTO\HilosModerationRuleDeleteActionDTO;
use Hilos\Pages\Moderation\DTO\HilosModerationRuleUpdateActionDTO;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\ActionViewModeException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\DTO\UnknownActionPayloadDTO;
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
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
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
use Hilos\Pages\Moderation\AbstractHilosModerationKindRulesPage;
use Hilos\Pages\Moderation\AbstractHilosModerationPage;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Tables\Moderation\HilosModerationKindsTable;
use Hilos\Tables\Moderation\HilosModerationRulesTable;
use Hilos\Users\AccountStandingResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/**
 * Real Rules subscriptions, both canonical windows and source-driven delivery to open pages.
 *
 * Uses the commit-capable lifecycle because actual action announcements must reach the
 * browser source bus; an always-rollback root would hold those announcements until cleanup.
 */
final class HilosModerationKindRulesPageIntegrationTest extends FrameworkIntegrationTestCase
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
        self::bindAppClass(ModerationKindRulesPageIntegrationTestHilos::class);
        $db = new ModerationKindRulesPageIntegrationTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        Hilos::$sr = new ModerationKindRulesPageTestSignalRouter();
        Hilos::$setting = new SettingsAccessor(ModerationKindRulesPageTestSettingsCatalog::class);
        Hilos::$table = new ModerationKindRulesPageIntegrationTestTableContext();
        Hilos::$table->configure();

        foreach ([HilosDbContext::moderationRules, HilosDbContext::settings] as $collection) {
            TruthSourceRegistry::register($collection, TruthSourceKeys::all(), self::OWNER_AGENT_ID);
        }
        ExecutionContext::setCurrentAgentId(self::OWNER_AGENT_ID);
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        SourceChangeBus::subscribe(new ModerationKindRulesPageTestBrowserSubscriber());
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

    public function testFirstResponseContainsTheOwnerHeadMetadataAndEmptyOwnRules(): void
    {
        $this->bootBrowser();
        $payload = $this->subscribe('rules', ['kind' => 'test_note']);
        $data = $payload['payload']['data'];
        self::assertSame('Rules', $data['pageLabel']);
        self::assertSame('Your own rules for this kind.', $data['pageLead']);
        self::assertSame([
            'page' => HilosPageConstants::HILOS_MODERATION_KIND, 'label' => 'Kind',
            'lead' => 'One declared check and the rules in force.', 'icon' => 'bi-shield-check',
        ], $data['pageHeadOwner']);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_MODERATION, 'label' => 'Moderation'],
            ['page' => HilosPageConstants::HILOS_MODERATION_KIND, 'label' => 'Kind'],
        ], $data['pageBreadcrumb']);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_MODERATION_KIND, 'label' => 'Overview'],
            ['page' => HilosPageConstants::HILOS_MODERATION_KIND_RULES, 'label' => 'Rules'],
        ], $data['pageTabs']);
        self::assertSame([], $data['pageChildren']);
        self::assertSame([
            'kind' => 'test_note', 'title' => 'Project note', 'owner' => 'project', 'input' => 'text_with_files',
            'model' => 'text-default', 'ownRuleCount' => 0, 'profile' => 'hilos.moderation.text', 'reasons' => ['rude'],
            'defaultRules' => ['Polite notes.', 'No spam.'],
        ], $this->assertSelectedWindow($payload, 'test_note'));
        self::assertSame([], $payload['payload']['windows'][HilosModerationRulesTable::TABLE]['rows']);
        self::assertSame(0, $payload['payload']['windows'][HilosModerationRulesTable::TABLE]['totalCount']);
        $this->assertCanonicalViewport('rules', 'test_note');
        $this->assertColdRulesViewport('rules', 'test_note');
        self::assertSame(HilosPageConstants::HILOS_MODERATION_KIND_RULES, ModerationKindRulesTestPage::PAGE);
        self::assertSame(PageAccessLevel::ADMIN, ModerationKindRulesTestPage::ACCESS_LEVEL);
        self::assertSame(PageReach::ROUTE, ModerationKindRulesTestPage::REACH);
        self::assertSame([
            BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MODERATION_KIND_RULES,
        ], ModerationKindRulesTestPage::BROWSER);
        self::assertSame([
            HilosSignalConstants::HILOS_MODERATION_RULE_ADD => HilosModerationRuleAddActionDTO::class,
            HilosSignalConstants::HILOS_MODERATION_RULE_DELETE => HilosModerationRuleDeleteActionDTO::class,
            HilosSignalConstants::HILOS_MODERATION_RULE_UPDATE => HilosModerationRuleUpdateActionDTO::class,
        ], ModerationKindRulesTestPage::ACTIONS);
        self::assertSame([SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_MODERATION_RULE_WRITE_DONE => HandoverAnswerSignalData::class,
        ]], ModerationKindRulesTestPage::SIGNALS);
        self::assertSame([], ModerationKindRulesTestPage::READING_ACTIONS);
        self::assertNull(Hilos::$sr->pageResource('rules'));
    }

    /** @return list<array{string, int}> Empty, single and paged own-rule sets of framework and project kinds */
    public static function ownRuleSets(): array
    {
        return [
            ['hilos_profile_name', 0], ['hilos_profile_name', 1], ['hilos_profile_name', 31],
            ['test_note', 0], ['test_note', 1], ['test_note', 31], ['extra_29', 0],
        ];
    }

    /**
     * @param string $kind Published URL kind
     * @param int $count Complete own-rule count
     */
    #[DataProvider('ownRuleSets')]
    public function testColdWindowContainsOnlyOwnRulesAndRetainsTheCodeDefaults(string $kind, int $count): void
    {
        $this->bootBrowser();
        $ids = $this->createRules($kind, $count);
        Hilos::$db->moderationRules->actions->create('test_picture', 'Foreign rule.');
        $payload = $this->subscribe('direct', ['kind' => $kind]);
        $metadata = $this->assertSelectedWindow($payload, $kind);
        self::assertSame($count, $metadata['ownRuleCount']);
        self::assertSame($kind === 'hilos_profile_name' ? 'framework' : 'project', $metadata['owner']);
        $expectedDefaults = match ($kind) {
            'hilos_profile_name' => [
                'Default policy: allow benign display names.',
                'Block explicit insults, threats, hate speech, sexual content, spam, and impersonation.',
                'If uncertain, return allow=true.',
            ],
            'test_note' => ['Polite notes.', 'No spam.'],
            'extra_29' => [],
        };
        self::assertSame($expectedDefaults, $metadata['defaultRules']);
        $window = $payload['payload']['windows'][HilosModerationRulesTable::TABLE];
        self::assertSame($count, $window['totalCount']);
        self::assertTrue($window['totalExact']);
        self::assertSame(25, $window['limit']);
        self::assertSame([['field' => 'id', 'direction' => 'asc']], $window['sort']);
        self::assertSame(array_slice($ids, 0, 25), array_column($window['rows'], 'rowKey'));
        foreach ($window['rows'] as $index => $row) {
            self::assertSame([
                'rowKey' => $ids[$index],
                'slots' => ['rule' => ['id' => $ids[$index], 'kind' => $kind, 'rule' => sprintf('Rule %02d.', $index)]],
            ], $row);
        }
    }

    public function testDirectRepeatAndUpdateSelectKindsBeyondTheRootWindow(): void
    {
        $this->bootBrowser();
        $root = $this->subscribe('root', [], page: ModerationKindRulesRootTestPage::PAGE);
        $rootWindow = $root['payload']['windows'][HilosModerationKindsTable::TABLE];
        self::assertSame(34, $rootWindow['totalCount']);
        self::assertCount(25, $rootWindow['rows']);
        self::assertNotContains('extra_29', array_column($rootWindow['rows'], 'rowKey'));
        $router = $this->createPageRouter();
        $this->assertSelectedWindow($this->subscribe('direct', ['kind' => 'extra_29'], router: $router), 'extra_29');
        $this->assertSelectedWindow($this->subscribe('direct', ['kind' => 'test_note'], router: $router), 'test_note');
        $this->assertSelectedWindow($this->subscribe('direct', ['kind' => 'extra_29'], router: $router), 'extra_29');
        foreach (['test_picture', 'extra_29'] as $kind) {
            $router->dispatchPageUpdateSubscription(
                new WebSocketPageUpdateSubscriptionSignalDTO('direct', ModerationKindRulesTestPage::PAGE, ['kind' => $kind]),
                SignalSource::WORKER, ModerationKindRulesTestPage::PAGE,
            );
            $frames = $this->takeFrames();
            self::assertCount(1, $frames);
            self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $frames[0]['name']);
            $this->assertSelectedWindow($frames[0]['data'], $kind);
            $this->assertCanonicalViewport('direct', $kind);
            $this->assertColdRulesViewport('direct', $kind);
            self::assertSame(['kind' => $kind], Hilos::$sr->pageSubscription('direct')->params);
        }
        $router->dispatchPageUpdateSubscription(
            new WebSocketPageUpdateSubscriptionSignalDTO('direct', ModerationKindRulesTestPage::PAGE, ['kind' => 'unknown']),
            SignalSource::WORKER, ModerationKindRulesTestPage::PAGE,
        );
        $frames = $this->takeFrames();
        self::assertCount(1, $frames);
        self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $frames[0]['name']);
        self::assertSame(404, $frames[0]['data']['httpCode']);
        self::assertSame(['kind' => 'extra_29'], Hilos::$sr->pageSubscription('direct')->params);
    }

    public function testForeignReportsAndHeldWindowsCannotReplaceEitherUrlScopedWindow(): void
    {
        $this->bootBrowser();
        $ids = $this->createRules('test_note', 3);
        $foreign = new TableWindowDescriptorDTO(
            filter: ['kind' => 'test_picture', 'search' => 'no match'],
            sort: TableSortOrderDTO::fromWire([['field' => 'rule', 'direction' => 'desc']]),
            limit: 100, anchor: new TableAnchorDTO(['rule' => 'Foreign']),
            anchorDirection: TableAnchorDirection::Before, rendered: ['rule'],
        );
        Hilos::$sr->setTableViewport('held', new TableViewportSubscription(
            tableKey: HilosModerationRulesTable::TABLE, filter: $foreign->filter,
            limit: 100, pageIndex: 9, rendered: ['rule'],
        ));
        foreach (['held', 'reconnected'] as $acceptKey) {
            $payload = $this->subscribe($acceptKey, ['kind' => 'test_note'], reports: [
                HilosModerationKindsTable::TABLE => $foreign,
                HilosModerationRulesTable::TABLE => $foreign,
                'unrelated' => new TableWindowDescriptorDTO(limit: 3),
            ]);
            $this->assertSelectedWindow($payload, 'test_note');
            $this->assertCanonicalViewport($acceptKey, 'test_note');
            $this->assertColdRulesViewport($acceptKey, 'test_note');
            self::assertSame($ids, array_column($payload['payload']['windows'][HilosModerationRulesTable::TABLE]['rows'], 'rowKey'));
            self::assertSame([], Hilos::$sr->takeReportedTableWindows($acceptKey));
            self::assertNull(Hilos::$sr->getTableViewport($acceptKey, 'unrelated'));
        }
    }

    public function testSameKindReportsAndHeldWindowsRestoreSearchSortAnchorAndRenderedFields(): void
    {
        $this->bootBrowser();
        $ids = $this->createRules('test_note', 31);
        $reported = new TableWindowDescriptorDTO(
            filter: ['kind' => 'test_note', 'search' => 'Rule'],
            sort: TableSortOrderDTO::fromWire([['field' => 'rule', 'direction' => 'desc']]),
            limit: 3, anchor: new TableAnchorDTO(['rule' => 'Rule 15.', 'id' => $ids[15]]),
            anchorDirection: TableAnchorDirection::Before, rendered: ['rule'],
        );
        $first = $this->subscribe('first', ['kind' => 'test_note'], reports: [
            HilosModerationKindsTable::TABLE => new TableWindowDescriptorDTO(filter: ['search' => 'nothing'], limit: 99, pageIndex: 5),
            HilosModerationRulesTable::TABLE => $reported,
        ]);
        $window = $first['payload']['windows'][HilosModerationRulesTable::TABLE];
        self::assertSame(31, $window['totalCount']);
        self::assertSame(3, $window['limit']);
        self::assertSame(['Rule 18.', 'Rule 17.', 'Rule 16.'], array_column(array_column(array_column($window['rows'], 'slots'), 'rule'), 'rule'));
        $this->assertCanonicalViewport('first', 'test_note');
        $this->assertRestoredRulesViewport('first', $reported);
        $this->assertSelectedWindow($first, 'test_note');
        $repeat = $this->subscribe('first', ['kind' => 'test_note']);
        self::assertSame($window, $repeat['payload']['windows'][HilosModerationRulesTable::TABLE]);
        $this->assertRestoredRulesViewport('first', $reported);
        $this->createPageRouter()->dispatchPageUpdateSubscription(
            new WebSocketPageUpdateSubscriptionSignalDTO('first', ModerationKindRulesTestPage::PAGE, ['kind' => 'test_note']),
            SignalSource::WORKER, ModerationKindRulesTestPage::PAGE,
        );
        $frames = $this->takeFrames();
        self::assertCount(1, $frames);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $frames[0]['name']);
        self::assertSame($window, $frames[0]['data']['payload']['windows'][HilosModerationRulesTable::TABLE]);
        $this->assertRestoredRulesViewport('first', $reported);
        $reconnected = $this->subscribe('new-key', ['kind' => 'test_note'], reports: [HilosModerationRulesTable::TABLE => $reported]);
        self::assertSame($window, $reconnected['payload']['windows'][HilosModerationRulesTable::TABLE]);
        $this->assertRestoredRulesViewport('new-key', $reported);
    }

    public function testNumberedAndEmptySearchWindowsKeepTheWholeKindCountAndExactDescriptor(): void
    {
        $this->bootBrowser();
        $this->createRules('test_note', 31);
        $reported = new TableWindowDescriptorDTO(
            filter: ['kind' => 'test_note', 'search' => 'Rule'],
            sort: TableSortOrderDTO::fromWire([['field' => 'rule', 'direction' => 'desc']]),
            limit: 4, pageIndex: 2, rendered: [],
        );
        $payload = $this->subscribe('numbered', ['kind' => 'test_note'], reports: [HilosModerationRulesTable::TABLE => $reported]);
        $window = $payload['payload']['windows'][HilosModerationRulesTable::TABLE];
        self::assertSame(['Rule 22.', 'Rule 21.', 'Rule 20.', 'Rule 19.'], array_column(
            array_column(array_column($window['rows'], 'slots'), 'rule'), 'rule',
        ));
        $this->assertRestoredRulesViewport('numbered', $reported);
        $emptySearch = new TableWindowDescriptorDTO(filter: ['kind' => 'test_note', 'search' => 'absent'], limit: 7, rendered: []);
        $payload = $this->subscribe('search', ['kind' => 'test_note'], reports: [HilosModerationRulesTable::TABLE => $emptySearch]);
        self::assertSame(31, $this->assertSelectedWindow($payload, 'test_note')['ownRuleCount']);
        self::assertSame(['Polite notes.', 'No spam.'], $this->assertSelectedWindow($payload, 'test_note')['defaultRules']);
        self::assertSame(0, $payload['payload']['windows'][HilosModerationRulesTable::TABLE]['totalCount']);
        self::assertSame([], $payload['payload']['windows'][HilosModerationRulesTable::TABLE]['rows']);
        $this->assertRestoredRulesViewport('search', $emptySearch);
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
    public function testAdmissionRefusesWithoutAnEmptySuccessfulRulesPage(
        ?int $userId, bool $admin, array $params, int $status, string $error,
    ): void {
        $this->bootBrowser($userId, $admin);
        $this->createPageRouter()->dispatchPageSubscribe(
            new WebSocketPageSubscribeSignalDTO('refused', ModerationKindRulesTestPage::PAGE, $params),
            SignalSource::WORKER, ModerationKindRulesTestPage::PAGE,
        );
        $frames = $this->takeFrames();
        self::assertCount(1, $frames);
        self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $frames[0]['name']);
        self::assertSame($status, $frames[0]['data']['httpCode']);
        self::assertSame($error, $frames[0]['data']['errorCode']);
        self::assertArrayNotHasKey('payload', $frames[0]['data']);
        self::assertNull(Hilos::$sr->getTableViewport('refused', HilosModerationKindsTable::TABLE));
        self::assertNull(Hilos::$sr->getTableViewport('refused', HilosModerationRulesTable::TABLE));
    }

    public function testViewerReceivesBothPermittedWindowsWithTheHiddenModelStillHidden(): void
    {
        Hilos::$setting = new SettingsAccessor(ModerationKindRulesPageTestTextHiddenCatalog::class);
        $this->bootBrowser(userId: 5, admin: false, viewer: true);
        $id = Hilos::$db->moderationRules->actions->create('test_note', 'An own rule.')->id;
        $payload = $this->subscribe('viewer', ['kind' => 'test_note']);
        self::assertStringNotContainsString('text-default', json_encode($payload, JSON_THROW_ON_ERROR));
        $metadata = $this->assertSelectedWindow($payload, 'test_note');
        self::assertSame(HiddenValue::mark(), $metadata['model']);
        self::assertSame(1, $metadata['ownRuleCount']);
        self::assertSame(['Polite notes.', 'No spam.'], $metadata['defaultRules']);
        self::assertSame('hilos.moderation.text', $metadata['profile']);
        self::assertSame(['rude'], $metadata['reasons']);
        self::assertSame([[
            'rowKey' => $id, 'slots' => ['rule' => ['id' => $id, 'kind' => 'test_note', 'rule' => 'An own rule.']],
        ]], $payload['payload']['windows'][HilosModerationRulesTable::TABLE]['rows']);
        self::assertCount(2, $payload['payload']['data']['pageTabs']);
    }

    public function testViewerActionGateRefusesWritesOnTheRulesPage(): void
    {
        $this->bootBrowser(userId: 5, admin: false, viewer: true);
        $this->subscribe('viewer', ['kind' => 'test_note']);
        $this->expectException(ActionViewModeException::class);
        PageAccessGate::assertAction(ModerationKindRulesTestPage::class, 'viewer', 'unsupported');
    }

    public function testUnsupportedActionsCannotWriteRules(): void
    {
        $this->bootBrowser();
        $this->subscribe('rules', ['kind' => 'test_note']);
        self::assertSame([
            HilosSignalConstants::HILOS_MODERATION_RULE_ADD => HilosModerationRuleAddActionDTO::class,
            HilosSignalConstants::HILOS_MODERATION_RULE_DELETE => HilosModerationRuleDeleteActionDTO::class,
            HilosSignalConstants::HILOS_MODERATION_RULE_UPDATE => HilosModerationRuleUpdateActionDTO::class,
        ], ModerationKindRulesPageIntegrationTestHilos::getActionDtoRoutes());
        $page = new ModerationKindRulesTestPage(new ModerationKindRulesPageTestAgent());
        $this->expectException(AgentUnknownActionException::class);
        $page->onAction('rules', 'unsupported', new UnknownActionPayloadDTO('unsupported', []));
    }

    public function testRealCreateUpdateAndDeleteFactsReachBothRulesPagesAndTheirOverviewOnly(): void
    {
        $browser = $this->bootBrowser();
        $this->subscribe('one', ['kind' => 'test_note']);
        $this->subscribe('two', ['kind' => 'test_note']);
        $this->subscribe('overview', ['kind' => 'test_note'], page: ModerationKindRulesOverviewTestPage::PAGE);
        $this->subscribe('foreign', ['kind' => 'test_picture']);
        $id = Hilos::$db->moderationRules->actions->create('test_note', 'Created.')->id;
        $frames = $this->flushBrowser($browser);
        $this->assertLiveChanges($frames, ['one', 'two', 'overview'], ['one', 'two'], 'test_note', 1, $id, 'Created.');
        Hilos::$db->moderationRules[$id]->actions->update('Updated.');
        $frames = $this->flushBrowser($browser);
        $this->assertLiveChanges($frames, [], ['one', 'two'], 'test_note', 1, $id, 'Updated.');
        Hilos::$db->moderationRules[$id]->actions->delete();
        $frames = $this->flushBrowser($browser);
        $this->assertLiveChanges($frames, ['one', 'two', 'overview'], ['one', 'two'], 'test_note', 0, $id, null, ['foreign']);
        $foreignId = Hilos::$db->moderationRules->actions->create('test_picture', 'Foreign.')->id;
        $this->assertLiveChanges($this->flushBrowser($browser), ['foreign'], ['foreign'], 'test_picture', 1, $foreignId, 'Foreign.');
    }

    /**
     * @param ?int $userId Acting person or guest
     * @param bool $admin Whether the person proves admin
     * @param bool $viewer Whether admin view mode admits the person
     * @return ModerationKindRulesPageTestBrowser Mounted browser context
     */
    private function bootBrowser(?int $userId = 7, bool $admin = true, bool $viewer = false): ModerationKindRulesPageTestBrowser
    {
        $browser = new ModerationKindRulesPageTestBrowser($userId, $admin, $viewer);
        ModerationKindRulesPageIntegrationTestHilos::initBrowser($browser);

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
        string $page = ModerationKindRulesTestPage::PAGE,
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
        self::assertSame(ModerationKindRulesTestPage::PAGE, $payload['page']);
        self::assertSame([HilosModerationKindsTable::TABLE, HilosModerationRulesTable::TABLE], array_keys($payload['payload']['windows']));
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
     * @param string $kind Published kind to populate
     * @param int $count Number of ordinary action writes
     * @return list<int> Rule primary keys in creation order
     */
    private function createRules(string $kind, int $count): array
    {
        $ids = [];
        for ($index = 0; $index < $count; $index++) {
            $ids[] = Hilos::$db->moderationRules->actions->create($kind, sprintf('Rule %02d.', $index))->id;
        }

        return $ids;
    }

    /**
     * @param string $acceptKey Connection holding a cold rules window
     * @param string $kind URL kind
     */
    private function assertColdRulesViewport(string $acceptKey, string $kind): void
    {
        $viewport = Hilos::$sr->getTableViewport($acceptKey, HilosModerationRulesTable::TABLE);
        self::assertNotNull($viewport);
        self::assertSame(['kind' => $kind], $viewport->filter);
        self::assertSame(25, $viewport->limit);
        self::assertSame([['field' => 'id', 'direction' => 'asc']], $viewport->sort->toArray());
        self::assertNull($viewport->anchor);
        self::assertNull($viewport->pageIndex);
        self::assertSame(TableAnchorDirection::After, $viewport->anchorDirection);
        self::assertSame(['id', 'kind', 'rule'], $viewport->rendered);
    }

    /**
     * @param string $acceptKey Connection holding the restored window
     * @param TableWindowDescriptorDTO $reported All coordinates that must survive
     */
    private function assertRestoredRulesViewport(string $acceptKey, TableWindowDescriptorDTO $reported): void
    {
        $viewport = Hilos::$sr->getTableViewport($acceptKey, HilosModerationRulesTable::TABLE);
        self::assertNotNull($viewport);
        self::assertSame($reported->filter, $viewport->filter);
        self::assertSame($reported->limit, $viewport->limit);
        self::assertSame($reported->sort?->toArray(), $viewport->sort?->toArray());
        self::assertSame($reported->anchor?->toArray(), $viewport->anchor?->toArray());
        self::assertSame($reported->anchorDirection, $viewport->anchorDirection);
        self::assertSame($reported->pageIndex, $viewport->pageIndex);
        self::assertSame($reported->rendered, $viewport->rendered);
    }

    /**
     * @param list<array{target: string, name: string, data: array<string, mixed>}> $frames Whole source flush
     * @param list<string> $metadataTargets Connections owed a kind-count delta
     * @param list<string> $rulesTargets Connections owed rule content
     * @param string $kind Changed kind
     * @param int $count Complete own-rule count
     * @param int $id Changed rule primary key
     * @param ?string $text Current text, or null for removal
     * @param list<string> $unannounceTargets Foreign windows owed only the standard pending-key cleanup
     */
    private function assertLiveChanges(
        array $frames, array $metadataTargets, array $rulesTargets, string $kind, int $count, int $id, ?string $text,
        array $unannounceTargets = [],
    ): void {
        $ruleSignal = $text !== null && $metadataTargets !== []
            ? SignalTypeConstants::TABLE_VIEWPORT_APPEND : SignalTypeConstants::TABLE_VIEWPORT_DELTA;
        $actual = [];
        foreach ($frames as $frame) {
            $delta = $frame['data'];
            $actual[] = [$frame['target'], $delta['tableKey'], $frame['name']];
            if ($delta['tableKey'] === HilosModerationKindsTable::TABLE) {
                self::assertSame(SignalTypeConstants::TABLE_VIEWPORT_DELTA, $frame['name']);
                self::assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta['kind']);
                self::assertSame($kind, $delta['rowKey']);
                self::assertSame($count, $delta['row']['slots']['kind']['ownRuleCount']);
                self::assertSame($kind === 'test_note' ? ['Polite notes.', 'No spam.'] : ['Safe pictures.'],
                    $delta['row']['slots']['kind']['defaultRules']);
            } else {
                self::assertSame(HilosModerationRulesTable::TABLE, $delta['tableKey']);
                if ($frame['name'] === SignalTypeConstants::TABLE_VIEWPORT_UNANNOUNCE) {
                    self::assertSame((string) $id, $delta['rowKey']);
                    self::assertSame(['page', 'tableKey', 'rowKey'], array_keys($delta));
                } elseif ($frame['name'] === SignalTypeConstants::TABLE_VIEWPORT_COUNT) {
                    self::assertSame($count, $delta['totalCount']);
                    self::assertTrue($delta['totalExact']);
                    self::assertSame(1, $delta['pageCount']);
                } elseif ($text === null) {
                    self::assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $delta['kind']);
                    self::assertSame($id, $delta['rowKey']);
                    self::assertSame(TableViewportDeltaDTO::REASON_DELETED, $delta['reason']);
                    self::assertArrayNotHasKey('row', $delta);
                } else {
                    if ($ruleSignal === SignalTypeConstants::TABLE_VIEWPORT_DELTA) {
                        self::assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta['kind']);
                        self::assertSame($id, $delta['rowKey']);
                    } else {
                        self::assertSame($count, $delta['totalCount']);
                        self::assertTrue($delta['totalExact']);
                    }
                    self::assertSame([
                        'rowKey' => $id, 'slots' => ['rule' => ['id' => $id, 'kind' => $kind, 'rule' => $text]],
                    ], $delta['row']);
                }
            }
        }
        $expected = [
            ...array_map(static fn (string $target): array => [
                $target, HilosModerationKindsTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_DELTA,
            ], $metadataTargets),
            ...array_map(static fn (string $target): array => [$target, HilosModerationRulesTable::TABLE, $ruleSignal], $rulesTargets),
            ...array_map(static fn (string $target): array => [
                $target, HilosModerationRulesTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_UNANNOUNCE,
            ], $unannounceTargets),
        ];
        if ($text === null) {
            foreach ($rulesTargets as $target) {
                $expected[] = [$target, HilosModerationRulesTable::TABLE, SignalTypeConstants::TABLE_VIEWPORT_COUNT];
            }
        }
        sort($actual);
        sort($expected);
        self::assertSame($expected, $actual);
    }

    /** @return PageSignalRouter Ordinary page dispatcher over the fixture registry */
    private function createPageRouter(): PageSignalRouter
    {
        $agent = new ModerationKindRulesPageTestAgent();
        $pageFactory = new HilosPageFactory($agent, ModerationKindRulesPageIntegrationTestHilos::class);

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

/** Rules binding for the real framework page under test. */
final class ModerationKindRulesTestPage extends AbstractHilosModerationKindRulesPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = 'moderation_test_agent';
}

/** Test agent for page signal router. */
final class ModerationKindRulesPageTestAgent implements PageAgentInterface
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
final class ModerationKindRulesPageTestBrowser extends BrowserContext
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
final class ModerationKindRulesPageTestDirectory extends ModerationKindDirectory
{
    public const string NOTE = 'test_note';
    public const string PICTURE = 'test_picture';

    /** @return list<ModerationKind> Framework entries, two ordinary kinds and thirty further kinds */
    protected static function kinds(): array
    {
        $extra = [];
        for ($index = 0; $index < 30; $index++) {
            $extra[] = new ModerationKind(
                'extra_' . $index, 'Extra ' . $index, ModerationInput::TEXT, ['spam'], [], 'test_owner',
            );
        }

        return [
            ...parent::kinds(),
            new ModerationKind(self::NOTE, 'Project note', ModerationInput::TEXT_WITH_FILES, ['rude'], ['Polite notes.', 'No spam.'], 'test_owner'),
            new ModerationKind(self::PICTURE, 'Project picture', ModerationInput::IMAGE, ['nudity'], ['Safe pictures.'], 'test_owner'),
            ...$extra,
        ];
    }
}

/** Settings catalog with visible model settings for tests. */
final class ModerationKindRulesPageTestSettingsCatalog implements CatalogProviderInterface
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
final class ModerationKindRulesPageTestTextHiddenCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Fixture model settings */
    public static function getCatalog(): array
    {
        return array_replace_recursive(ModerationKindRulesPageTestSettingsCatalog::getCatalog(), [
            ModerationSettingsCatalog::TEXT_MODEL => [SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => false],
        ]);
    }
}

/** Test database context. */
final class ModerationKindRulesPageIntegrationTestDbContext extends HilosDbContext
{
}

/** Test context registering both existing framework moderation tables. */
final class ModerationKindRulesPageIntegrationTestTableContext extends TableContext
{
    /** Registers metadata and rules without extending either table. */
    public function configure(): void
    {
        $this->register(HilosModerationKindsTable::TABLE, new HilosModerationKindsTable());
        $this->register(HilosModerationRulesTable::TABLE, new HilosModerationRulesTable());
    }
}

/** Test application facade. */
abstract class ModerationKindRulesPageIntegrationTestHilos extends Hilos
{
    public const array PAGES = [
        ModerationKindRulesTestPage::PAGE => ModerationKindRulesTestPage::class,
        ModerationKindRulesOverviewTestPage::PAGE => ModerationKindRulesOverviewTestPage::class,
        ModerationKindRulesRootTestPage::PAGE => ModerationKindRulesRootTestPage::class,
    ];

    public const array TABLES = [
        HilosModerationKindsTable::TABLE => HilosModerationKindsTable::class,
        HilosModerationRulesTable::TABLE => HilosModerationRulesTable::class,
    ];

    public const array PAGE_TABLES = [
        ModerationKindRulesRootTestPage::PAGE => [HilosModerationKindsTable::TABLE => []],
        ModerationKindRulesTestPage::PAGE => [
            HilosModerationKindsTable::TABLE => [],
            HilosModerationRulesTable::TABLE => [],
        ],
        ModerationKindRulesOverviewTestPage::PAGE => [HilosModerationKindsTable::TABLE => []],
    ];

    protected const array FEATURES = [HilosFeature::MODERATION, HilosFeature::PROFILE_PHOTO];
    protected const string MODERATION_KIND_DIRECTORY = ModerationKindRulesPageTestDirectory::class;
}

/** Root window beside the kind page, using the existing framework root. */
final class ModerationKindRulesRootTestPage extends AbstractHilosModerationPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = 'moderation_test_agent';
}

/** Bridges the actual ORM bus to the mounted browser without starting worker IPC. */
final class ModerationKindRulesPageTestBrowserSubscriber implements SourceChangeSubscriberInterface
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
final class ModerationKindRulesPageTestSignalRouter extends SignalRouter
{
    /** @return class-string<Hilos> The fixture application facade */
    protected function hilosClass(): string
    {
        return ModerationKindRulesPageIntegrationTestHilos::class;
    }
}

/** Overview sibling served beside Rules in this fixture only. */
final class ModerationKindRulesOverviewTestPage extends AbstractHilosModerationKindPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = 'moderation_test_agent';
}
