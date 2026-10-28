<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\Definition\StatusFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;
use Hilos\Notification\HilosNotifier;
use Hilos\Pages\Status\AbstractHilosStatusPage;
use Hilos\Pages\Status\AbstractHilosStatusSubscribersPage;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketTableViewportSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Status\DTO\StatusRemoveSubscriberSignalData;
use Hilos\Status\DTO\StatusOverviewData;
use Hilos\Status\DTO\StatusSubscriberCountsData;
use Hilos\Status\StatusAgent;
use Hilos\Tables\Status\HilosStatusSubscribersTable;
use Hilos\Tests\Fixtures\StatusPublicVolume;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountStandingResolver;
use ReflectionProperty;

/** Production ADMIN page forwarding, real owner commits, independent SQL echo and initial/live viewer wire. */
final class StatusSubscriberAdminIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = [
        'hilos_user', 'hilos_user_merge', 'hilos_account_deletion', 'hilos_status_incident',
        'hilos_status_incident_update', 'hilos_status_subscriber',
    ];

    private ?HilosDbContext $previousDb = null;
    private ?RtContext $previousRt = null;
    private ?SignalRouter $previousRouter = null;
    private ?HilosNotifier $previousNotify = null;
    private ?SettingsAccessor $previousSetting = null;
    private ?BrowserContext $previousBrowser = null;
    private string $previousApp;
    /** @var list<SourceMirrorSubscriberInterface> Original source mirrors */
    private array $previousMirrors = [];
    /** @var array Original reactions restored without disturbing their process-wide interests */
    private array $previousReactions = [];
    private SourceChangeProvenance $previousProvenance;
    /** @var array<string, mixed> Account-standing memory restored with the original context */
    private array $previousStanding = [];
    private StatusAgent $owner;
    private ?TableContext $previousTable = null;
    private StatusPublicVolume $publicVolume;
    private StatusSubscriberAdminWorker $worker;
    private StatusSubscriberAdminRuntime $runtime;
    private PageSignalRouter $gatekeeper;
    private StatusSubscriberAdminCommitProbe $probe;

    /** Creates only Status storage and mounts its real owner with local claims. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Hilos::appClass();
        $this->previousDb = Hilos::$db;
        $this->previousRt = Hilos::$rt;
        $this->previousRouter = Hilos::$sr;
        $this->previousNotify = Hilos::$notify;
        $this->previousSetting = Hilos::$setting;
        $this->previousBrowser = Hilos::$browser;
        $this->previousTable = Hilos::$table;
        $this->previousMirrors = new ReflectionProperty(SourceChangeBus::class, 'mirrors')->getValue();
        $this->previousReactions = new ReflectionProperty(SourceChangeBus::class, 'reactions')->getValue();
        $this->previousProvenance = new ReflectionProperty(SourceChangeBus::class, 'provenance')->getValue();
        foreach (['standings', 'lapsedUserIds', 'epoch'] as $property) {
            $this->previousStanding[$property] = new ReflectionProperty(AccountStandingResolver::class, $property)->getValue();
        }
        new ReflectionProperty(AccountStandingResolver::class, 'standings')->setValue(null, []);
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        Hilos::$notify = null;
        Hilos::$setting = null;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, StatusSubscriberAdminHilos::class);
        foreach (array_reverse(self::TABLES) as $table) {
            $this->stub($table, true);
        }
        foreach (self::TABLES as $table) {
            $this->stub($table, false);
        }
        Database::sqlRun("INSERT INTO hilos_user (id, name, admin) VALUES (7, 'Admin', 1), (5, 'Viewer', 0)");
        Schema::reset();
        Schema::initialize();
        Hilos::$db = new StatusSubscriberAdminDbContext();
        Hilos::$db->configure();
        $this->runtime = new StatusSubscriberAdminRuntime();
        $this->runtime->mountFeatureRuntime([new StatusFeature()]);
        $this->runtime->bindStateCollectionNames();
        Hilos::$rt = $this->runtime;
        StatusSubscriberAdminHilos::initBrowser(new IdentityTestBrowser(7, true));
        Hilos::$table = new StatusSubscriberAdminTables();
        Hilos::$table->configure();
        $this->publicVolume = new StatusPublicVolume();
        $this->owner = new StatusAgent();
        OwnershipDeclaration::claimAll($this->owner);
        $this->worker = new StatusSubscriberAdminWorker($this->owner);
        Hilos::$browser->bindPageResender($this->worker);
        $this->gatekeeper = StatusSubscriberAdminWorker::pageRouter(new StatusSubscriberAdminGatekeeperAgent());
        $this->asOwner(fn () => $this->owner->onStart());
        $this->drain();
        $this->probe = new StatusSubscriberAdminCommitProbe();
        SourceChangeBus::subscribe($this->probe);
        SourceChangeBus::subscribe(new StatusSubscriberAdminBrowserChanges());
    }

    /** Releases every local claim, listener and facade even after a failed case. */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            foreach (array_reverse(self::TABLES) as $table) {
                $this->stub($table, true);
            }
        } finally {
            TruthSourceRegistry::unregisterAgent($this->owner->getId());
            RtTruthSourceRegistry::unregisterAgent($this->owner->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->owner->getId()));
            SourceInterestRegistry::releaseConsumer(SourceConsumer::page('ak-status'));
            SourceInterestRegistry::releaseConsumer(SourceConsumer::page('ak-overview'));
            RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
            new ReflectionProperty(SourceChangeBus::class, 'mirrors')->setValue(null, $this->previousMirrors);
            new ReflectionProperty(SourceChangeBus::class, 'reactions')->setValue(null, $this->previousReactions);
            new ReflectionProperty(SourceChangeBus::class, 'provenance')->setValue(null, $this->previousProvenance);
            foreach ($this->previousStanding as $property => $value) {
                new ReflectionProperty(AccountStandingResolver::class, $property)->setValue(null, $value);
            }
            new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
            Hilos::$db = $this->previousDb;
            Hilos::$rt = $this->previousRt;
            Hilos::$sr = $this->previousRouter;
            Hilos::$notify = $this->previousNotify;
            Hilos::$setting = $this->previousSetting;
            Hilos::$browser = $this->previousBrowser;
            Hilos::$table = $this->previousTable;
            $this->publicVolume->close();
            SourceInterestRegistry::readsWhatItMounts();
            parent::tearDown();
        }
    }

    public function testDbWindowsSearchFiltersOrdersFacetsAndMembershipShareTheBoundedSet(): void
    {
        $ids = [];
        foreach ([['z@example.net', 1000, false], ['a@example.net', 2000, true],
            ['b@example.net', 2000, true], ['c@example.net', 3000, false]] as [$email, $at, $confirmed]) {
            $ids[] = $this->subscriber($email, $at, $confirmed);
        }
        $table = new HilosStatusSubscribersTable();
        $orders = ['subscribedAt' => [$ids[0], $ids[1], $ids[2], $ids[3]],
            'email' => [$ids[1], $ids[2], $ids[3], $ids[0]],
            'confirmed' => [$ids[0], $ids[3], $ids[1], $ids[2]]];
        foreach ($orders as $field => $ascending) {
            foreach (['asc' => $ascending, 'desc' => array_reverse($ascending)] as $direction => $expected) {
                $sort = TableSortOrderDTO::of(new TableSortDTO($field, $direction));
                $first = $table->getPage(new TableQueryDTO(sort: $sort, limit: 2));
                self::assertSame(array_slice($expected, 0, 2), array_map(static fn ($row) => $row->id, $first->rows));
                $second = $table->getPage(new TableQueryDTO(sort: $sort, limit: 2, anchor: $first->lastAnchor));
                self::assertSame(array_slice($expected, 2), array_map(static fn ($row) => $row->id, $second->rows));
                $back = $table->getPage(new TableQueryDTO(sort: $sort, limit: 2, anchor: $second->firstAnchor,
                    anchorDirection: TableAnchorDirection::Before));
                self::assertSame(array_slice($expected, 0, 2), array_map(static fn ($row) => $row->id, $back->rows));
                self::assertSame(4, $first->totalCount);
                self::assertTrue($first->totalExact);
            }
        }
        $query = new TableQueryDTO(search: '@example.net', filter: ['confirmed' => true], limit: 2);
        self::assertSame([$ids[1], $ids[2]], array_map(static fn ($row) => $row->id, $table->getPage($query)->rows));
        self::assertTrue($table->containsRow($ids[1], $query));
        self::assertFalse($table->containsRow($ids[0], $query));
        self::assertFalse($table->containsRow($ids[1], new TableQueryDTO(search: 'z@')));
        self::assertSame([$ids[0]], array_map(static fn ($row) => $row->id,
            $table->getPage(new TableQueryDTO(search: 'Z@', limit: 2))->rows));
        $facets = $table->facetCounts($query, ['confirmed' => [true, false], 'email' => ['a@example.net']]);
        self::assertSame(['confirmed'], array_keys($facets));
        self::assertSame(4, $facets['confirmed']['any']->count);
        self::assertSame(2, $facets['confirmed']['options']['true']->count);
        self::assertSame(2, $facets['confirmed']['options']['false']->count);
        self::assertFalse(Hilos::$db->statusSubscribers->getObjectCollection()->isAllLoaded());
        self::assertSame(25, $table->getPage(new TableQueryDTO())->limit, 'Unbounded browser reads remain bounded');
    }

    public function testLargeSetStaysWindowedAndFocusedRowsUsePrimaryKeyReads(): void
    {
        $first = null;
        for ($index = 0; $index < 40; ++$index) {
            $id = $this->subscriber("subscriber{$index}@example.net", 1000 + $index);
            $first ??= $id;
        }
        $table = new HilosStatusSubscribersTable();
        $window = $table->getPage(new TableQueryDTO(sort: $table->defaultSort()));
        self::assertCount(25, $window->rows);
        self::assertSame(40, $window->totalCount);
        self::assertSame(1039, $window->rows[0]->subscribedAt);
        self::assertNotContains($first, array_map(static fn ($row) => $row->id, $window->rows));
        self::assertSame($first, $table->findRow($first)->getRowKey());
        self::assertFalse(Hilos::$db->statusSubscribers->getObjectCollection()->isAllLoaded());
        foreach (['window', 'contains', 'facet'] as $path) {
            try {
                $query = new TableQueryDTO(filter: ['confirmed' => 'true'], limit: 1);
                if ($path === 'window') {
                    $table->getPage($query);
                } elseif ($path === 'contains') {
                    $table->containsRow($first, $query);
                } else {
                    $table->facetCounts(new TableQueryDTO(limit: 1), ['confirmed' => ['true']]);
                }
                self::fail('Malformed boolean filter was accepted: ' . $path);
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCompleteFirstAnswerAndCommittedCountersPreserveTheApplyWindow(): void
    {
        $this->open();
        $initial = $this->pageFrame($this->drain());
        self::assertSame(['confirmedCount' => 0, 'pendingCount' => 0], $initial->payload->data['statusSubscribers']);
        self::assertSame([], $initial->payload->windows[HilosStatusSubscribersTable::TABLE]['rows']);
        $held = Hilos::$sr->getTableViewport('ak-status', HilosStatusSubscribersTable::TABLE);
        $id = $this->subscriber('one@example.net', 1000);
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $frames = $this->drain();
        self::assertSame(['confirmedCount' => 0, 'pendingCount' => 1],
            $this->pageFrame($frames)->payload->data['statusSubscribers']);
        self::assertSame($held, Hilos::$sr->getTableViewport('ak-status', HilosStatusSubscribersTable::TABLE),
            'Count publication must not replace the held window');
        self::assertFalse($held->hasRow((string) $id), 'Foreign arrival waits for Apply');
        self::assertSame([], $this->pageFrame($frames)->payload->windows, 'No replacement window in count updates');
        $token = $this->asOwner(fn () => Database::transaction(DatabaseNames::PRIMARY,
            fn () => Hilos::$db->statusSubscribers[$id]->actions->issueConfirmation(2000)));
        $this->asOwner(fn () => Database::transaction(DatabaseNames::PRIMARY,
            fn () => Hilos::$db->statusSubscribers[$id]->actions->confirm($token->token, 2001)));
        Hilos::$browser->flushToSignalRouter();
        self::assertSame(['confirmedCount' => 1, 'pendingCount' => 0],
            $this->pageFrame($this->drain())->payload->data['statusSubscribers']);
        $this->success($this->submit(HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE, ['subscriberId' => $id]));
        Hilos::$browser->flushToSignalRouter();
        self::assertSame(['confirmedCount' => 0, 'pendingCount' => 0],
            $this->pageFrame($this->drain())->payload->data['statusSubscribers']);
    }

    public function testBothPagesObserveCommittedSubscriberChangesAndRollbackTogether(): void
    {
        $this->open();
        $this->open(AbstractHilosStatusPage::PAGE, 'ak-overview');
        $this->assertBothCounts($this->drain(), 0, 0);
        foreach (['ak-status', 'ak-overview'] as $acceptKey) {
            self::assertContains(HilosDbContext::statusSubscribers,
                SourceInterestRegistry::collectionsOfConsumer(SourceConsumer::page($acceptKey), SourceChange::KIND_DB));
        }
        SourceInterestRegistry::readsWhatIsDelivered();
        $held = Hilos::$sr->getTableViewport('ak-status', HilosStatusSubscribersTable::TABLE);
        $id = $this->subscriber('both@example.net', 1000);
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertBothCounts($this->drain(), 0, 1);
        self::assertSame($held, Hilos::$sr->getTableViewport('ak-status', HilosStatusSubscribersTable::TABLE));
        self::assertFalse($held->hasRow((string) $id));
        $token = $this->asOwner(fn () => Database::transaction(DatabaseNames::PRIMARY,
            fn () => Hilos::$db->statusSubscribers[$id]->actions->issueConfirmation(2000)));
        $this->asOwner(fn () => Database::transaction(DatabaseNames::PRIMARY,
            fn () => Hilos::$db->statusSubscribers[$id]->actions->confirm($token->token, 2001)));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertBothCounts($this->drain(), 1, 0);
        $this->probe->watch = true;
        $this->probe->failAfterTimeline = true;
        $this->submit(HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE, ['subscriberId' => $id]);
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        self::assertSame([], $this->drain(), 'Rollback publishes neither overview nor subscriber counts');
        $this->success($this->submit(HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE, ['subscriberId' => $id]));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertBothCounts($this->drain(), 0, 0);
    }

    public function testBothPagesCarryNullWhileTheirDeclaredSubscriberSourceIsAwaitingReadiness(): void
    {
        $this->open();
        $this->open(AbstractHilosStatusPage::PAGE, 'ak-overview');
        $this->drain();
        $held = Hilos::$sr->getTableViewport('ak-status', HilosStatusSubscribersTable::TABLE);
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->owner->getId()));
        SourceInterestRegistry::releaseConsumer(SourceConsumer::page('ak-status'));
        SourceInterestRegistry::releaseConsumer(SourceConsumer::page('ak-overview'));
        $this->worker->pageInterests(AbstractHilosStatusSubscribersPage::PAGE, 'ak-status', false);
        $this->worker->pageInterests(AbstractHilosStatusPage::PAGE, 'ak-overview', false);
        SourceInterestRegistry::readsWhatIsDelivered();
        self::assertFalse(SourceInterestRegistry::isReady(SourceChange::KIND_DB, HilosDbContext::statusSubscribers));
        Hilos::$browser->record(SourceChange::rtUpdated(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::ID, []));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertBothCounts($this->drain(), null, null);
        SourceInterestRegistry::markReady(SourceChange::KIND_DB, HilosDbContext::statusSubscribers);
        Hilos::$browser->record(SourceChange::rtUpdated(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::ID, []));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertBothCounts($this->drain(), 0, 0);
        self::assertSame($held, Hilos::$sr->getTableViewport('ak-status', HilosStatusSubscribersTable::TABLE));
    }

    public function testBothCountProjectionsRecoverFromUnavailableWithoutReloadOrApplyingTheWindow(): void
    {
        $this->open();
        $this->open(AbstractHilosStatusPage::PAGE, 'ak-overview');
        $this->drain();
        $held = Hilos::$sr->getTableViewport('ak-status', HilosStatusSubscribersTable::TABLE);
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
        self::assertNull(StatusSubscriberCountsData::read());
        self::assertNull(StatusOverviewData::read()->subscribers);
        $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        Hilos::$rt = null;
        self::assertNull(StatusSubscriberCountsData::read());
        self::assertNull(StatusOverviewData::read()->subscribers);
        Hilos::$rt = $this->runtime;
        $database = Hilos::$db;
        Hilos::$db = null;
        self::assertNull(StatusSubscriberCountsData::read());
        self::assertNull(StatusOverviewData::read()->subscribers);
        Hilos::$db = $database;
        $this->stub('hilos_status_subscriber', true);
        Hilos::$browser->record(SourceChange::rtUpdated(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::ID, []));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertBothCounts($this->drain(), null, null);
        $this->stub('hilos_status_subscriber', false);
        Hilos::$browser->recordDatabaseBack();
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        $this->assertBothCounts($this->drain(), 0, 0);
        self::assertSame($held, Hilos::$sr->getTableViewport('ak-status', HilosStatusSubscribersTable::TABLE));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        self::assertSame([], $this->drain(), 'Recovery is a fact, not a timer');
    }

    public function testViewerWireAndCraftedQueryNeverRevealEmailOrItsMembership(): void
    {
        $id = $this->subscriber('private@example.net', 1000);
        $this->subscriber('other@example.net', 2000, true);
        $this->runtime->viewMode(true);
        StatusSubscriberAdminHilos::initBrowser(new IdentityTestBrowser(5, false));
        $this->open();
        $frame = $this->pageFrame($this->drain());
        $rows = $frame->payload->windows[HilosStatusSubscribersTable::TABLE]['rows'];
        self::assertSame(HiddenValue::mark(), $rows[0]['slots']['subscriber']['email']);
        self::assertSame(['id', 'email', 'subscribedAt', 'confirmed'], array_keys($rows[0]['slots']['subscriber']));
        self::assertStringNotContainsString('@example.net', $frame->toJson());
        self::assertSame(['confirmedCount' => 1, 'pendingCount' => 1], $frame->payload->data['statusSubscribers']);
        $this->gatekeeper->dispatchTableViewport(new WebSocketTableViewportSignalDTO(
            'ak-status', AbstractHilosStatusSubscribersPage::PAGE, HilosStatusSubscribersTable::TABLE,
            ['search' => 'private'], TableSortOrderDTO::of(new TableSortDTO('email')), 5,
        ), 'websocket', AbstractHilosStatusSubscribersPage::PAGE);
        $crafted = $this->drain();
        self::assertCount(1, $crafted);
        self::assertSame(SignalTypeConstants::TABLE_WINDOW, $crafted[0]->signalName->getName());
        self::assertSame(2, $crafted[0]->data->data->toArray()['totalCount']);
        self::assertStringNotContainsString('@example.net', $crafted[0]->data->data->toJson());
        $table = new HilosStatusSubscribersTable();
        $shown = ['id', 'subscribedAt', 'confirmed'];
        $query = new TableQueryDTO(search: 'private', sort: TableSortOrderDTO::of(new TableSortDTO('email')),
            shownFields: $shown, limit: 5);
        $snapshot = $table->getPage($query);
        self::assertSame(2, $snapshot->totalCount);
        self::assertStringNotContainsString('@example.net', json_encode($snapshot->firstAnchor->toArray()));
        self::assertTrue($table->containsRow($id, new TableQueryDTO(search: 'never-matches', shownFields: $shown)));
        $facets = $table->facetCounts($query, ['confirmed' => [true, false], 'email' => ['private@example.net']]);
        self::assertSame(['confirmed'], array_keys($facets));
        self::assertSame(2, $facets['confirmed']['any']->count);
        $this->gatekeeper->dispatchAction(new WebSocketActionSignalDTO('ak-status',
            HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE, ['subscriberId' => $id], 'viewer'), 'websocket');
        $denied = $this->drain();
        self::assertCount(1, $denied);
        self::assertSame('view_mode', $denied[0]->data->data->errorCode);
        self::assertCount(1, Database::sql('SELECT id FROM hilos_status_subscriber WHERE id = ?', [$id])->rows());
    }

    public function testOwnerRechecksRevokedAdminFreezeRuntimeAndSourceBeforeDeleting(): void
    {
        $id = $this->subscriber('one@example.net', 1000);
        $this->drain();
        foreach (['viewer', 'freeze', 'runtime', 'source', 'db'] as $reason) {
            StatusSubscriberAdminHilos::initBrowser(new IdentityTestBrowser(7, true));
            $this->gatekeeper->dispatchAction(new WebSocketActionSignalDTO('ak-status',
                HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE, ['subscriberId' => $id], $reason), 'websocket');
            $forwarded = $this->drain();
            self::assertCount(1, $forwarded);
            $database = Hilos::$db;
            if ($reason === 'viewer') {
                $this->runtime->viewMode(true);
                StatusSubscriberAdminHilos::initBrowser(new IdentityTestBrowser(5, false));
            } elseif ($reason === 'freeze') {
                $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
            } elseif ($reason === 'runtime') {
                Hilos::$rt = null;
            } elseif ($reason === 'source') {
                SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->owner->getId()));
                SourceInterestRegistry::register(SourceChange::KIND_DB, HilosDbContext::statusSubscribers, 'subscriber-readiness');
                SourceInterestRegistry::readsWhatIsDelivered();
                foreach ([SourceChange::KIND_DB, SourceChange::KIND_RT] as $kind) {
                    foreach (SourceInterestRegistry::collections($kind) as $key) {
                        if ($kind !== SourceChange::KIND_DB || $key !== HilosDbContext::statusSubscribers) {
                            SourceInterestRegistry::markReady($kind, $key);
                        }
                    }
                }
                SourceInterestRegistry::register(SourceChange::KIND_RT, ProtectedModeRuntime::RT_ITEM, 'subscriber-readiness');
                SourceInterestRegistry::markReady(SourceChange::KIND_RT, ProtectedModeRuntime::RT_ITEM);
            } else {
                Hilos::$db = null;
            }
            $this->drain();
            $this->deliver($forwarded[0]);
            $answer = $this->answer($this->drain());
            self::assertNotNull($answer->error, $reason);
            Hilos::$db = $database;
            Hilos::$rt = $this->runtime;
            SourceInterestRegistry::readsWhatItMounts();
            SourceInterestRegistry::releaseConsumer('subscriber-readiness');
            OwnershipDeclaration::claimAll($this->owner);
            $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
            $this->runtime->viewMode(false);
            $this->drain();
            self::assertCount(1, Database::sql('SELECT id FROM hilos_status_subscriber WHERE id = ?', [$id])->rows());
        }
        // A forged ask cannot use a reading action name to pass the viewer's gate.
        $this->runtime->viewMode(true);
        StatusSubscriberAdminHilos::initBrowser(new IdentityTestBrowser(5, false));
        $this->drain();
        $ask = new StatusRemoveSubscriberSignalData($id, HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_WRITE_DONE,
            'ak-status', 'forged', SignalTypeConstants::TABLE_VIEWPORT, null);
        $this->asOwner(fn () => $this->owner->onSignalAgent(new AgentSignalData($ask), 'forged',
            HilosSignalConstants::STATUS_REMOVE_SUBSCRIBER));
        self::assertNotNull($this->answer($this->drain())->error);
        self::assertCount(1, Database::sql('SELECT id FROM hilos_status_subscriber WHERE id = ?', [$id])->rows());
    }

    public function testRemovalCommitsBeforeAckRevokesOldLinksAndNeverTargetsARecreatedAddress(): void
    {
        $id = $this->subscriber('one@example.net', 1000);
        $oldConfirmation = $this->asOwner(fn () => Database::transaction(DatabaseNames::PRIMARY,
            fn () => Hilos::$db->statusSubscribers[$id]->actions->issueConfirmation(1500)));
        $secrets = Database::sql('SELECT confirmation_token_hash, unsubscribe_token FROM hilos_status_subscriber WHERE id = ?', [$id])->rows()[0];
        $this->probe->watch = true;
        $this->success($this->submit(HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE, ['subscriberId' => $id], 'remove-1'));
        self::assertCount(1, $this->probe->writes);
        self::assertTrue($this->probe->writes[0]['transaction']);
        self::assertNull($this->probe->writes[0]['queued']);
        self::assertSame('ak-status', $this->probe->writes[0]['origin']);
        self::assertSame('remove-1', $this->probe->writes[0]['requestId']);
        self::assertSame([false], $this->probe->afterCommitTransactions);
        self::assertNull(Hilos::$db->statusSubscribers->findByUnsubscribeToken($secrets['unsubscribe_token']));
        self::assertNull(Hilos::$db->statusSubscribers->findByConfirmationToken($oldConfirmation->token));
        $replacement = $this->subscriber('one@example.net', 2000);
        self::assertNotSame($id, $replacement);
        self::assertNotNull($this->submit(HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE, ['subscriberId' => $id])->error);
        self::assertCount(1, Database::sql('SELECT id FROM hilos_status_subscriber WHERE id = ?', [$replacement])->rows());
        $this->success($this->submit(HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE, ['subscriberId' => $replacement]));
    }

    public function testRollbackEmitsNoCounterChangeAndUnavailableIsDistinctFromKnownEmpty(): void
    {
        $id = $this->subscriber('one@example.net', 1000);
        $this->open();
        Hilos::$browser->flushToSignalRouter();
        $this->drain();
        $this->probe->watch = true;
        $this->probe->failAfterTimeline = true;
        self::assertNotNull($this->submit(HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE, ['subscriberId' => $id])->error);
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        self::assertSame([], $this->drain(), 'Rolled-back rows do not fan out');
        self::assertCount(1, Database::sql('SELECT id FROM hilos_status_subscriber WHERE id = ?', [$id])->rows());
        self::assertSame(['confirmedCount' => 0, 'pendingCount' => 1], StatusSubscriberCountsData::read()->toArray());
        $database = Hilos::$db;
        Hilos::$db = null;
        self::assertNull(StatusSubscriberCountsData::read());
        Hilos::$db = $database;
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
        self::assertNull(StatusSubscriberCountsData::read());
        $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        Hilos::$rt = null;
        self::assertNull(StatusSubscriberCountsData::read());
        Hilos::$rt = $this->runtime;
        $this->stub('hilos_status_subscriber', true);
        self::assertNull(StatusSubscriberCountsData::read(), 'Failed aggregate is unavailable rather than zero');
    }

    public function testCountsDistinguishDeclaredReadinessFromAMissingDeclaration(): void
    {
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->owner->getId()));
        SourceInterestRegistry::register(SourceChange::KIND_DB, HilosDbContext::statusSubscribers, 'counts-db');
        SourceInterestRegistry::register(SourceChange::KIND_RT, ProtectedModeRuntime::RT_ITEM, 'counts-runtime');
        SourceInterestRegistry::markReady(SourceChange::KIND_RT, ProtectedModeRuntime::RT_ITEM);
        SourceInterestRegistry::readsWhatIsDelivered();
        try {
            self::assertNull(StatusSubscriberCountsData::read(), 'Declared source awaiting readiness is unavailable');
            SourceInterestRegistry::releaseConsumer('counts-db');
            $this->expectException(DbCollectionNotReadableException::class);
            StatusSubscriberCountsData::read();
        } finally {
            SourceInterestRegistry::readsWhatItMounts();
            SourceInterestRegistry::releaseConsumer('counts-db');
            SourceInterestRegistry::releaseConsumer('counts-runtime');
            OwnershipDeclaration::claimAll($this->owner);
        }
    }

    public function testVisibleValuesUseDeltasAndOwnRemovalKeepsTheStandardOwnFlag(): void
    {
        $id = $this->subscriber('one@example.net', 1000);
        $this->open();
        Hilos::$browser->flushToSignalRouter();
        $this->drain();
        $confirmation = $this->asOwner(fn () => Database::transaction(DatabaseNames::PRIMARY,
            fn () => Hilos::$db->statusSubscribers[$id]->actions->issueConfirmation(2000)));
        $this->asOwner(fn () => Database::transaction(DatabaseNames::PRIMARY,
            fn () => Hilos::$db->statusSubscribers[$id]->actions->confirm($confirmation->token, 2001)));
        Hilos::$browser->flushToSignalRouter();
        $frames = $this->drain();
        $deltas = array_values(array_filter($frames,
            static fn (SignalDTO $frame): bool => $frame->signalName->getName() === SignalTypeConstants::TABLE_VIEWPORT_DELTA));
        self::assertCount(1, $deltas);
        self::assertSame('row_updated', $deltas[0]->data->data->kind);
        self::assertTrue($deltas[0]->data->data->row['slots']['subscriber']['confirmed']);
        self::assertFalse($deltas[0]->data->data->own);
        self::assertSame([], $this->pageFrame($frames)->payload->windows);
        $this->success($this->submit(HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE, ['subscriberId' => $id]));
        Hilos::$browser->flushToSignalRouter();
        $deltas = array_values(array_filter($this->drain(),
            static fn (SignalDTO $frame): bool => $frame->signalName->getName() === SignalTypeConstants::TABLE_VIEWPORT_DELTA));
        self::assertCount(1, $deltas);
        self::assertSame('row_removed', $deltas[0]->data->data->kind);
        self::assertTrue($deltas[0]->data->data->own);
    }

    /**
     * @param list<SignalDTO> $frames Actual first or committed live answers
     * @param ?int $confirmedCount Known confirmed count, or null when unavailable
     * @param ?int $pendingCount Known pending count, or null when unavailable
     */
    private function assertBothCounts(array $frames, ?int $confirmedCount, ?int $pendingCount): void
    {
        $pages = [];
        foreach ($frames as $frame) {
            if ($frame->signalName->getName() === SignalTypeConstants::PAGE_RESPONSE) {
                $pages[$frame->data->targetAcceptKey][] = $frame->data->data;
            }
        }
        self::assertCount(2, $pages);
        self::assertCount(1, $pages['ak-status']);
        self::assertCount(1, $pages['ak-overview']);
        $expected = $confirmedCount === null ? null : ['confirmedCount' => $confirmedCount, 'pendingCount' => $pendingCount];
        self::assertSame($expected, $pages['ak-status'][0]->payload->data['statusSubscribers']);
        self::assertSame($expected, $pages['ak-overview'][0]->payload->data['status']['subscribers']);
        self::assertSame([], $pages['ak-overview'][0]->payload->windows);
        self::assertStringNotContainsString('maxSubscriberId', $pages['ak-overview'][0]->toJson());
    }

    /**
     * @param string $page Registered production page to subscribe to
     * @param string $acceptKey Independent browser connection
     */
    private function open(string $page = AbstractHilosStatusSubscribersPage::PAGE, string $acceptKey = 'ak-status'): void
    {
        Hilos::$browser->bindPageResender($this->worker);
        $this->worker->pageInterests($page, $acceptKey);
        $subscribe = new WebSocketPageSubscribeSignalDTO($acceptKey, $page);
        Hilos::$sr->subscribeToPage($page, $subscribe);
        Hilos::$sr->bindPageAgent($acceptKey, $page, HilosAgentType::HILOS_INDEX, null);
        $this->gatekeeper->dispatchPageSubscribe($subscribe, 'websocket', SignalTypeConstants::PAGE_SUBSCRIBE);
    }

    /**
     * @param list<SignalDTO> $frames Queued final wire
     * @return PageResponseSignalData Exactly one count-bearing page answer
     */
    private function pageFrame(array $frames): PageResponseSignalData
    {
        $pages = array_values(array_filter($frames, static fn (SignalDTO $frame): bool =>
            $frame->signalName->getName() === SignalTypeConstants::PAGE_RESPONSE));
        self::assertCount(1, $pages);
        self::assertInstanceOf(PageResponseSignalData::class, $pages[0]->data->data);
        return $pages[0]->data->data;
    }

    /**
     * @param string $email Distinct subscription address
     * @param int $at UTC epoch milliseconds
     * @param bool $confirmed Whether to spend its first confirmation
     * @return int Persisted primary id
     */
    private function subscriber(string $email, int $at, bool $confirmed = false): int
    {
        return $this->asOwner(function () use ($email, $at, $confirmed): int {
            return Database::transaction(DatabaseNames::PRIMARY, function () use ($email, $at, $confirmed): int {
                $confirmation = Hilos::$db->statusSubscribers->actions->create($email, $at, 'Europe/Warsaw');
                if ($confirmed) {
                    Hilos::$db->statusSubscribers[$confirmation->subscriberId]->actions->confirm($confirmation->token, $at + 1);
                }
                return $confirmation->subscriberId;
            });
        });
    }

    /**
     * @param string $action Production page action name
     * @param array<string, mixed> $payload Raw client body
     * @param string $requestId Original correlation
     * @return HandoverAnswerSignalData Committed owner result before the gatekeeper ack
     */
    private function submit(string $action, array $payload, string $requestId = 'button-1'): HandoverAnswerSignalData
    {
        $this->drain();
        $this->gatekeeper->dispatchAction(new WebSocketActionSignalDTO('ak-status', $action, $payload, $requestId), 'websocket');
        $forwarded = $this->drain();
        self::assertCount(1, $forwarded, 'No ack at forwarding time');
        self::assertSame($requestId, $forwarded[0]->data->data->requestId);
        self::assertSame($payload['subscriberId'], $forwarded[0]->data->data->subscriberId);
        $this->deliver($forwarded[0]);
        $answer = $this->answer($this->drain());
        self::assertSame($requestId, $answer->requestId);
        self::assertSame($action, $answer->action);
        $this->deliverAnswer($answer);
        $acks = $this->drain();
        self::assertCount(1, $acks);
        self::assertSame($answer->error === null ? SignalConstants::ACTION_SUCCESS : SignalConstants::ACTION_ERROR,
            $acks[0]->signalName->getName());
        self::assertSame($requestId, $acks[0]->data->data->requestId);
        return $answer;
    }

    /** @param SignalDTO $frame Transport frame to route and deliver to its declared Status owner */
    private function deliver(SignalDTO $frame): void
    {
        $destinations = Hilos::$sr->getDestinations($frame);
        self::assertCount(1, $destinations);
        self::assertInstanceOf(AgentDestination::class, $destinations[0]);
        self::assertSame(HilosAgentType::HILOS_STATUS, $destinations[0]->agentType);
        self::assertNull($destinations[0]->agentIndex);
        $this->worker->deliver($frame);
        self::assertNull(ExecutionContext::currentAcceptKey());
        self::assertNull(ExecutionContext::currentRequestId());
    }

    /** @param HandoverAnswerSignalData $answer Real owner's outcome sent to the page-owned reply name */
    private function deliverAnswer(HandoverAnswerSignalData $answer): void
    {
        $this->gatekeeper->dispatchAgentSignal(AgentSignalData::fromArray(new AgentSignalData($answer)->toArray()),
            HilosAgentType::HILOS_STATUS, HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_WRITE_DONE);
    }

    /**
     * @param list<SignalDTO> $frames Committed sync announcements and exactly one handover answer
     * @return HandoverAnswerSignalData Parsed owner outcome
     */
    private function answer(array $frames): HandoverAnswerSignalData
    {
        $answers = array_values(array_filter($frames,
            static fn (SignalDTO $frame): bool => $frame->signalName->getName() === HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_WRITE_DONE));
        self::assertCount(1, $answers);
        self::assertSame(HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_WRITE_DONE, $frames[array_key_last($frames)]->signalName->getName(),
            'Committed sync announcements must precede the owner answer');
        self::assertInstanceOf(AgentSignalData::class, $answers[0]->data);
        $answer = $answers[0]->data->data;
        self::assertInstanceOf(HandoverAnswerSignalData::class, $answer);
        self::assertSame('ak-status', $answer->acceptKey);
        self::assertFalse(Database::hasOpenTransactions());
        if ($answer->error !== null) {
            self::assertCount(1, $frames, 'A failed transaction must release no DB/RT announcements');
        }
        self::assertNotContains(SignalConstants::ACTION_SUCCESS,
            array_map(static fn (SignalDTO $frame): string => $frame->signalName->getName(), $frames));
        return $answer;
    }

    /** @param HandoverAnswerSignalData $answer Expected success */
    private function success(HandoverAnswerSignalData $answer): void
    {
        self::assertNull($answer->error, $answer->errorDetail ?? $answer->error ?? 'Status write refused');
        self::assertNull($answer->errorType);
        self::assertNull($answer->errorDetail);
    }

    /**
     * @template T
     * @param Closure(): T $operation Fixture work under the Status owner's identity
     * @return T Operation result
     */
    private function asOwner(Closure $operation): mixed
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: $this->owner->getId()), $operation);
    }

    /** @return list<SignalDTO> All queued frames */
    private function drain(): array
    {
        $frames = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $frames[] = $frame;
        }
        return $frames;
    }

    /**
     * @param string $table Status table with an existing migration stub
     * @param bool $down Whether to drop rather than create
     */
    private function stub(string $table, bool $down): void
    {
        $name = 'create_' . $table;
        if ($down) {
            $name .= '_down';
        }
        Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/' . $name . '.sql'));
    }


}

