<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableViewportCountDTO;
use Hilos\Core\Table\DTO\TableViewportUnannounceDTO;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the live count of a table that can place one row against its set.
 *
 * The fixture answers {@see CountingUnitTable::containsRow()} from a fixed set of keys, which is
 * what lets these tests state the rule without a database: what the count does depends on that
 * answer and on whether the window is holding the row, and on nothing else.
 */
final class BrowserContextViewportCountTest extends TestCase
{
    private const int WINDOW = 10;

    public function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$table = null;

        parent::tearDown();
    }

    public function testAChangeToARowOutsideTheWindowRecountsTheTotalAtEndOfFlush(): void
    {
        $viewport = $this->searchingViewport(40);
        $context = $this->boot($viewport, inSet: ['alpha'], totalCount: 41);

        $context->record(SourceChange::dbUpdated(CountingUnitTable::SOURCE_KEY, 'gamma', ['label' => 'Gamma']));
        $context->flushToSignalRouter();

        // Whether gamma was in the set a moment ago is a question about its previous state, and
        // nobody keeps that. The total is re-queried once at the end of the flush.
        $count = $this->nextCount();
        $this->assertSame(41, $count->totalCount);
        $this->assertTrue($count->totalExact);
        $this->assertSame(5, $count->pageCount);
        $this->assertSame(41, $viewport->totalCount());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testADeleteToARowOutsideTheWindowRecountsTheTotalAtEndOfFlush(): void
    {
        $viewport = $this->searchingViewport(40);
        $context = $this->boot($viewport, inSet: ['alpha'], totalCount: 39);

        $context->record(SourceChange::dbDeleted(CountingUnitTable::SOURCE_KEY, 'gamma', ['key' => 'gamma']));
        $context->flushToSignalRouter();

        $this->nextUnannounce();
        $count = $this->nextCount();
        $this->assertSame(39, $count->totalCount);
        $this->assertTrue($count->totalExact);
        $this->assertSame(4, $count->pageCount);
        $this->assertSame(39, $viewport->totalCount());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testTwoUnresolvableChangesInOneFlushTriggerOneQuery(): void
    {
        $viewport = $this->searchingViewport(40);
        $context = $this->boot($viewport, inSet: ['alpha'], totalCount: 42);

        $context->record(SourceChange::dbUpdated(CountingUnitTable::SOURCE_KEY, 'gamma', ['label' => 'Gamma']));
        $context->record(SourceChange::dbDeleted(CountingUnitTable::SOURCE_KEY, 'delta', ['key' => 'delta']));
        $context->flushToSignalRouter();

        $this->assertSame(1, $this->table()->queryCount);
        $this->nextUnannounce();
        $count = $this->nextCount();
        $this->assertSame(42, $count->totalCount);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testRecountMatchingRecordedTotalEmitsNoFrame(): void
    {
        $viewport = $this->searchingViewport(40);
        $context = $this->boot($viewport, inSet: ['alpha'], totalCount: 40);

        $context->record(SourceChange::dbUpdated(CountingUnitTable::SOURCE_KEY, 'gamma', ['label' => 'Gamma']));
        $context->flushToSignalRouter();

        $this->assertSame(1, $this->table()->queryCount);
        $this->assertSame(40, $viewport->totalCount());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testDeleteWhenCountPastCeilingFallingBelowCeilingEmitsExactCount(): void
    {
        $viewport = $this->searchingViewport(TableConstants::COUNT_CEILING, totalExact: false);
        $context = $this->boot($viewport, inSet: ['alpha'], totalCount: 499, totalExact: true);

        $context->record(SourceChange::dbDeleted(CountingUnitTable::SOURCE_KEY, 'gamma', ['key' => 'gamma']));
        $context->flushToSignalRouter();

        $this->nextUnannounce();
        $count = $this->nextCount();
        $this->assertSame(499, $count->totalCount);
        $this->assertTrue($count->totalExact);
        $this->assertSame(50, $count->pageCount);
        $this->assertSame(499, $viewport->totalCount());
        $this->assertTrue($viewport->totalExact());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testTableUnableToAnswerTriggersOneRecountForTwoChangesInFlush(): void
    {
        $viewport = $this->searchingViewport(40);
        $context = $this->boot($viewport, inSet: null, totalCount: 42);

        $context->record(SourceChange::dbCreated(CountingUnitTable::SOURCE_KEY, 'delta', ['key' => 'delta']));
        $context->record(SourceChange::dbCreated(CountingUnitTable::SOURCE_KEY, 'epsilon', ['key' => 'epsilon']));
        $context->flushToSignalRouter();

        $this->assertSame(1, $this->table()->queryCount);
        $count = $this->nextCount();
        $this->assertSame(42, $count->totalCount);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testARowLeavingTheSetUnderTheWindowTakesOneOff(): void
    {
        $viewport = $this->searchingViewport(40, window: ['alpha', 'beta']);
        $context = $this->boot($viewport, inSet: ['alpha']);

        $context->record(SourceChange::dbUpdated(CountingUnitTable::SOURCE_KEY, 'beta', ['label' => 'Beta']));
        $context->flushToSignalRouter();

        // Beta is in the window, so it was in the set; the table now says it is not.
        $count = $this->nextCount();
        $this->assertSame(39, $count->totalCount);
        $this->assertTrue($count->totalExact);
        $this->assertSame(4, $count->pageCount);
    }

    public function testARowStayingInTheSetUnderTheWindowMovesNothing(): void
    {
        $viewport = $this->searchingViewport(40, window: ['alpha', 'beta']);
        $context = $this->boot($viewport, inSet: ['alpha', 'beta']);

        $context->record(SourceChange::dbUpdated(CountingUnitTable::SOURCE_KEY, 'beta', ['label' => 'Beta']));
        $context->flushToSignalRouter();

        // The row itself still travels — it is in the window and it changed. What does not follow
        // it is a count: the set it belongs to is the same set it belonged to before.
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::TABLE_VIEWPORT_DELTA, $signal->signalName->getName());
        $this->assertSame(40, $viewport->totalCount());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testADeleteUnderTheWindowTakesOneOff(): void
    {
        $viewport = $this->searchingViewport(40, window: ['alpha', 'beta']);
        $context = $this->boot($viewport, inSet: ['alpha']);

        $context->record(SourceChange::dbDeleted(CountingUnitTable::SOURCE_KEY, 'beta'));
        $context->flushToSignalRouter();

        $this->assertSame(39, $this->nextCount()->totalCount);
    }

    public function testACreatedRowCountsOnlyWhenItLandsInTheSet(): void
    {
        $viewport = $this->searchingViewport(40);
        $context = $this->boot($viewport, inSet: ['alpha', 'delta']);

        $context->record(SourceChange::dbCreated(CountingUnitTable::SOURCE_KEY, 'delta', ['key' => 'delta']));
        $context->flushToSignalRouter();

        $this->assertSame(41, $this->nextCount()->totalCount);
    }

    public function testACreatedRowOutsideTheSetMovesNothing(): void
    {
        $viewport = $this->searchingViewport(40);
        $context = $this->boot($viewport, inSet: ['alpha']);

        $context->record(SourceChange::dbCreated(CountingUnitTable::SOURCE_KEY, 'delta', ['key' => 'delta']));
        $context->flushToSignalRouter();

        $this->assertSame(40, $viewport->totalCount());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAnExactCountGrowingPastTheCeilingIsSentOnceAndThenGoesQuiet(): void
    {
        $viewport = $this->searchingViewport(TableConstants::COUNT_CEILING);
        $context = $this->boot($viewport, inSet: ['alpha', 'delta', 'epsilon']);

        $context->record(SourceChange::dbCreated(CountingUnitTable::SOURCE_KEY, 'delta', ['key' => 'delta']));
        $context->flushToSignalRouter();

        $count = $this->nextCount();
        $this->assertSame(TableConstants::COUNT_CEILING, $count->totalCount);
        $this->assertFalse($count->totalExact);
        $this->assertNull($count->pageCount);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());

        $context->record(SourceChange::dbCreated(CountingUnitTable::SOURCE_KEY, 'epsilon', ['key' => 'epsilon']));
        $context->flushToSignalRouter();

        // "At least 500" plus one row is still "at least 500", so a create above the ceiling
        // sends nothing and does not mark the window for recount.
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /**
     * Builds a viewport whose window is addressed under an active search.
     *
     * @param int $totalCount Total recorded for the window
     * @param list<string> $window Row-id keys the connection is holding
     * @param bool $totalExact Whether the recorded total is the size of the set
     * @return TableViewportSubscription Recorded viewport
     */
    private function searchingViewport(int $totalCount, array $window = ['alpha'], bool $totalExact = true): TableViewportSubscription
    {
        $viewport = new TableViewportSubscription(
            tableKey: CountingUnitTable::TABLE,
            filter: [TableConstants::FILTER_KEY_SEARCH => 'a'],
            limit: self::WINDOW,
        );
        $window = array_combine(
            $window,
            array_map(
                static fn(string $rowId): array => [PagePayload::rowKey => $rowId, PagePayload::slots => []],
                $window,
            ),
        );
        $viewport->recordWindow($window, $totalCount, $totalExact, null, null);

        return $viewport;
    }

    /**
     * Boots the registry, the counting table and one connection holding the given viewport.
     *
     * @param TableViewportSubscription $viewport Viewport to register for the connection
     * @param ?list<string> $inSet Row-id keys the table answers as belonging to the searched set
     * @param int $totalCount Total count returned by query()
     * @param bool $totalExact Whether the query() total is exact
     * @return CountingUnitContext Booted browser context
     */
    private function boot(
        TableViewportSubscription $viewport,
        ?array $inSet,
        int $totalCount = 0,
        bool $totalExact = true,
    ): CountingUnitContext {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new CountingUnitTableContext($inSet, $totalCount, $totalExact);
        Hilos::$table->configure();
        Hilos::$sr->subscribeToPage(
            CountingUnitContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-1', CountingUnitContext::PAGE),
        );
        Hilos::$sr->setTableViewport('ak-1', $viewport);

        return new CountingUnitContext();
    }

    /**
     * @return CountingUnitTable The booted fixture table
     */
    private function table(): CountingUnitTable
    {
        $table = Hilos::$table?->get(CountingUnitTable::TABLE);
        $this->assertInstanceOf(CountingUnitTable::class, $table);

        return $table;
    }

    /**
     * Asserts the next queued signal is an addressed table viewport count and returns it.
     *
     * @return TableViewportCountDTO The count payload
     */
    private function nextCount(): TableViewportCountDTO
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        $this->assertSame(SignalTypeConstants::TABLE_VIEWPORT_COUNT, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(TableViewportCountDTO::class, $signal->data->data);

        return $signal->data->data;
    }

    /**
     * Asserts the next queued signal is an addressed table viewport unannouncement and returns it.
     *
     * @return TableViewportUnannounceDTO The unannounce payload
     */
    private function nextUnannounce(): TableViewportUnannounceDTO
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        $this->assertSame(SignalTypeConstants::TABLE_VIEWPORT_UNANNOUNCE, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(TableViewportUnannounceDTO::class, $signal->data->data);

        return $signal->data->data;
    }
}

final class CountingUnitContext extends BrowserContext
{
    public const string PAGE = 'viewport_count_page';
    public const string SIGNAL = 'viewport_count_signal';

    /**
     * Resolves the test page browser metadata.
     *
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Test page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if ($page !== self::PAGE) {
            return null;
        }

        return BrowserPageConfig::fromArray([
            BrowserConfigKey::SIGNAL => self::SIGNAL,
        ]);
    }

    /**
     * Binds the test page to the counting table.
     *
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Test page table bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        if ($page !== self::PAGE) {
            return BrowserPageBindings::empty();
        }

        return BrowserPageBindings::fromArray([
            CountingUnitTable::TABLE => [],
        ]);
    }
}

final class CountingUnitTableContext extends TableContext
{
    /**
     * @param ?list<string> $inSet Row-id keys the table answers as belonging to the searched set
     * @param int $totalCount Total count to return from query()
     * @param bool $totalExact Whether the total returned from query() is exact
     */
    public function __construct(
        private readonly ?array $inSet = [],
        private readonly int $totalCount = 0,
        private readonly bool $totalExact = true,
    ) {
    }

    public function configure(): void
    {
        $this->register(
            CountingUnitTable::TABLE,
            new CountingUnitTable($this->inSet, $this->totalCount, $this->totalExact),
        );
    }
}

/**
 * A viewport table that can place one row against its set without reading any of the others.
 */
final class CountingUnitTable extends TableDefinition implements SelfSnapshotTable
{
    public const string TABLE = 'countingTable';
    public const string SLOT = 'countingRows';
    public const string SOURCE_KEY = 'countingSource';

    public int $queryCount = 0;

    /**
     * @param ?list<string> $inSet Row-id keys that belong to the searched set, null when containsRow cannot say
     * @param int $totalCount Total count to return from query()
     * @param bool $totalExact Whether the total returned from query() is exact
     */
    public function __construct(
        private readonly ?array $inSet = [],
        private readonly int $totalCount = 0,
        private readonly bool $totalExact = true,
    ) {
        parent::__construct();
    }

    /**
     * @return array<string, string> Searched fields, so a searching window reaches this table at all
     */
    protected function searchableFields(): array
    {
        return ['key' => 'key'];
    }

    /**
     * Answers whether one row belongs to the searched set, from the fixture's own list.
     *
     * @param string|int $rowKey Row key to place against the set
     * @param TableQueryDTO $query Window query (ignored: the fixture states the set outright)
     * @return ?bool Whether the row is in the set, or null when the table cannot say
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        if ($this->inSet === null) {
            return null;
        }

        return in_array((string) $rowKey, $this->inSet, true);
    }

    /**
     * Maps a source change to a row mutation of the same type.
     *
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Row mutation, or null for another source
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== self::SOURCE_KEY) {
            return null;
        }
        if ($change->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, $change->sourceId);
        }

        return $this->mutation($change->mutationType, $change->sourceId, new CountingUnitRow($change->sourceId));
    }

    /**
     * Serializes a row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Counting table row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [
                self::SLOT => $row->toArray(),
            ],
        ];
    }

    /**
     * Configures the row class so makeRows rebuilds typed rows.
     */
    protected function init(): void
    {
        $this->setRowClass(CountingUnitRow::class);
    }

    /**
     * Serves an empty window with the total configured for the test.
     *
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Window snapshot
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $this->queryCount++;

        return new TableSnapshotDTO(
            rows: [],
            totalCount: $this->totalCount,
            totalExact: $this->totalExact,
            limit: $query->limit,
        );
    }
}

final class CountingUnitRow extends AbstractTableRow
{
    public function __construct(public readonly string $key)
    {
    }

    public function getRowKey(): string
    {
        return $this->key;
    }

    /**
     * @return string Payload key the row key travels under
     */
    public static function keyField(): string
    {
        return 'key';
    }

    /**
     * @return array<string, mixed> Row fields
     */
    public function toArray(): array
    {
        return ['key' => $this->key];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static Row instance
     */
    public static function fromArray(array $data): static
    {
        return new static((string) $data['key']);
    }
}
