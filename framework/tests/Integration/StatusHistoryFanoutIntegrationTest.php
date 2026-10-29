<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Sync\DTO\DbSyncClearedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Topology\TopologyValidator;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\Schema\Schema;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Status\StatusAgent;
use Hilos\Status\StatusSeverity;
use Hilos\Tables\Status\HilosStatusHistoryTable;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/** Real commits and source delivery need the ordinary lifecycle, outside an always-rollback scope. */
final class StatusHistoryFanoutIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string OWNER = 'status-history-fanout';
    private const array TABLES = ['hilos_status_work', 'hilos_status_incident', 'hilos_status_maintenance_period'];

    /** @var array Facades restored after each real-commit case */
    private array $previousGlobals;
    /** @var array<string, array> Bus registrations restored after the fixture */
    private array $previousBus = [];
    /** @var array<string, array> Source interests restored after the fixture */
    private array $previousInterests = [];
    private string $previousApp;
    private int $ownerToken;
    private StatusHistoryFanoutBrowser $browser;

    /** Creates schema before case writes and mounts the real table with local ADMIN topology. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousGlobals = [Hilos::$db, Hilos::$sr, Hilos::$table, Hilos::$browser];
        $this->previousApp = Hilos::appClass();
        foreach (['mirrors', 'reactions', 'provenance'] as $property) {
            $this->previousBus[$property] = new ReflectionProperty(SourceChangeBus::class, $property)->getValue();
        }
        foreach (['byConsumer', 'states', 'readsWhatIsDelivered'] as $property) {
            $this->previousInterests[$property] = new ReflectionProperty(SourceInterestRegistry::class, $property)->getValue();
        }
        foreach (self::TABLES as $table) {
            Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . '.sql'));
        }
        Schema::reset();
        Schema::initialize();
        OwnershipDeclaration::claimDb(StatusAgent::class, self::OWNER);
        $this->ownerToken = ExecutionContext::push(new ExecutionFrame(agentId: self::OWNER));
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, StatusHistoryFanoutHilos::class);
        Hilos::$db = new StatusHistoryStorageDbContext();
        Hilos::$db->configure();
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new StatusHistoryFanoutTables();
        Hilos::$table->configure();
        $this->browser = new StatusHistoryFanoutBrowser(7, true);
        StatusHistoryFanoutHilos::initBrowser($this->browser);
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        SourceChangeBus::subscribe(new StatusHistoryFanoutChanges());
        foreach ([HilosDbContext::statusIncidents, HilosDbContext::statusMaintenancePeriods] as $key) {
            SourceInterestRegistry::register(SourceChange::KIND_DB, $key, SourceConsumer::page('history-reader'));
            SourceInterestRegistry::markReady(SourceChange::KIND_DB, $key);
        }
    }

    /** Drops committed fixtures and restores every borrowed facade and source registration. */
    protected function tearDown(): void
    {
        try {
            foreach (array_reverse(self::TABLES) as $table) {
                Database::sqlRun('DROP TABLE IF EXISTS `' . $table . '`');
            }
            Schema::reset();
        } finally {
            TruthSourceRegistry::unregisterAgent(self::OWNER);
            ExecutionContext::pop($this->ownerToken);
            foreach ($this->previousBus as $property => $value) {
                new ReflectionProperty(SourceChangeBus::class, $property)->setValue(null, $value);
            }
            foreach ($this->previousInterests as $property => $value) {
                new ReflectionProperty(SourceInterestRegistry::class, $property)->setValue(null, $value);
            }
            new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousApp);
            [Hilos::$db, Hilos::$sr, Hilos::$table, Hilos::$browser] = $this->previousGlobals;
            parent::tearDown();
        }
    }

    /** An open candidate never arrives; its real completion uses the ordinary pending announcement. */
    public function testCommittedCompletionArrivesPendingAndVisibleValuesUpdateWithoutMovingTheHeldWindow(): void
    {
        $period = Database::transaction('primary', static fn() => Hilos::$db->statusMaintenancePeriods->actions->import(
            str_repeat('a', 32), null, null, false, 'Retained maintenance', ['retired'], 1000, 2000, null, 3000));
        $this->browser->flushToSignalRouter();
        $this->frames();
        $viewport = $this->open();
        $held = 'maintenance:' . $period->id;
        self::assertTrue($viewport->hasRow($held));
        $candidate = Database::transaction('primary', static fn() => Hilos::$db->statusIncidents->actions->candidate(
            'retired', 'Retained component', StatusSeverity::PARTIAL_OUTAGE, 4000, 5000));
        $this->browser->flushToSignalRouter();
        self::assertSame([], $this->frames());
        Database::transaction('primary', static fn() => $candidate->actions->suppress(4500));
        $this->browser->flushToSignalRouter();
        self::assertSame([], $this->frames());
        self::assertSame(1, $viewport->totalCount());
        Database::transaction('primary', static fn() => $candidate->actions->resolve(6000));
        self::assertSame([], $this->browser->flushToSignalRouter());
        $frames = $this->frames();
        $announcements = array_values(array_filter($frames, static fn(array $frame): bool =>
            $frame['name'] === SignalTypeConstants::TABLE_VIEWPORT_ANNOUNCE));
        self::assertCount(1, $announcements);
        self::assertFalse($viewport->hasRow('incident:' . $candidate->id));
        self::assertTrue($viewport->hasRow($held));
        self::assertSame(2, $viewport->totalCount());
        self::assertSame([], array_filter($frames, static fn(array $frame): bool => $frame['name'] === SignalTypeConstants::TABLE_WINDOW));
        self::assertSame('incident:' . $candidate->id, $announcements[0]['data']['rowKey']);
        self::assertSame('above', $announcements[0]['data']['placement']);
        $this->browser->answerTableRowFocus(StatusHistoryFanoutPage::PAGE, 'history-reader', HilosStatusHistoryTable::TABLE,
            'incident:' . $candidate->id);
        $focus = $this->frames();
        self::assertSame([SignalTypeConstants::TABLE_VIEWPORT_DELTA], array_column($focus, 'name'));
        self::assertSame('row_removed', $focus[0]['data']['kind']);
        self::assertSame('moved_out', $focus[0]['data']['reason']);
        self::assertSame('incident:' . $candidate->id, $focus[0]['data']['row']['slots']['history']['id']);
        self::assertSame('Retained component', $focus[0]['data']['row']['slots']['history']['title']);
        self::assertFalse($viewport->hasRow('incident:' . $candidate->id));

        $activePeriod = Database::transaction('primary', static fn() => Hilos::$db->statusMaintenancePeriods->actions->import(
            str_repeat('b', 32), null, null, false, 'Later maintenance', ['retired'], 7000, 7000, null, null));
        $this->browser->flushToSignalRouter();
        self::assertSame([], $this->frames());
        Database::transaction('primary', static fn() => $activePeriod->actions->reconcile(7500, null, null));
        $this->browser->flushToSignalRouter();
        self::assertSame([], $this->frames());
        self::assertSame(2, $viewport->totalCount());
        Database::transaction('primary', static fn() => $activePeriod->actions->reconcile(8000, null, 9000));
        $this->browser->flushToSignalRouter();
        $announcements = array_values(array_filter($this->frames(), static fn(array $frame): bool =>
            $frame['name'] === SignalTypeConstants::TABLE_VIEWPORT_ANNOUNCE));
        self::assertCount(1, $announcements);
        self::assertSame('maintenance:' . $activePeriod->id, $announcements[0]['data']['rowKey']);
        self::assertSame(3, $viewport->totalCount());

        // A peer correction changes only values; the real sync applicator rebuilds the full current row.
        Database::sql('UPDATE `hilos_status_maintenance_period` SET `title` = ? WHERE `id` = ?', ['Corrected snapshot', $period->id]);
        DbSyncApplicator::applyUpdated(new DbSyncUpdatedSignalData(HilosDbContext::statusMaintenancePeriods,
            (string)$period->id, ['title' => 'Corrected snapshot']), originNodeId: 'history-peer');
        $this->browser->flushToSignalRouter();
        $frames = $this->frames();
        $deltas = array_values(array_filter($frames, static fn(array $frame): bool =>
            $frame['name'] === SignalTypeConstants::TABLE_VIEWPORT_DELTA));
        self::assertCount(1, $deltas);
        self::assertSame('row_updated', $deltas[0]['data']['kind']);
        self::assertSame('Corrected snapshot', $deltas[0]['data']['row']['slots']['history']['title']);
        self::assertSame($held, $deltas[0]['data']['rowKey']);
        self::assertArrayNotHasKey('own', $deltas[0]['data']);
        self::assertTrue($viewport->hasRow($held));
        self::assertFalse($viewport->hasRow('incident:' . $candidate->id));
        self::assertSame(3, $viewport->totalCount());
    }

    /** ADMIN table metadata and every normalized field remain visible through the viewer serialization bridge. */
    public function testTopologyAndNonpersonalWireVerdictsCoverBothSources(): void
    {
        new TopologyValidator()->validate(StatusHistoryFanoutHilos::class);
        new TopologyValidator()->validateReferences(StatusHistoryFanoutHilos::class);
        Database::transaction('primary', static function (): void {
            $incident = Hilos::$db->statusIncidents->actions->candidate('retired', 'Saved', StatusSeverity::PARTIAL_OUTAGE, 1000, 2000);
            $incident->actions->resolve(3000);
            Hilos::$db->statusMaintenancePeriods->actions->import(
                str_repeat('a', 32), null, null, false, 'Saved work', ['retired'], 1000, 2000, null, 3000);
        });
        $this->browser->flushToSignalRouter();
        $this->frames();
        $this->browser->viewer = true;
        $viewport = $this->open();
        self::assertTrue($this->browser->sendTableWindow(StatusHistoryFanoutPage::PAGE, 'history-reader', $viewport));
        $frames = $this->frames();
        $expected = new HilosStatusHistoryTable()->getPage(new TableQueryDTO());
        self::assertSame(PageAccessLevel::ADMIN, StatusHistoryFanoutPage::ACCESS_LEVEL);
        self::assertCount(2, $frames[0]['data']['rows']);
        foreach ($frames[0]['data']['rows'] as $index => $row) {
            self::assertSame($expected->rows[$index]->toArray(), $row['slots']['history']);
            self::assertSame($row['slots']['history']['id'], $row['rowKey']);
        }
    }

    /** @return iterable<string, array{string}> Either history source can invalidate the complete merge */
    public static function sources(): iterable
    {
        yield 'incidents' => [HilosDbContext::statusIncidents];
        yield 'maintenance' => [HilosDbContext::statusMaintenancePeriods];
    }

    /**
     * @param string $source Source being cleared
     */
    #[DataProvider('sources')]
    public function testClearedSourceFreezesTheWholeWindowAndTheNextFactRebuildsBothHalves(string $source): void
    {
        Database::transaction('primary', static function (): void {
            $incident = Hilos::$db->statusIncidents->actions->candidate('retired', 'Saved', StatusSeverity::PARTIAL_OUTAGE, 1000, 2000);
            $incident->actions->resolve(3000);
            Hilos::$db->statusMaintenancePeriods->actions->import(
                str_repeat('a', 32), null, null, false, 'Saved work', ['retired'], 1000, 2000, null, 3000);
        });
        $this->browser->flushToSignalRouter();
        $this->frames();
        $this->open();
        Database::sql($source === HilosDbContext::statusIncidents
            ? 'DELETE FROM `hilos_status_incident`' : 'DELETE FROM `hilos_status_maintenance_period`');
        DbSyncApplicator::applyCleared(new DbSyncClearedSignalData($source));
        $this->browser->record(SourceChange::dbCleared($source));
        $this->browser->flushToSignalRouter();
        $frames = $this->frames();
        self::assertSame([SignalTypeConstants::TABLE_VIEWPORT_FROZEN], array_column($frames, 'name'));
        self::assertTrue(Hilos::$sr->isTableWindowOwed('history-reader', HilosStatusHistoryTable::TABLE));
        $this->browser->record(SourceChange::dbUpdated($source, '999999', []));
        $this->browser->flushToSignalRouter();
        $frames = $this->frames();
        self::assertSame([SignalTypeConstants::TABLE_WINDOW], array_column($frames, 'name'));
        self::assertSame(1, $frames[0]['data']['totalCount']);
        self::assertSame($source === HilosDbContext::statusIncidents ? 'maintenance' : 'incident',
            $frames[0]['data']['rows'][0]['slots']['history']['kind']);
        self::assertFalse(Hilos::$sr->isTableWindowOwed('history-reader', HilosStatusHistoryTable::TABLE));
    }

    /** @param string $source Source whose storage is unavailable */
    #[DataProvider('sources')]
    public function testSourceFailureRefusesTheWholeTableAndFacets(string $source): void
    {
        Database::sqlRun($source === HilosDbContext::statusIncidents
            ? 'DROP TABLE `hilos_status_incident`' : 'DROP TABLE `hilos_status_maintenance_period`');
        $table = new HilosStatusHistoryTable();
        foreach (['window', 'facet'] as $read) {
            try {
                if ($read === 'window') {
                    $table->getPage(new TableQueryDTO());
                } else {
                    $table->facetCounts(new TableQueryDTO(), ['outcome' => ['resolved']]);
                }
                self::fail('A failed source must refuse the merged ' . $read);
            } catch (DatabaseException) {
                self::assertTrue(true);
            }
        }
        $viewport = new TableViewportSubscription(tableKey: HilosStatusHistoryTable::TABLE, limit: 32);
        Hilos::$sr->setTableViewport('history-reader', $viewport);
        self::assertFalse($this->browser->sendTableWindow(StatusHistoryFanoutPage::PAGE, 'history-reader', $viewport));
        self::assertSame([SignalTypeConstants::TABLE_WINDOW_REFUSED], array_column($this->frames(), 'name'));
    }

    /** @return TableViewportSubscription Delivered first window with ordinary row digests and anchors */
    private function open(): TableViewportSubscription
    {
        Hilos::$sr->subscribeToPage(StatusHistoryFanoutPage::PAGE,
            new WebSocketPageSubscribeSignalDTO('history-reader', StatusHistoryFanoutPage::PAGE));
        $viewport = new TableViewportSubscription(tableKey: HilosStatusHistoryTable::TABLE,
            sort: new HilosStatusHistoryTable()->defaultSort(), limit: 32);
        Hilos::$sr->setTableViewport('history-reader', $viewport);
        self::assertTrue($this->browser->sendTableWindow(StatusHistoryFanoutPage::PAGE, 'history-reader', $viewport));
        self::assertSame([SignalTypeConstants::TABLE_WINDOW], array_column($this->frames(), 'name'));
        return $viewport;
    }

    /** @return list<array{name: string, data: array<string, mixed>}> Queued browser frames, excluding DB sync transport */
    private function frames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalSource->getSource() !== SignalSource::DB) {
                $frames[] = ['name' => $signal->signalName->getName(), 'data' => $signal->data->data->toArray()];
            }
        }
        return $frames;
    }
}