abstract class StatusSubscriberAdminHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_STATUS => [AgentRegistryKey::WORKER => StatusAgent::class],
        StatusSubscriberAdminGatekeeperAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => StatusSubscriberAdminGatekeeperAgent::class],
        'probe' => [AgentRegistryKey::SCOPE => AgentScope::CLUSTER],
    ];
    public const array PAGES = [StatusSubscriberAdminGatekeeperPage::PAGE => StatusSubscriberAdminGatekeeperPage::class,
        StatusSubscriberAdminOverviewPage::PAGE => StatusSubscriberAdminOverviewPage::class];
    public const array TABLES = [HilosStatusSubscribersTable::TABLE => HilosStatusSubscribersTable::class];
    public const array PAGE_TABLES = [StatusSubscriberAdminGatekeeperPage::PAGE => [HilosStatusSubscribersTable::TABLE => []]];
    protected const array FEATURES = [HilosFeature::STATUS];
    protected const array STATUS_COMPONENTS = [
        'service' => ['name' => 'Service', 'description' => null, 'agentType' => 'probe'],
    ];
}

/** Production lazy Status chains are mounted by the active feature. */
final class StatusSubscriberAdminDbContext extends HilosDbContext
{
}

final class StatusSubscriberAdminRuntime extends RtContext
{
    /** No project collections; feature mounting supplies the real bindings. */
    public function configure(): void
    {
    }

