<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableViewportAppendDTO;
use Hilos\Core\Table\DTO\TableViewportCountDTO;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Proves the server-windowed viewport path is generalized beyond self-snapshot
 * tables: a source-fanned {@see ViewportTable} (the kind the Hilos users table is)
 * is served its window and live deltas exactly like settings, while the same table
 * with no active viewport delivers nothing (it never uses page_response).
 */
final class BrowserContextSourceFanoutWindowTest extends TestCase
{
    public function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$table = null;

        parent::tearDown();
    }

    public function testSendTableWindowServesASourceFanoutViewportTable(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new SourceFanoutWindowUnitTableContext([
            new SourceFanoutWindowUnitRow('a', 'Alpha'),
            new SourceFanoutWindowUnitRow('b', 'Beta'),
            new SourceFanoutWindowUnitRow('c', 'Gamma'),
        ]);
        Hilos::$table->configure();

        $viewport = new TableViewportSubscription(tableKey: SourceFanoutWindowUnitTable::TABLE, offset: 1, limit: 1);
        new SourceFanoutWindowUnitContext()->sendTableWindow(
            SourceFanoutWindowUnitContext::PAGE,
            'ak-1',
            $viewport,
        );

        $signal = Hilos::$sr->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::TABLE_WINDOW, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(TableWindowSignalData::class, $signal->data->data);
        $this->assertSame(
            [
                TableWindowSignalData::page => SourceFanoutWindowUnitContext::PAGE,
                TableWindowSignalData::tableKey => SourceFanoutWindowUnitTable::TABLE,
                TableWindowSignalData::rows => [
                    [
                        PagePayload::rowKey => 'b',
                        PagePayload::slots => [
                            SourceFanoutWindowUnitTable::SLOT => ['key' => 'b', 'label' => 'Beta'],
                        ],
                    ],
                ],
                TableWindowSignalData::totalCount => 3,
                TableWindowSignalData::offset => 1,
                TableWindowSignalData::limit => 1,
            ],
            $signal->data->data->toArray(),
        );
        $this->assertSame(['b'], $viewport->rowIds());
        $this->assertSame(3, $viewport->totalCount());
    }

    public function testInWindowUpdateEmitsRowUpdatedDelta(): void
    {
        $context = $this->boot([new SourceFanoutWindowUnitRow('alpha', 'Alpha')], ['alpha'], 1);
        $context->record(SourceChange::dbUpdated(SourceFanoutWindowUnitTable::SOURCE_KEY, 'alpha', ['label' => 'Alpha']));
        $context->flushToSignalRouter();

        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta->kind);
        $this->assertSame('alpha', $delta->rowKey);
        $this->assertSame(
            [
                PagePayload::rowKey => 'alpha',
                PagePayload::slots => [
                    SourceFanoutWindowUnitTable::SLOT => ['key' => 'alpha', 'label' => 'Alpha'],
                ],
            ],
            $delta->row,
        );
    }

    public function testInWindowDeleteEmitsRowRemovedDeltaAndForgetsTheRow(): void
    {
        $viewport = new TableViewportSubscription(tableKey: SourceFanoutWindowUnitTable::TABLE, offset: 0, limit: 10);
        $viewport->recordWindow(['alpha'], 1);
        $context = $this->bootWithViewport([], $viewport);

        $context->record(SourceChange::dbDeleted(SourceFanoutWindowUnitTable::SOURCE_KEY, 'alpha', ['key' => 'alpha']));
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
        $viewport = new TableViewportSubscription(tableKey: SourceFanoutWindowUnitTable::TABLE, offset: 0, limit: 10);
        $viewport->recordWindow(['alpha'], 1);
        $context = $this->bootWithViewport(
            [new SourceFanoutWindowUnitRow('alpha', 'Alpha'), new SourceFanoutWindowUnitRow('beta', 'Beta')],
            $viewport,
        );

        $context->record(SourceChange::dbCreated(SourceFanoutWindowUnitTable::SOURCE_KEY, 'beta', ['key' => 'beta', 'label' => 'Beta']));
        $context->flushToSignalRouter();

        $append = $this->nextAppend();
        $this->assertSame(2, $append->totalCount);
        $this->assertSame(1, $append->pageCount);
        $this->assertSame(
            [
                PagePayload::rowKey => 'beta',
                PagePayload::slots => [
                    SourceFanoutWindowUnitTable::SLOT => ['key' => 'beta', 'label' => 'Beta'],
                ],
            ],
            $append->row,
        );
        $this->assertTrue($viewport->hasRow('beta'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testCreateOffTheLastPageEmitsCount(): void
    {
        $viewport = new TableViewportSubscription(tableKey: SourceFanoutWindowUnitTable::TABLE, offset: 0, limit: 1);
        $viewport->recordWindow(['alpha'], 5);
        $context = $this->bootWithViewport(
            [new SourceFanoutWindowUnitRow('alpha', 'Alpha'), new SourceFanoutWindowUnitRow('beta', 'Beta')],
            $viewport,
        );

        $context->record(SourceChange::dbCreated(SourceFanoutWindowUnitTable::SOURCE_KEY, 'beta', ['key' => 'beta', 'label' => 'Beta']));
        $context->flushToSignalRouter();

        $this->assertSame(6, $this->nextCount()->totalCount);
        $this->assertFalse($viewport->hasRow('beta'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testNoViewportEmitsNoDelta(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new SourceFanoutWindowUnitTableContext([new SourceFanoutWindowUnitRow('alpha', 'Alpha')]);
        Hilos::$table->configure();
        Hilos::$sr->subscribeToPage(
            SourceFanoutWindowUnitContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-1', SourceFanoutWindowUnitContext::PAGE),
        );

        $context = new SourceFanoutWindowUnitContext();
        $context->record(SourceChange::dbUpdated(SourceFanoutWindowUnitTable::SOURCE_KEY, 'alpha', ['label' => 'Alpha']));
        $context->flushToSignalRouter();

        // A viewport table with no active viewport delivers nothing — the change
        // is dropped (the next table_viewport request returns the current window).
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * Boots the registry, table, page subscription, and a recorded viewport.
     *
     * @param list<SourceFanoutWindowUnitRow> $rows Table rows the fixture owns
     * @param list<string> $windowRowIds Row-id keys recorded as the connection's window
     * @param int $totalCount Total count recorded for the window
     * @return SourceFanoutWindowUnitContext Booted browser context
     */
    private function boot(array $rows, array $windowRowIds, int $totalCount): SourceFanoutWindowUnitContext
    {
        $viewport = new TableViewportSubscription(tableKey: SourceFanoutWindowUnitTable::TABLE, offset: 0, limit: 10);
        $viewport->recordWindow($windowRowIds, $totalCount);

        return $this->bootWithViewport($rows, $viewport);
    }

    /**
     * Boots the registry, table, page subscription, and the given viewport.
     *
     * @param list<SourceFanoutWindowUnitRow> $rows Table rows the fixture owns
     * @param TableViewportSubscription $viewport Viewport to register for the connection
     * @return SourceFanoutWindowUnitContext Booted browser context
     */
    private function bootWithViewport(array $rows, TableViewportSubscription $viewport): SourceFanoutWindowUnitContext
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new SourceFanoutWindowUnitTableContext($rows);
        Hilos::$table->configure();
        Hilos::$sr->subscribeToPage(
            SourceFanoutWindowUnitContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-1', SourceFanoutWindowUnitContext::PAGE),
        );
        Hilos::$sr->setTableViewport('ak-1', $viewport);

        return new SourceFanoutWindowUnitContext();
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

final class SourceFanoutWindowUnitContext extends BrowserContext
{
    public const string PAGE = 'source_fanout_window_page';
    public const string SIGNAL = 'source_fanout_window_signal';

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
     * Binds the test page to the source-fanned viewport table.
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
            SourceFanoutWindowUnitTable::TABLE => [],
        ]);
    }
}

final class SourceFanoutWindowUnitTableContext extends TableContext
{
    /**
     * @param list<SourceFanoutWindowUnitRow> $rows Snapshot rows the table owns
     */
    public function __construct(private readonly array $rows = [])
    {
    }

    public function configure(): void
    {
        $this->register(SourceFanoutWindowUnitTable::TABLE, new SourceFanoutWindowUnitTable($this->rows));
    }
}

/**
 * A source-fanned viewport table: it implements ViewportTable WITHOUT being a
 * SelfSnapshotTable, the shape the Hilos users table takes. It owns no declarative
 * browser config, so a change reaching it with no viewport produces nothing.
 */
final class SourceFanoutWindowUnitTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'sourceFanoutWindowTable';
    public const string SLOT = 'sourceFanoutWindowRows';
    public const string SOURCE_KEY = 'sourceFanoutWindowSource';

    /**
     * @param list<SourceFanoutWindowUnitRow> $rows Snapshot rows the table owns
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
     * @param AbstractTableRow $row Viewport row
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
     * Configures the row class so makeRows rebuilds typed rows from the filter output.
     */
    protected function init(): void
    {
        $this->setRowClass(SourceFanoutWindowUnitRow::class);
    }

    /**
     * Applies the in-memory filter to the injected rows.
     *
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Windowed snapshot
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $rows = array_map(static fn(SourceFanoutWindowUnitRow $row): array => $row->toArray(), $this->rows);

        return InMemoryTableFilter::apply($rows, $query);
    }
}

final class SourceFanoutWindowUnitRow extends AbstractTableRow
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
            (string) $data['key'],
            (string) $data['label'],
        );
    }
}
