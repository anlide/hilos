<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\Exception\TableActionsNotConfiguredException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Topology\TopologyValidator;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\State\Item\HilosStatusComponent;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Status\HilosStatusComponentsTable;
use Hilos\Tables\Status\HilosStatusComponentsTableRow;
use Hilos\Tests\Unit\Status\StatusFallbackHilos;
use Hilos\Tests\Unit\Status\StatusProjectionHilos;
use Hilos\Tests\Unit\Status\StatusReadTestCase;
use ReflectionProperty;

/** Real table declarations, read queries and BrowserContext viewport delivery. */
final class StatusComponentsTableTest extends StatusReadTestCase
{
    private const string CONSUMER = 'status-table-fixture';
    private const string ACCEPT_KEY = 'secret';

    /** Drops the test page's source interests with its viewport. */
    protected function tearDown(): void
    {
        SourceInterestRegistry::releaseConsumer(self::CONSUMER);
        parent::tearDown();
    }

    public function testCatalogRowsPrecedeAnyRuntimeFactAndKeepNaturalOrder(): void
    {
        $table = new HilosStatusComponentsTable();
        $snapshot = $table->getPage(new TableQueryDTO());
        self::assertSame(['zeta', 'alpha'], array_map(static fn ($row) => $row->getRowKey(), $snapshot->rows));
        self::assertSame([
            'componentKey' => 'zeta', 'name' => 'Zeta API', 'description' => 'Public endpoints',
            'agentType' => 'hilos_index', 'state' => 'unknown',
        ], $snapshot->rows[0]->toArray());
        self::assertSame($snapshot->rows[0]->toArray(), HilosStatusComponentsTableRow::fromArray($snapshot->rows[0]->toArray())->toArray());
        self::assertSame(25, $table->windowSize());
        self::assertNull($table->defaultSort());
        self::assertNull($table->getItemActionsClass());
        self::assertFalse(isset($table->actions));
        self::assertSame(['alpha'], array_map(static fn ($row) => $row->getRowKey(),
            $table->getPage(new TableQueryDTO(limit: 1, pageIndex: 1))->rows));
        self::assertSame([
            'rowKey' => 'zeta', 'sources' => ['component' => $snapshot->rows[0]->toArray()],
        ], $table->browserRow($snapshot->rows[0]));
        self::assertSame(array_keys($snapshot->rows[0]->toArray()), array_keys($table->wireFields()));
    }

    public function testEmptyCatalogGetsTheRealServiceFallback(): void
    {
        (new ReflectionProperty(Hilos::class, 'appClass'))->setValue(null, StatusFallbackHilos::class);
        $snapshot = (new HilosStatusComponentsTable())->getPage(new TableQueryDTO());
        self::assertCount(1, $snapshot->rows);
        self::assertSame(['componentKey' => 'service', 'name' => 'Service', 'description' => null,
            'agentType' => 'hilos_index', 'state' => 'unknown'], $snapshot->rows[0]->toArray());
    }

    public function testNoCustomMutationActionsAreConfigured(): void
    {
        $this->expectException(TableActionsNotConfiguredException::class);
        (new HilosStatusComponentsTable())->actions;
    }

    public function testUnsupportedSortCannotChangeDeclarationOrder(): void
    {
        $table = new HilosStatusComponentsTable();
        $snapshot = $table->getPage(new TableQueryDTO(sort: TableSortOrderDTO::of(new TableSortDTO('name'))));
        self::assertSame(['zeta', 'alpha'], array_map(static fn ($row) => $row->getRowKey(), $snapshot->rows));
        $snapshot = $table->getPage(new TableQueryDTO(sort: TableSortOrderDTO::of(new TableSortDTO('componentKey'))));
        self::assertSame(['zeta', 'alpha'], array_map(static fn ($row) => $row->getRowKey(), $snapshot->rows));
    }