    /** @param string $phase Isolated row projection without running a destructive operation */
    public function phase(string $phase): void
    {
        $row = ProtectedModeRuntime::create();
        $row->phase = $phase;
        $this->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, $row);
    }

    /** @param bool $enabled Whether the node permits looking at ADMIN pages */
    public function viewMode(bool $enabled): void
    {
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        $this->hilosAdminViewModeRuntime->actions->set($enabled);
    }
}

final class StatusSubscriberAdminRouter extends SignalRouter
{
    /** @return class-string<Hilos> Test topology with real Status signal declarations */
    protected function hilosClass(): string
    {
        return StatusSubscriberAdminHilos::class;
    }
}

/** Drives the actual worker parsing and HandoverAsk origin scope, without starting a process or socket. */
final class StatusSubscriberAdminWorker extends WorkerManager
{
    /** @param StatusAgent $owner Actual production owner to register */
    public function __construct(StatusAgent $owner)
    {
        parent::__construct(1);
        $this->replaceOwner($owner);
    }

    /** @param StatusAgent $owner Replacement instance retaining the same claims and durable data */
    public function replaceOwner(StatusAgent $owner): void
    {
        $this->agentManager->addAgent($owner->getId(), $owner);
    }

    /** @param SignalDTO $frame Real agent frame, passed through the worker's normal entry */
    public function deliver(SignalDTO $frame): void
    {
        $dispatch = Closure::bind(static function (self $worker, SignalDTO $frame): void {
            $worker->handleAgentMessage(new DaemonAgentMessageDTO(HilosAgentType::HILOS_STATUS, $frame));
        }, null, WorkerManager::class);
        ExecutionContext::run(new ExecutionFrame(agentId: HilosAgentType::HILOS_STATUS), fn () => $dispatch($this, $frame));
    }

