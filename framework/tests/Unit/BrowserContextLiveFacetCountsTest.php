<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageResendOutcome;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableFacetCountsSignalData;
use Hilos\Core\Table\DTO\TableFacetsDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableFacetTally;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for live facet count recalculation at the end of a flush.
 */
final class BrowserContextLiveFacetCountsTest extends TestCase
{
    private const int WINDOW = 10;

    public function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$table = null;

        parent::tearDown();
    }

    public function testPublishedMutationBatchCompletesOneRecountWithoutAnyDbOrRtFact(): void
    {
        $viewport = new TableViewportSubscription(LiveFacetUnitTable::TABLE, filter: ['status' => 'pending'], limit: self::WINDOW);
        $table = new LiveFacetUnitTable([new LiveFacetUnitRow('1', 'pending')]);
        $context = $this->boot($viewport, $table);
        $this->declareFacets();
        $table->rows[] = new LiveFacetUnitRow('2', 'pending');
        $table->rows[] = new LiveFacetUnitRow('3', 'sent');
        $context->publishTableMutations(LiveFacetUnitContext::PAGE, 'ak-1', LiveFacetUnitTable::TABLE,
            static fn (): array => [
                new TableRowMutationDTO(TableMutationType::Create, '2', new LiveFacetUnitRow('2', 'pending')),
                new TableRowMutationDTO(TableMutationType::Create, '3', new LiveFacetUnitRow('3', 'sent')),
            ]);
        $context->flushToSignalRouter();
        $names = [];
        $facets = null;
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $names[] = $signal->signalName->getName();
            if ($signal->data->data instanceof TableFacetCountsSignalData) {
                $facets = $signal->data->data;
            }
        }
        self::assertSame([SignalTypeConstants::TABLE_VIEWPORT_COUNT, SignalTypeConstants::TABLE_FACET_COUNTS], $names);
        self::assertNotNull($facets);
        self::assertSame(3, $facets->facets->filters['status'][TableConstants::FACET_KEY_ANY]->count);
        self::assertSame(2, $facets->facets->filters['status'][TableConstants::FACET_KEY_OPTIONS]['pending']->count);
        self::assertSame(1, $facets->facets->filters['status'][TableConstants::FACET_KEY_OPTIONS]['sent']->count);
        self::assertSame([], $context->flushToSignalRouter());
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testARecountMarkedBeforeARouterFailureIsDiscarded(): void
    {
        $viewport = $this->viewport([new LiveFacetUnitRow('1', 'pending')]);
        $table = new LiveFacetUnitTable([new LiveFacetUnitRow('1', 'pending')]);
        $context = $this->boot($viewport, $table);
        $this->declareFacets();
        $agent = $this->createStub(PageAgentInterface::class);
        $agent->method('getAgentSignalSource')->willReturn(new SignalSource(SignalSource::AGENT, 'live-facet-unit'));
        $page = new class ($agent) extends AbstractPage {
            public const string PAGE = LiveFacetUnitContext::PAGE;

            public bool $broken = true;

            protected function buildPagePayload(string $acceptKey, PageRouteParams $params): ?PagePayload
            {
                if ($this->broken) {
                    throw new RuntimeException('simulated page failure');
                }

                return new PagePayload();
            }
        };
        $factory = $this->createStub(AbstractPageFactory::class);
        $factory->method('hasPage')->willReturn(true);
        $factory->method('getPage')->willReturn($page);
        $router = new PageSignalRouter($factory, new ActionRouteConfig());
        $previousBrowser = Hilos::$browser;
        Hilos::initBrowser($context);
        try {
            $row = new LiveFacetUnitRow('2', 'sent');
            $table->rows[] = $row;
            $context->publishTableMutations(LiveFacetUnitContext::PAGE, 'ak-1', LiveFacetUnitTable::TABLE,
                static fn (): array => [new TableRowMutationDTO(TableMutationType::Create, $row->key, $row)]);
            self::assertSame(PageResendOutcome::Failed, $router->resendPage(LiveFacetUnitContext::PAGE, 'ak-1', []));
            self::assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, Hilos::$sr->getNextQueuedSignal()?->signalName->getName());
            self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('ak-1'));
            self::assertSame([], $context->flushToSignalRouter());
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
            self::assertSame(1, $viewport->totalCount());

            $page->broken = false;
            self::assertSame(PageResendOutcome::Answered, $router->resendPage(LiveFacetUnitContext::PAGE, 'ak-1', []));
            self::assertSame(SignalTypeConstants::PAGE_RESPONSE, Hilos::$sr->getNextQueuedSignal()?->signalName->getName());
            $facets = $this->nextFacetCountsSignal();
            self::assertNotNull($facets);
            self::assertSame(2, $facets->facets->filters[LiveFacetUnitTable::FILTER_STATUS][TableConstants::FACET_KEY_ANY]->count);
            self::assertSame([], $context->flushToSignalRouter());
            self::assertNull(Hilos::$sr->getNextQueuedSignal());

            $row = new LiveFacetUnitRow('3', 'pending');
            $table->rows[] = $row;
            $context->publishTableMutations(LiveFacetUnitContext::PAGE, 'ak-1', LiveFacetUnitTable::TABLE,
                static fn (): array => [new TableRowMutationDTO(TableMutationType::Create, $row->key, $row)]);
            self::assertSame([], $context->flushToSignalRouter());
            $facets = $this->nextFacetCountsSignal();
            self::assertNotNull($facets);
            self::assertSame(3, $facets->facets->filters[LiveFacetUnitTable::FILTER_STATUS][TableConstants::FACET_KEY_ANY]->count);
            self::assertSame(3, Hilos::$sr->getTableViewport('ak-1', LiveFacetUnitTable::TABLE)?->totalCount());
        } finally {
            if ($previousBrowser === null) {
                Hilos::resetBrowser();
            } else {
                Hilos::initBrowser($previousBrowser);
            }
        }
    }

    public function testLateFacetGuardCannotSendTheCountOfAReplacedSubscription(): void
    {
        $viewport = $this->viewport();
        $table = new LiveFacetUnitTable([]);
        $context = $this->boot($viewport, $table);
        $this->declareFacets();
        $table->rows[] = new LiveFacetUnitRow('1', 'pending');
        $publish = static fn () => $context->publishTableMutations(LiveFacetUnitContext::PAGE, 'ak-1', LiveFacetUnitTable::TABLE,
            static fn (): array => [new TableRowMutationDTO(TableMutationType::Create, '1', new LiveFacetUnitRow('1', 'pending'))]);
        $publish();
        $context->duringIdentity = static function (): void {
            Hilos::$sr->subscribeToPage(LiveFacetUnitContext::PAGE, new WebSocketPageSubscribeSignalDTO('ak-1', LiveFacetUnitContext::PAGE));
        };

        self::assertSame([], $context->flushToSignalRouter());
        self::assertNull($this->nextFacetCountsSignal());

        $publish();
        self::assertSame([], $context->flushToSignalRouter());
        self::assertNotNull($this->nextFacetCountsSignal());
    }

    public function testMarkedTotalRemembersItsWindowAcrossEarlyPublicationAndFlush(): void
    {
        $viewport = $this->viewport();
        $table = new LiveFacetUnitTable([]);
        $context = $this->boot($viewport, $table);
        $table->rows[] = new LiveFacetUnitRow('1', 'pending');
        $publish = static fn () => $context->publishTableMutations(LiveFacetUnitContext::PAGE, 'ak-1', LiveFacetUnitTable::TABLE,
            static fn (): array => [new TableRowMutationDTO(TableMutationType::Create, '1', new LiveFacetUnitRow('1', 'pending'))]);
        $publish();
        $next = $this->viewport();
        Hilos::$sr->setTableViewport('ak-1', $next);
        $before = $next->totalCount();
        $context->flushToSignalRouter();
        self::assertSame($before, $next->totalCount());

        $publish();
        $context->flushToSignalRouter();
        self::assertSame(1, $next->totalCount());
    }

    public function testCreatedRowTriggersOneFacetCountsSignalWithAllDeclaredFiltersAfterFlush(): void
    {
        $viewport = $this->viewport();
        $table = new LiveFacetUnitTable([
            new LiveFacetUnitRow('1', 'pending'),
            new LiveFacetUnitRow('2', 'sent'),
        ]);
        $context = $this->boot($viewport, $table);
        $this->declareFacets();

        // New row is added to the table source
        $table->rows[] = new LiveFacetUnitRow('3', 'pending');
        $context->record(SourceChange::dbCreated(LiveFacetUnitTable::SOURCE_KEY, '3', ['key' => '3', 'status' => 'pending']));
        $context->flushToSignalRouter();

        $frame = $this->nextFacetCountsSignal();
        $this->assertNotNull($frame);
        $this->assertSame(LiveFacetUnitTable::TABLE, $frame->tableKey);
        $this->assertSame(LiveFacetUnitContext::PAGE, $frame->page);

        $filter = $frame->facets->filters[LiveFacetUnitTable::FILTER_STATUS];
        $this->assertSame(3, $filter[TableConstants::FACET_KEY_ANY]->count);
        $this->assertSame(2, $filter[TableConstants::FACET_KEY_OPTIONS]['pending']->count);
        $this->assertSame(1, $filter[TableConstants::FACET_KEY_OPTIONS]['sent']->count);
        $this->assertNull($this->nextFacetCountsSignal());
    }

    public function testTwoChangesInOneFlushTriggerOneFacetCountsSignal(): void
    {
        $viewport = $this->viewport();
        $table = new LiveFacetUnitTable([
            new LiveFacetUnitRow('1', 'pending'),
        ]);
        $context = $this->boot($viewport, $table);
        $this->declareFacets();

        $table->rows[] = new LiveFacetUnitRow('2', 'pending');
        $table->rows[] = new LiveFacetUnitRow('3', 'sent');
        $context->record(SourceChange::dbCreated(LiveFacetUnitTable::SOURCE_KEY, '2', ['key' => '2', 'status' => 'pending']));
        $context->record(SourceChange::dbCreated(LiveFacetUnitTable::SOURCE_KEY, '3', ['key' => '3', 'status' => 'sent']));
        $context->flushToSignalRouter();

        $frame = $this->nextFacetCountsSignal();
        $this->assertNotNull($frame);
        $filter = $frame->facets->filters[LiveFacetUnitTable::FILTER_STATUS];
        $this->assertSame(3, $filter[TableConstants::FACET_KEY_ANY]->count);
        $this->assertNull($this->nextFacetCountsSignal());
    }

    public function testConnectionWithoutDeclaredFacetsReceivesNoSignal(): void
    {
        $viewport = $this->viewport();
        $table = new LiveFacetUnitTable([
            new LiveFacetUnitRow('1', 'pending'),
        ]);
        $context = $this->boot($viewport, $table);
        // Notice: do NOT call declareFacets()

        $table->rows[] = new LiveFacetUnitRow('2', 'pending');
        $context->record(SourceChange::dbCreated(LiveFacetUnitTable::SOURCE_KEY, '2', ['key' => '2', 'status' => 'pending']));
        $context->flushToSignalRouter();

        $this->assertNull($this->nextFacetCountsSignal());
    }

    public function testChangeWithoutMutationSendsNoFacetCountsSignal(): void
    {
        $viewport = $this->viewport();
        $table = new LiveFacetUnitTable(
            [new LiveFacetUnitRow('1', 'pending')],
            buildMutation: false,
        );
        $context = $this->boot($viewport, $table);
        $this->declareFacets();

        $context->record(SourceChange::dbCreated(LiveFacetUnitTable::SOURCE_KEY, '2', ['key' => '2', 'status' => 'pending']));
        $context->flushToSignalRouter();

        $this->assertNull($this->nextFacetCountsSignal());
    }

    public function testAuthorOfOwnCreateReceivesFacetCountsSignal(): void
    {
        $viewport = $this->viewport();
        $table = new LiveFacetUnitTable([
            new LiveFacetUnitRow('1', 'pending'),
        ]);
        $context = $this->boot($viewport, $table);
        $this->declareFacets();

        $table->rows[] = new LiveFacetUnitRow('2', 'pending');
        $change = SourceChange::dbCreated(
            LiveFacetUnitTable::SOURCE_KEY,
            '2',
            ['key' => '2', 'status' => 'pending'],
            origin: 'ak-1',
            originRequestId: 'req-1',
        );
        $context->record($change);
        $context->flushToSignalRouter();

        $frame = $this->nextFacetCountsSignal();
        $this->assertNotNull($frame);
        $filter = $frame->facets->filters[LiveFacetUnitTable::FILTER_STATUS];
        $this->assertSame(2, $filter[TableConstants::FACET_KEY_ANY]->count);
        $this->assertNull($this->nextFacetCountsSignal());
    }

    public function testSubscriptionFailingInFlushReceivesNoFacetCountsSignal(): void
    {
        $row = new LiveFacetUnitRow('1', 'pending');
        $viewport = $this->viewport([$row]);
        $table = new LiveFacetUnitTable(
            [$row],
            failRow: true,
        );
        $context = $this->boot($viewport, $table);
        $this->declareFacets();

        $context->record(SourceChange::dbUpdated(LiveFacetUnitTable::SOURCE_KEY, '1', ['key' => '1', 'status' => 'sent']));
        $context->flushToSignalRouter();

        $this->assertNull($this->nextFacetCountsSignal());
    }

    public function testTableUnableToCountSendsNoFacetCountsSignal(): void
    {
        $viewport = $this->viewport();
        $table = new LiveFacetUnitTable(
            [new LiveFacetUnitRow('1', 'pending')],
            canCount: false,
        );
        $context = $this->boot($viewport, $table);
        $this->declareFacets();

        $table->rows[] = new LiveFacetUnitRow('2', 'pending');
        $context->record(SourceChange::dbCreated(LiveFacetUnitTable::SOURCE_KEY, '2', ['key' => '2', 'status' => 'pending']));
        $context->flushToSignalRouter();

        $this->assertNull($this->nextFacetCountsSignal());
    }

    /**
     * @param list<LiveFacetUnitRow> $rows
     */
    private function viewport(array $rows = []): TableViewportSubscription
    {
        $viewport = new TableViewportSubscription(
            tableKey: LiveFacetUnitTable::TABLE,
            limit: self::WINDOW,
        );
        $wireRows = [];
        foreach ($rows as $row) {
            $wireRows[$row->key] = [
                PagePayload::rowKey => $row->key,
                PagePayload::slots => [
                    LiveFacetUnitTable::SLOT => $row->toArray(),
                ],
            ];
        }
        $viewport->recordWindow($wireRows, count($rows), true, null, null);

        return $viewport;
    }

    private function declareFacets(string $acceptKey = 'ak-1'): void
    {
        Hilos::$sr?->setTableFacets($acceptKey, LiveFacetUnitTable::TABLE, [
            LiveFacetUnitTable::FILTER_STATUS => ['pending', 'sent'],
        ]);
    }

    private function boot(TableViewportSubscription $viewport, LiveFacetUnitTable $table): LiveFacetUnitContext
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new LiveFacetUnitTableContext($table);
        Hilos::$table->configure();
        Hilos::$sr->subscribeToPage(
            LiveFacetUnitContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-1', LiveFacetUnitContext::PAGE),
        );
        Hilos::$sr->setTableViewport('ak-1', $viewport);

        return new LiveFacetUnitContext();
    }

    private function nextFacetCountsSignal(string $acceptKey = 'ak-1'): ?TableFacetCountsSignalData
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if (
                $signal->signalName->getName() === SignalTypeConstants::TABLE_FACET_COUNTS
                && $signal->data instanceof WebSocketSignalData
                && $signal->data->targetAcceptKey === $acceptKey
                && $signal->data->data instanceof TableFacetCountsSignalData
            ) {
                return $signal->data->data;
            }
        }

        return null;
    }
}