    public function testSearchStateFilterMembershipAndFacetsUseTheSameSet(): void
    {
        $this->observe('zeta', 2);
        $this->observe('alpha', 0);
        $this->incident(3, 'alpha', suppressedAt: 1700000000020);
        $table = new HilosStatusComponentsTable();
        foreach (['Zeta', 'endpoints', 'hilos_index'] as $term) {
            $snapshot = $table->getPage(new TableQueryDTO(search: $term));
            self::assertSame(['zeta'], array_map(static fn ($row) => $row->getRowKey(), $snapshot->rows));
        }
        self::assertSame([], $table->getPage(new TableQueryDTO(search: 'unknown'))->rows, 'State is a filter, not a searched field');
        $query = new TableQueryDTO(filter: ['state' => 'checking']);
        self::assertSame('checking', $table->getPage($query)->rows[0]->state);
        self::assertTrue($table->containsRow('alpha', $query));
        self::assertFalse($table->containsRow('zeta', $query));
        self::assertFalse($table->containsRow('alpha', new TableQueryDTO(search: 'endpoints')));
        $facets = $table->facetCounts($query, ['state' => ['checking', 'operational', 'unknown'], 'foreign' => ['x']]);
        self::assertSame(['state'], array_keys($facets));
        self::assertSame(['count' => 2, 'exact' => true], $facets['state']['any']->toArray());
        self::assertSame(1, $facets['state']['options']['operational']->count);
        self::assertSame(1, $facets['state']['options']['checking']->count);
        self::assertSame(0, $facets['state']['options']['unknown']->count);
        $this->runtime->phase(ProtectedModeRuntime::PHASE_VERIFYING);
        self::assertCount(2, $table->getPage(new TableQueryDTO(filter: ['state' => 'maintenance']))->rows);
    }

    public function testMutationsResolveIncidentKeysAndNeverDeleteCatalogRows(): void
    {
        $this->observe('zeta', 2);
        $this->observe('alpha', 0);
        $this->incident(3, 'alpha', 1700000000040);
        $table = new HilosStatusComponentsTable();
        $changes = [
            SourceChange::rtCreated(HilosStatusComponent::RT_COLLECTION, 'zeta', []),
            SourceChange::rtDeleted(HilosStatusComponent::RT_COLLECTION, 'zeta'),
            SourceChange::dbUpdated(HilosDbContext::statusIncidents, '3', ['severity' => 'major_outage']),
            SourceChange::dbDeleted(HilosDbContext::statusIncidents, '99', ['component_key' => 'alpha']),
            SourceChange::dbUpdated(HilosDbContext::statusIncidents, '99', [], previous: ['active_component_key' => 'alpha']),
            SourceChange::dbDeleted(HilosDbContext::statusIncidents, '99'),
            SourceChange::dbCleared(HilosDbContext::statusIncidents),
            SourceChange::rtUpdated(ProtectedModeRuntime::RT_ITEM, 'runtime', []),
        ];
        $expected = [['zeta'], ['zeta'], ['alpha'], ['alpha'], ['alpha'], ['zeta', 'alpha'], ['zeta', 'alpha'], ['zeta', 'alpha']];
        foreach ($changes as $index => $change) {
            $mutations = $table->buildMutationsForSourceEvent($change);
            self::assertSame($expected[$index], array_map(static fn ($mutation) => $mutation->rowKey, $mutations));
            foreach ($mutations as $mutation) {
                self::assertSame(TableMutationType::Update, $mutation->type);
                self::assertNotNull($mutation->row);
            }
        }
        self::assertSame([], $table->buildMutationsForSourceEvent(SourceChange::dbUpdated('unrelated', '3', [])));
    }