    /**
     * @param string $page Registered page
     * @param string $acceptKey Connection holding the production-declared interests
     * @param bool $subscribersReady Whether the subscriber source has arrived
     */
    public function pageInterests(string $page, string $acceptKey, bool $subscribersReady = true): void
    {
        $reads = Closure::bind(fn (): array => $this->pageSourceReads($page), $this, WorkerManager::class)();
        foreach ($reads as $kind => $keys) {
            foreach ($keys as $key) {
                SourceInterestRegistry::register($kind, $key, SourceConsumer::page($acceptKey));
                if ($subscribersReady || $kind !== SourceChange::KIND_DB || $key !== HilosDbContext::statusSubscribers) {
                    SourceInterestRegistry::markReady($kind, $key);
                }
            }
        }
    }

    /**
     * @param AgentInterface $agent Agent serving the fixture page
     * @return PageSignalRouter Existing page machinery with declarations computed from the test topology
     */
    public static function pageRouter(AgentInterface $agent): PageSignalRouter
    {
        return new PageSignalRouter(new HilosPageFactory($agent, StatusSubscriberAdminHilos::class),
            new ActionRouteConfig(StatusSubscriberAdminHilos::getPageActionRoutes()),
            new SignalRouteConfig(StatusSubscriberAdminHilos::getPageSignalRoutes()));
    }