/** Local ADMIN fixture; the production History page is activated separately. */
final class StatusHistoryFanoutPage extends AbstractPage
{
    public const string PAGE = 'status_history_table_fixture';
    public const string SUBSCRIPTION_AGENT_TYPE = StatusHistoryFanoutAgent::AGENT_TYPE;
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;
    public const bool OPEN_WHILE_FROZEN = true;
    public const array BROWSER = [BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_STATUS_WORKS];
}

/** Mounts the table only in test topology, with no production routes or signal declarations. */
abstract class StatusHistoryFanoutHilos extends Hilos
{
    public const array AGENTS = [StatusHistoryFanoutAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => StatusHistoryFanoutAgent::class]];
    public const array PAGES = [StatusHistoryFanoutPage::PAGE => StatusHistoryFanoutPage::class];
    public const array TABLES = [HilosStatusHistoryTable::TABLE => HilosStatusHistoryTable::class];
    public const array PAGE_TABLES = [StatusHistoryFanoutPage::PAGE => [HilosStatusHistoryTable::TABLE => []]];
}

/** Metadata holder for the local page; the production table body remains unchanged. */
final class StatusHistoryFanoutAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'status_history_fixture';

    /** No background lifecycle is needed to exercise browser delivery. */
    public function onStop(): void
    {
    }
}

final class StatusHistoryFanoutBrowser extends IdentityTestBrowser
{
    public bool $viewer = false;

    /**
     * @param string $pageClass Local ADMIN page
     * @param string $acceptKey Local admitted administrator
     * @return bool Whether this case exercises the viewer serialization bridge
     */
    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }
}

final class StatusHistoryFanoutTables extends TableContext
{
    /** Registers the real production table body. */
    public function configure(): void
    {
        $this->register(HilosStatusHistoryTable::TABLE, new HilosStatusHistoryTable());
    }
}

final class StatusHistoryFanoutChanges implements SourceChangeSubscriberInterface
{
    /**
     * @param SourceChange $change Actual committed source fact
     * @param SourceChangeProvenance $provenance Bus origin
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        Hilos::$browser?->record($change);
    }
}
