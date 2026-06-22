<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserPageTableBindings;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableViewportAppendDTO;
use Hilos\Core\Table\DTO\TableViewportCountDTO;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for live viewport deltas from BrowserContext::emitBrowserSignals.
 */
final class BrowserContextViewportDeltaTest extends TestCase
{
    public function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$table = null;

        parent::tearDown();
    }

    public function testInWindowUpdateEmitsRowUpdatedDelta(): void
    {
        $context = $this->boot(
            [new ViewportDeltaUnitRow('alpha', 'Alpha')],
            ['alpha'],
            1,
        );
        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['label' => 'Alpha']));
        $context->flushToSignalRouter();

        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta->kind);
        $this->assertSame('alpha', $delta->rowKey);
        $this->assertSame(
            [
                PagePayload::rowKey => 'alpha',
                PagePayload::slots => [
                    ViewportDeltaUnitTable::SLOT => ['key' => 'alpha', 'label' => 'Alpha'],
                ],
            ],
            $delta->row,
        );
    }

    public function testInWindowDeleteEmitsRowRemovedDeltaAndForgetsTheRow(): void
    {
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, offset: 0, limit: 10);
        $viewport->recordWindow(['alpha'], 1);
        $context = $this->bootWithViewport([], $viewport);

        $context->record(SourceChange::dbDeleted(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['key' => 'alpha']));
        $context->flushToSignalRouter();

        $this->assertSame(0, $this->nextCount()->totalCount);

        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $delta->kind);
        $this->assertSame('alpha', $delta->rowKey);
        $this->assertSame(TableViewportDeltaDTO::REASON_DELETED, $delta->reason);
        $this->assertFalse($viewport->hasRow('alpha'));
    }

    public function testLastPageWithRoomCreateAppends(): void
    {
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, offset: 0, limit: 10);
        $viewport->recordWindow(['alpha'], 1);
        $context = $this->bootWithViewport(
            [new ViewportDeltaUnitRow('alpha', 'Alpha'), new ViewportDeltaUnitRow('beta', 'Beta')],
            $viewport,
        );

        $context->record(SourceChange::dbCreated(ViewportDeltaUnitTable::SOURCE_KEY, 'beta', ['key' => 'beta', 'label' => 'Beta']));
        $context->flushToSignalRouter();

        $append = $this->nextAppend();
        $this->assertSame(2, $append->totalCount);
        $this->assertSame(1, $append->pageCount);
        $this->assertSame(
            [
                PagePayload::rowKey => 'beta',
                PagePayload::slots => [
                    ViewportDeltaUnitTable::SLOT => ['key' => 'beta', 'label' => 'Beta'],
                ],
            ],
            $append->row,
        );
        $this->assertTrue($viewport->hasRow('beta'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testCreateOffTheLastPageEmitsCount(): void
    {
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, offset: 0, limit: 1);
        $viewport->recordWindow(['alpha'], 5);
        $context = $this->bootWithViewport(
            [new ViewportDeltaUnitRow('alpha', 'Alpha'), new ViewportDeltaUnitRow('beta', 'Beta')],
            $viewport,
        );

        $context->record(SourceChange::dbCreated(ViewportDeltaUnitTable::SOURCE_KEY, 'beta', ['key' => 'beta', 'label' => 'Beta']));
        $context->flushToSignalRouter();

        $this->assertSame(6, $this->nextCount()->totalCount);
        $this->assertFalse($viewport->hasRow('beta'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testNoViewportDropsTheChange(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new ViewportDeltaUnitTableContext([new ViewportDeltaUnitRow('alpha', 'Alpha')]);
        Hilos::$table->configure();
        Hilos::$sr->subscribeToPage(
            ViewportDeltaUnitContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-1', ViewportDeltaUnitContext::PAGE),
        );

        $context = new ViewportDeltaUnitContext();
        $context->record(SourceChange::dbUpdated(ViewportDeltaUnitTable::SOURCE_KEY, 'alpha', ['label' => 'Alpha']));
        $context->flushToSignalRouter();

        // A viewport table with no active viewport delivers nothing — the change is
        // dropped, and the next table_viewport request returns the current window.
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * Boots the registry, table, page subscription, and a recorded viewport.
     *
     * @param list<ViewportDeltaUnitRow> $rows Table rows the fixture owns
     * @param list<string> $windowRowIds Row-id keys recorded as the connection's window
     * @param int $totalCount Total count recorded for the window
     * @return ViewportDeltaUnitContext Booted browser context
     */
    private function boot(array $rows, array $windowRowIds, int $totalCount): ViewportDeltaUnitContext
    {
        $viewport = new TableViewportSubscription(tableKey: ViewportDeltaUnitTable::TABLE, offset: 0, limit: 10);
        $viewport->recordWindow($windowRowIds, $totalCount);

        return $this->bootWithViewport($rows, $viewport);
    }

    /**
     * Boots the registry, table, page subscription, and the given viewport.
     *
     * @param list<ViewportDeltaUnitRow> $rows Table rows the fixture owns
     * @param TableViewportSubscription $viewport Viewport to register for the connection
     * @return ViewportDeltaUnitContext Booted browser context
     */
    private function bootWithViewport(array $rows, TableViewportSubscription $viewport): ViewportDeltaUnitContext
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new ViewportDeltaUnitTableContext($rows);
        Hilos::$table->configure();
        Hilos::$sr->subscribeToPage(
            ViewportDeltaUnitContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-1', ViewportDeltaUnitContext::PAGE),
        );
        Hilos::$sr->setTableViewport('ak-1', $viewport);

        return new ViewportDeltaUnitContext();
    }

    /**
     * Asserts the next queued signal is an addressed table viewport delta and returns it.
     *
     * @return TableViewportDeltaDTO The delta payload
     */
    private function nextDelta(): TableViewportDeltaDTO
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        $this->assertSame(SignalTypeConstants::TABLE_VIEWPORT_DELTA, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(TableViewportDeltaDTO::class, $signal->data->data);

        return $signal->data->data;
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
     * Asserts the next queued signal is an addressed table viewport append and returns it.
     *
     * @return TableViewportAppendDTO The append payload
     */
    private function nextAppend(): TableViewportAppendDTO
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        $this->assertSame(SignalTypeConstants::TABLE_VIEWPORT_APPEND, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(TableViewportAppendDTO::class, $signal->data->data);

        return $signal->data->data;
    }
}

final class ViewportDeltaUnitContext extends BrowserContext
{
    public const string PAGE = 'viewport_delta_page';
    public const string SIGNAL = 'viewport_delta_signal';

    /**
     * Resolves the test page browser metadata.
     *
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Test page metadata, or null when absent
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
     * Binds the test page to the self-snapshot table.
     *
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageTableBindings Test page table bindings
     */
    protected function resolveBrowserPageTables(string $page): BrowserPageTableBindings
    {
        if ($page !== self::PAGE) {
            return BrowserPageTableBindings::empty();
        }

        return BrowserPageTableBindings::fromArray([
            ViewportDeltaUnitTable::TABLE => [],
        ]);
    }
}

final class ViewportDeltaUnitTableContext extends TableContext
{
    /**
     * @param list<ViewportDeltaUnitRow> $rows Snapshot rows the table owns
     */
    public function __construct(private readonly array $rows = [])
    {
    }

    public function configure(): void
    {
        $this->register(ViewportDeltaUnitTable::TABLE, new ViewportDeltaUnitTable($this->rows));
    }
}

final class ViewportDeltaUnitTable extends TableDefinition implements SelfSnapshotTable
{
    public const string TABLE = 'viewportDeltaTable';
    public const string SLOT = 'viewportDeltaRows';
    public const string SOURCE_KEY = 'viewportDeltaSource';

    /**
     * @param list<ViewportDeltaUnitRow> $rows Snapshot rows the table owns
     */
    public function __construct(private readonly array $rows = [])
    {
        parent::__construct();
    }

    /**
     * Maps a source change to a row mutation preserving its type when the row
     * exists, or a delete otherwise.
     *
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Row mutation, or null for another source
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== self::SOURCE_KEY) {
            return null;
        }

        foreach ($this->rows as $row) {
            if ($row->getRowKey() === $change->sourceId) {
                return $this->mutation($change->mutationType, $row->getRowKey(), $row);
            }
        }

        return $this->mutation(TableMutationType::Delete, $change->sourceId);
    }

    /**
     * Serializes a row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Self-snapshot row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->getRowKey() ?? '',
            BrowserPageSignalData::sources => [
                self::SLOT => $row->toArray(),
            ],
        ];
    }

    /**
     * Configures the row class so makeRows rebuilds typed rows from the filter output.
     */
    protected function init(): void
    {
        $this->setRowClass(ViewportDeltaUnitRow::class);
    }

    /**
     * Applies the in-memory filter to the injected rows.
     *
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Windowed snapshot
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $rows = array_map(static fn(ViewportDeltaUnitRow $row): array => $row->toArray(), $this->rows);

        return InMemoryTableFilter::apply($rows, $query);
    }
}

final class ViewportDeltaUnitRow extends AbstractTableRow
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
    ) {
    }

    public function getRowKey(): string
    {
        return $this->key;
    }

    /**
     * @return array<string, mixed> Row fields
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static Row instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            (string) ($data['key'] ?? ''),
            (string) ($data['label'] ?? ''),
        );
    }
}