    /** @return SignalRouter Topology-driven router */
    protected function createSignalRouter(): SignalRouter
    {
        return new StatusSubscriberAdminRouter();
    }

    /** @return AgentManager Fixture registry, without any production lifecycle changes */
    protected function createAgentManager(): AgentManager
    {
        $manager = new StatusSubscriberAdminAgentManager();
        $agent = new StatusSubscriberAdminGatekeeperAgent();
        $manager->addAgent($agent->getId(), $agent);
        return $manager;
    }

    /**
     * @param AgentInterface $agent Page-serving agent
     * @return PageSignalRouter Normal fixture page router
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        return self::pageRouter($agent);
    }
}

final class StatusSubscriberAdminAgentManager extends AgentManager
{
    /**
     * @param string $agentType Requested type, unused
     * @param ?string $agentIndex Requested index, unused
     * @return AgentInterface Production Status owner
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new StatusAgent();
    }
}

final class StatusSubscriberAdminGatekeeperAgent extends AbstractAgent
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_INDEX;

    /** The fixture owns no background work. */
    public function onStop(): void
    {
    }
}

/** The thin project binding contains no forwarding or reply implementation. */
final class StatusSubscriberAdminGatekeeperPage extends AbstractHilosStatusSubscribersPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_INDEX;
}