    public function testRealBrowserDeclarationDiscoversAllThreeSourcesAndFirstWindow(): void
    {
        (new TopologyValidator())->validate(StatusComponentsDeclarationHilos::class);
        $context = $this->browser();
        $rows = BrowserSourceConfig::fromArray(HilosStatusComponentsTable::BROWSER)->rowConfigs();
        self::assertSame(HilosStatusComponent::componentKey, $rows[0][BrowserTableFieldKey::ROW_KEY]);
        self::assertSame([HilosStatusComponent::componentKey => HilosStatusComponentsTableRow::componentKey],
            $rows[0][BrowserTableFieldKey::FIELDS]);
        self::assertSame([HilosStatusComponent::RT_COLLECTION, ProtectedModeRuntime::RT_ITEM],
            $context->rtSourceKeysOfPage(StatusComponentsBrowserFixture::PAGE));
        self::assertSame([HilosDbContext::statusIncidents], $context->dbSourceKeysOfPage(StatusComponentsBrowserFixture::PAGE));
        $payload = $context->buildSubscribeSnapshot(StatusComponentsBrowserFixture::PAGE, self::ACCEPT_KEY, new PageRouteParams([]));
        self::assertSame([], $this->drain(), 'Snapshot assembly sends no companion frames');
        self::assertSame([], $payload->refusedWindows);
        self::assertSame([], $payload->tables);
        $window = $payload->windows[HilosStatusComponentsTable::TABLE];
        self::assertSame(25, $window['limit']);
        self::assertSame([], $window['sort']);
        self::assertSame(['zeta', 'alpha'], array_column($window['rows'], 'rowKey'));
        self::assertSame('unknown', $window['rows'][0]['slots']['component']['state']);
        self::assertTrue(Hilos::$sr->getTableViewport(self::ACCEPT_KEY, HilosStatusComponentsTable::TABLE)->hasRow('alpha'));
    }