final class LiveFacetUnitContext extends BrowserContext
{
    public const string PAGE = 'live_facet_page';
    public const string SIGNAL = 'live_facet_signal';

    public ?Closure $duringIdentity = null;

    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        $callback = $this->duringIdentity;
        $this->duringIdentity = null;
        $callback?->__invoke();

        return ConnectionIdentity::resolved(1);
    }

    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if ($page !== self::PAGE) {
            return null;
        }

        return BrowserPageConfig::fromArray([
            BrowserConfigKey::SIGNAL => self::SIGNAL,
            BrowserConfigKey::GUARDS => [[BrowserGuardKey::TYPE => BrowserGuardType::AUTHENTICATED]],
        ]);
    }

    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        if ($page !== self::PAGE) {
            return BrowserPageBindings::empty();
        }

        return BrowserPageBindings::fromArray([
            LiveFacetUnitTable::TABLE => [],
        ]);
    }
}

final class LiveFacetUnitTableContext extends TableContext
{
    public function __construct(private readonly LiveFacetUnitTable $table)
    {
    }

    public function configure(): void
    {
        $this->register(LiveFacetUnitTable::TABLE, $this->table);
    }
}

final class LiveFacetUnitTable extends TableDefinition implements SelfSnapshotTable
{
    public const string TABLE = 'liveFacetTable';
    public const string SLOT = 'liveFacetRows';
    public const string SOURCE_KEY = 'liveFacetSource';
    public const string FILTER_STATUS = 'status';