final class StatusSubscriberAdminOverviewPage extends AbstractHilosStatusPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_INDEX;
}

final class StatusSubscriberAdminCommitProbe implements SourceMirrorSubscriberInterface
{
    public bool $watch = false;
    public bool $failAfterTimeline = false;
    /** @var list<array{transaction: bool, queued: ?SignalDTO, origin: ?string, requestId: ?string}> Write-time observations */
    public array $writes = [];
    /** @var list<bool> Transaction state when real afterCommit callbacks run */
    public array $afterCommitTransactions = [];

    /**
     * @param SourceChange $change Actual ORM write
     * @param SourceChangeProvenance $provenance Local write or rollback
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if (!$this->watch || !in_array($change->sourceKey, [HilosDbContext::statusSubscribers], true)) {
            return;
        }
        $this->writes[] = ['transaction' => Database::hasOpenTransactions(), 'queued' => Hilos::$sr->getNextQueuedSignal(),
            'origin' => $change->origin, 'requestId' => $change->originRequestId];
        if (Database::hasOpenTransactions()) {
            Database::afterCommit(function (): void {
                $this->afterCommitTransactions[] = Database::hasOpenTransactions();
            });
        }
        if ($this->failAfterTimeline && $change->mutationType === TableMutationType::Delete) {
            $this->failAfterTimeline = false;
            throw new DatabaseException('Injected failure after subscriber removal.');
        }
    }
}


final class StatusSubscriberAdminTables extends TableContext
{
    /** Registers the real bounded subscriber viewport. */
    public function configure(): void
    {
        $this->register(HilosStatusSubscribersTable::TABLE, new HilosStatusSubscribersTable());
    }
}

/** Isolated stand-in for the master's committed DB echo to the worker browser buffer. */
final class StatusSubscriberAdminBrowserChanges implements SourceChangeSubscriberInterface
{
    /**
     * @param SourceChange $change Committed fact
     * @param SourceChangeProvenance $provenance Fact provenance
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        Hilos::$browser?->record($change);
    }
}