    public function testCountsIncidentsAndFreezeAllReachTheRealBrowserWithoutWholeWindowFrames(): void
    {
        $context = $this->browser();
        $context->buildSubscribeSnapshot(StatusComponentsBrowserFixture::PAGE, self::ACCEPT_KEY, new PageRouteParams([]));
        $this->observe('zeta', 2);
        $context->record(SourceChange::rtUpdated(HilosStatusComponent::RT_COLLECTION, 'zeta', ['liveInstances' => 2]));
        $deltas = $this->flush($context);
        self::assertSame(['zeta'], array_map(static fn ($delta) => $delta->rowKey, $deltas));
        self::assertSame('operational', $deltas[0]->row['slots']['component']['state']);
        self::assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $deltas[0]->kind);
        $this->incident(3, 'zeta', 1700000000040);
        $context->record(SourceChange::dbCreated(HilosDbContext::statusIncidents, '3', ['component_key' => 'zeta']));
        $deltas = $this->flush($context);
        self::assertSame('major_outage', $deltas[0]->row['slots']['component']['state']);
        $this->runtime->phase(ProtectedModeRuntime::PHASE_VERIFYING);
        $context->record(SourceChange::rtUpdated(ProtectedModeRuntime::RT_ITEM, 'runtime', ['phase' => 'verifying']));
        $deltas = $this->flush($context);
        self::assertSame(['zeta', 'alpha'], array_map(static fn ($delta) => $delta->rowKey, $deltas));
        foreach ($deltas as $delta) {
            self::assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta->kind);
            self::assertSame('maintenance', $delta->row['slots']['component']['state']);
        }
    }

    public function testStateMembershipChangeUsesStandardPendingRemoval(): void
    {
        $this->observe('zeta', 2);
        $this->observe('alpha', 2);
        $context = $this->browser();
        $viewport = new TableViewportSubscription(HilosStatusComponentsTable::TABLE, filter: ['state' => 'operational'], limit: 25);
        Hilos::$sr->setTableViewport(self::ACCEPT_KEY, $viewport);
        $context->buildSubscribeSnapshot(StatusComponentsBrowserFixture::PAGE, self::ACCEPT_KEY, new PageRouteParams([]));
        $this->observe('zeta', 0);
        $this->incident(3, 'zeta', suppressedAt: 1700000000040);
        $context->record(SourceChange::dbCreated(HilosDbContext::statusIncidents, '3', ['component_key' => 'zeta']));
        $deltas = $this->flush($context);
        self::assertCount(1, $deltas);
        self::assertSame('zeta', $deltas[0]->rowKey);
        self::assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $deltas[0]->kind);
        self::assertSame(TableViewportDeltaDTO::REASON_LEFT_SET, $deltas[0]->reason);
        self::assertFalse($deltas[0]->own);
        self::assertNull($deltas[0]->row);
        self::assertTrue(Hilos::$sr->getTableViewport(self::ACCEPT_KEY, HilosStatusComponentsTable::TABLE)->hasRow('alpha'));
    }

    public function testStaleRuntimeMarksTheComponentSlotInTheFirstWindow(): void
    {
        $this->observe('zeta', 2);
        RtStaleness::mark(HilosStatusComponent::RT_COLLECTION, ['zeta'], 10.0);
        $context = $this->browser();
        $payload = $context->buildSubscribeSnapshot(StatusComponentsBrowserFixture::PAGE, self::ACCEPT_KEY, new PageRouteParams([]));
        $rows = $payload->windows[HilosStatusComponentsTable::TABLE]['rows'];
        self::assertSame(['component'], $rows[0][PagePayload::staleSources]);
        self::assertSame('unknown', $rows[0]['slots']['component']['state']);
        self::assertArrayNotHasKey(PagePayload::staleSources, $rows[1]);
    }

    /** @return StatusComponentsBrowserFixture Browser bound to the real unactivated table */
    private function browser(): StatusComponentsBrowserFixture
    {
        Hilos::$table = new StatusComponentsTableFixture();
        Hilos::$table->configure();
        $context = new StatusComponentsBrowserFixture();
        $context->bindHilosFacade(StatusProjectionHilos::class);
        foreach ([SourceChange::KIND_RT => $context->rtSourceKeysOfPage(StatusComponentsBrowserFixture::PAGE),
            SourceChange::KIND_DB => $context->dbSourceKeysOfPage(StatusComponentsBrowserFixture::PAGE)] as $kind => $keys) {
            foreach ($keys as $key) {
                SourceInterestRegistry::register($kind, $key, self::CONSUMER);
                SourceInterestRegistry::markReady($kind, $key);
            }
        }
        Hilos::$sr->subscribeToPage(StatusComponentsBrowserFixture::PAGE,
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, StatusComponentsBrowserFixture::PAGE));
        return $context;
    }

    /**
     * @param BrowserContext $context Browser holding pending source changes
     * @return list<TableViewportDeltaDTO> Standard deltas only
     */
    private function flush(BrowserContext $context): array
    {
        $context->flushToSignalRouter();
        $deltas = [];
        foreach ($this->drain() as $signal) {
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertNotSame(SignalTypeConstants::TABLE_WINDOW, $signal->signalName->getName());
            self::assertNotSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
            self::assertNotSame(SignalTypeConstants::TABLE_VIEWPORT_FROZEN, $signal->signalName->getName());
            if ($signal->signalName->getName() === SignalTypeConstants::TABLE_VIEWPORT_DELTA) {
                self::assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
                self::assertInstanceOf(TableViewportDeltaDTO::class, $signal->data->data);
                $deltas[] = $signal->data->data;
            }
        }
        return $deltas;
    }
}

final class StatusComponentsTableFixture extends TableContext
{
    /** Mounts the production table without activating project topology. */
    public function configure(): void
    {
        $this->register(HilosStatusComponentsTable::TABLE, new HilosStatusComponentsTable());
    }
}

/** Validates the standalone table's complete browser declaration without activating a project feature. */
abstract class StatusComponentsDeclarationHilos extends Hilos
{
    public const array BROWSER_TABLES = [HilosStatusComponentsTable::TABLE => HilosStatusComponentsTable::class];
}

final class StatusComponentsBrowserFixture extends BrowserContext
{
    public const string PAGE = 'status_components_fixture';

    /**
     * @param string $page Test page key
     * @return ?BrowserPageConfig Test browser metadata
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        return $page === self::PAGE ? BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => 'status_components_fixture']) : null;
    }

    /**
     * @param string $page Test page key
     * @return BrowserPageBindings Production table binding
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return $page === self::PAGE ? BrowserPageBindings::fromArray([HilosStatusComponentsTable::TABLE => []]) : BrowserPageBindings::empty();
    }
}