    /**
     * @param list<LiveFacetUnitRow> $rows
     */
    public function __construct(
        public array $rows = [],
        public bool $canCount = true,
        public bool $buildMutation = true,
        public bool $failRow = false,
    ) {
        parent::__construct();
    }

    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== self::SOURCE_KEY || !$this->buildMutation) {
            return null;
        }

        if ($change->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, $change->sourceId);
        }

        $row = new LiveFacetUnitRow((string) $change->sourceId, (string) ($change->rowFields['status'] ?? 'pending'));

        return $this->mutation($change->mutationType, $change->sourceId, $row);
    }

    public function browserRow(AbstractTableRow $row): array
    {
        if ($this->failRow) {
            throw new RuntimeException('simulated row failure');
        }

        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [
                self::SLOT => $row->toArray(),
            ],
        ];
    }

    protected function init(): void
    {
        $this->setRowClass(LiveFacetUnitRow::class);
    }

    public function facetCounts(TableQueryDTO $query, array $wanted): ?array
    {
        if (!$this->canCount) {
            return null;
        }

        return TableFacetTally::forFilters(
            $query,
            array_intersect_key($wanted, [self::FILTER_STATUS => true]),
            fn(TableQueryDTO $set): TableFacetCountDTO => new TableFacetCountDTO(
                count(array_filter(
                    $this->rows,
                    static fn(LiveFacetUnitRow $row): bool => !array_key_exists(self::FILTER_STATUS, $set->filter)
                        || $set->filter[self::FILTER_STATUS] === $row->status,
                )),
                true,
            ),
        );
    }

    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $rows = array_map(static fn(LiveFacetUnitRow $row): array => $row->toArray(), $this->rows);

        return $this->filterInMemory($rows, $query);
    }
}

final class LiveFacetUnitRow extends AbstractTableRow
{
    public function __construct(
        public readonly string $key,
        public readonly string $status = 'pending',
    ) {
    }

    public function getRowKey(): string
    {
        return $this->key;
    }

    public static function keyField(): string
    {
        return 'key';
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'status' => $this->status,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new static((string) $data['key'], (string) ($data['status'] ?? 'pending'));
    }
}
