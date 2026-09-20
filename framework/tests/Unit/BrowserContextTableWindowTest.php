<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserSubscriptionError;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableFacetCountsSignalData;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableFacetTally;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BrowserContext::sendTableWindow, the viewport-request road to a window.
 *
 * The build itself is shared with the page subscription's own road since HIL-642, so what these
 * pin is this road's share of it: the guards it re-checks, the page it re-sends when a delivery
 * failure clears, and the table_window frame it answers with. The other road is pinned by
 * {@see BrowserContextSubscribeWindowTest}.
 */
final class BrowserContextTableWindowTest extends TestCase
{
    public function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$table = null;

        parent::tearDown();
    }

    public function testSendTableWindowQueuesTheWindowedSnapshotAndRecordsRowIds(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new TableWindowUnitTableContext([
            new TableWindowUnitRow('a', 'Alpha'),
            new TableWindowUnitRow('b', 'Beta'),
            new TableWindowUnitRow('c', 'Gamma'),
        ]);
        Hilos::$table->configure();

        $viewport = new TableViewportSubscription(tableKey: TableWindowUnitTable::TABLE, limit: 1, anchor: new TableAnchorDTO(['key' => 'a']));
        $delivered = new TableWindowUnitBrowserContext()->sendTableWindow(
            TableWindowUnitBrowserContext::PAGE,
            'ak-1',
            $viewport,
        );

        $this->assertTrue($delivered);
        $signal = Hilos::$sr->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        $this->assertSame(SignalTypeConstants::TABLE_WINDOW, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(TableWindowSignalData::class, $signal->data->data);
        $this->assertSame(
            [
                TableWindowSignalData::page => TableWindowUnitBrowserContext::PAGE,
                TableWindowSignalData::tableKey => TableWindowUnitTable::TABLE,
                TableWindowSignalData::rows => [
                    [
                        PagePayload::rowKey => 'b',
                        PagePayload::slots => [
                            TableWindowUnitTable::SLOT => ['key' => 'b', 'label' => 'Beta'],
                        ],
                    ],
                ],
                TableWindowSignalData::totalCount => 3,
                TableWindowSignalData::totalExact => true,
                TableWindowSignalData::limit => 1,
                TableWindowSignalData::firstAnchor => ['key' => 'b'],
                TableWindowSignalData::lastAnchor => ['key' => 'b'],
                TableWindowSignalData::rowsBefore => 1,
            ],
            $signal->data->data->toArray(),
        );

        $this->assertSame(['b'], $viewport->rowIds());
        $this->assertSame(3, $viewport->totalCount());
    }

    public function testAWindowAskedForPastTheEndOfTheSetSaysTheWholeSetStandsBeforeIt(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new TableWindowUnitTableContext([
            new TableWindowUnitRow('a', 'Alpha'),
            new TableWindowUnitRow('b', 'Beta'),
            new TableWindowUnitRow('c', 'Gamma'),
        ]);
        Hilos::$table->configure();

        new TableWindowUnitBrowserContext()->sendTableWindow(
            TableWindowUnitBrowserContext::PAGE,
            'ak-1',
            new TableViewportSubscription(tableKey: TableWindowUnitTable::TABLE, limit: 2, anchor: new TableAnchorDTO(['key' => 'c'])),
        );

        $signal = Hilos::$sr->getNextQueuedSignal();

        $this->assertInstanceOf(WebSocketSignalData::class, $signal?->data);
        $this->assertInstanceOf(TableWindowSignalData::class, $signal->data->data);
        $this->assertSame([], $signal->data->data->rows);
        $this->assertSame(3, $signal->data->data->rowsBefore);
    }

    public function testSendTableWindowIgnoresAMissingTable(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new TableWindowUnitTableContext([]);
        Hilos::$table->configure();

        new TableWindowUnitBrowserContext()->sendTableWindow(
            TableWindowUnitBrowserContext::PAGE,
            'ak-1',
            new TableViewportSubscription(tableKey: 'no_such_table'),
        );

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testSendTableWindowSkipsGuardFailedSubscription(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new TableWindowUnitTableContext([
            new TableWindowUnitRow('a', 'Alpha'),
        ]);
        Hilos::$table->configure();
        // The guarded page's DB_EXISTS guard cannot resolve resource '1' (its source
        // is absent), so the page guard fails; the window must not be served even
        // though the viewport descriptor is valid.
        Hilos::$sr->subscribeToPage(
            TableWindowGuardUnitBrowserContext::PAGE,
            new WebSocketPageSubscribeSignalDTO(
                'ak-1',
                TableWindowGuardUnitBrowserContext::PAGE,
                ['id' => '1'],
            ),
        );

        $delivered = new TableWindowGuardUnitBrowserContext()->sendTableWindow(
            TableWindowGuardUnitBrowserContext::PAGE,
            'ak-1',
            new TableViewportSubscription(tableKey: TableWindowUnitTable::TABLE, limit: 10),
        );

        // The refusal is answered as well as silent on the wire: this is what the
        // dispatcher turns into the log line the door never used to leave (HIL-599).
        $this->assertFalse($delivered);
        $this->assertNull(
            Hilos::$sr->getNextQueuedSignal(),
            'a guard-failed subscription must receive no table window',
        );
    }

    public function testSendTableWindowSkipsABrokenDeclarationInsteadOfLettingItEscape(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new TableWindowUnitTableContext([
            new TableWindowUnitRow('a', 'Alpha'),
        ]);
        Hilos::$table->configure();
        Hilos::$sr->subscribeToPage(
            TableWindowBrokenDeclarationBrowserContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-1', TableWindowBrokenDeclarationBrowserContext::PAGE),
        );

        // This path is dispatched bare — PageSignalRouter::dispatchTableViewport and the
        // TABLE_VIEWPORT case in WorkerManager both call straight through — so a throw
        // escaping here would reach the worker's exit and crash-loop it on every window
        // request, exactly as it would on the reactive fan-out.
        new TableWindowBrokenDeclarationBrowserContext()->sendTableWindow(
            TableWindowBrokenDeclarationBrowserContext::PAGE,
            'ak-1',
            new TableViewportSubscription(tableKey: TableWindowUnitTable::TABLE, limit: 10),
        );

        // No window, and — since HIL-575 — not silence either: the connection is told once
        // that its page could not be delivered, because a window that never comes is otherwise
        // indistinguishable from a page with nothing new on it.
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $signal->data->data);
        $this->assertSame(500, $signal->data->data->httpCode);
        $this->assertSame('Internal error while delivering the page', $signal->data->data->message);
        $this->assertNull(
            Hilos::$sr->getNextQueuedSignal(),
            'a broken declaration must receive no table window',
        );
    }

    public function testSendTableWindowNamesTheTableWhoseRowRefusedItsOwnPayload(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new TableWindowUnitTableContext([]);
        Hilos::$table->configure();

        // A row class refusing a payload it cannot be built from lands in the same
        // catch a broken declaration does, and that catch answers by not sending.
        // Without the log line the connection would simply never receive its window
        // and nothing anywhere would say why.
        ob_start();
        new TableWindowUnitBrowserContext()->sendTableWindow(
            TableWindowUnitBrowserContext::PAGE,
            'ak-1',
            new TableViewportSubscription(tableKey: TableWindowRefusedRowTable::TABLE, limit: 10),
        );
        $logged = (string)ob_get_clean();

        $this->assertNull(
            Hilos::$sr->getNextQueuedSignal(),
            'a refused row must receive no table window',
        );
        $this->assertStringContainsString(TableWindowRefusedRowTable::TABLE, $logged);
        $this->assertStringContainsString(TableWindowUnitBrowserContext::PAGE, $logged);
        $this->assertStringContainsString('label', $logged);
    }

    public function testTheCountsGoToTheConnectionThatAskedInAFrameOfTheirOwn(): void
    {
        $this->mountLabelledRows();
        Hilos::$sr->setTableFacets('ak-1', TableWindowUnitTable::TABLE, [
            TableWindowUnitTable::FILTER_LABEL => ['Alpha', 'Beta', 'Gamma'],
        ]);

        new TableWindowUnitBrowserContext()->sendTableFacetCounts(
            TableWindowUnitBrowserContext::PAGE,
            'ak-1',
            new TableViewportSubscription(
                tableKey: TableWindowUnitTable::TABLE,
                filter: [TableWindowUnitTable::FILTER_LABEL => 'Beta'],
                limit: 1,
            ),
        );

        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        $this->assertSame(SignalTypeConstants::TABLE_FACET_COUNTS, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $frame = $signal->data->data;
        $this->assertInstanceOf(TableFacetCountsSignalData::class, $frame);
        $this->assertSame(TableWindowUnitBrowserContext::PAGE, $frame->page);
        $this->assertSame(TableWindowUnitTable::TABLE, $frame->tableKey);
        // Counted over the set with the label filter lifted, so picking Beta does not zero the others.
        $label = $frame->facets->filters[TableWindowUnitTable::FILTER_LABEL];
        $this->assertSame(3, $label[TableConstants::FACET_KEY_ANY]->count);
        $this->assertSame(1, $label[TableConstants::FACET_KEY_OPTIONS]['Alpha']->count);
        $this->assertSame(2, $label[TableConstants::FACET_KEY_OPTIONS]['Beta']->count);
        $this->assertSame(0, $label[TableConstants::FACET_KEY_OPTIONS]['Gamma']->count);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testOnlyTheFiltersNamedAreCountedAgain(): void
    {
        $this->mountLabelledRows();
        Hilos::$sr->setTableFacets('ak-1', TableWindowUnitTable::TABLE, [
            TableWindowUnitTable::FILTER_LABEL => ['Alpha'],
            TableWindowUnitTable::FILTER_REFUSED => ['x'],
        ]);

        // The refusing filter would fail the whole count; left out of the recount, it is never asked.
        new TableWindowUnitBrowserContext()->sendTableFacetCounts(
            TableWindowUnitBrowserContext::PAGE,
            'ak-1',
            new TableViewportSubscription(tableKey: TableWindowUnitTable::TABLE, limit: 1),
            [TableWindowUnitTable::FILTER_LABEL],
        );

        $data = Hilos::$sr->getNextQueuedSignal()?->data;
        $this->assertInstanceOf(WebSocketSignalData::class, $data);
        $this->assertInstanceOf(TableFacetCountsSignalData::class, $data->data);
        $this->assertSame([TableWindowUnitTable::FILTER_LABEL], array_keys($data->data->facets->filters));
    }

    public function testAConnectionThatAskedForNoCountsIsSentNone(): void
    {
        $this->mountLabelledRows();

        new TableWindowUnitBrowserContext()->sendTableFacetCounts(
            TableWindowUnitBrowserContext::PAGE,
            'ak-1',
            new TableViewportSubscription(tableKey: TableWindowUnitTable::TABLE, limit: 1),
        );

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testATableThatCannotCountIsSentNoFrame(): void
    {
        $this->mountLabelledRows();
        Hilos::$sr->setTableFacets('ak-1', TableWindowRefusedRowTable::TABLE, ['status' => ['failed']]);

        new TableWindowUnitBrowserContext()->sendTableFacetCounts(
            TableWindowUnitBrowserContext::PAGE,
            'ak-1',
            new TableViewportSubscription(tableKey: TableWindowRefusedRowTable::TABLE, limit: 1),
        );

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * A number beside an option is not worth the window it follows: a count that fails sends
     * nothing and stops here, rather than reaching the worker the frame was dispatched on.
     */
    public function testACountThatFailsSendsNoFrameAndStopsHere(): void
    {
        $this->mountLabelledRows();
        Hilos::$sr->setTableFacets('ak-1', TableWindowUnitTable::TABLE, [TableWindowUnitTable::FILTER_REFUSED => ['x']]);

        new TableWindowUnitBrowserContext()->sendTableFacetCounts(
            TableWindowUnitBrowserContext::PAGE,
            'ak-1',
            new TableViewportSubscription(tableKey: TableWindowUnitTable::TABLE, limit: 1),
        );

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * Mounts a router and the fixture tables over three rows, two of which share a label.
     */
    private function mountLabelledRows(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new TableWindowUnitTableContext([
            new TableWindowUnitRow('a', 'Alpha'),
            new TableWindowUnitRow('b', 'Beta'),
            new TableWindowUnitRow('c', 'Beta'),
        ]);
        Hilos::$table->configure();
    }
}

final class TableWindowUnitBrowserContext extends BrowserContext
{
    public const string PAGE = 'table_window_unit_page';
}

final class TableWindowBrokenDeclarationBrowserContext extends BrowserContext
{
    public const string PAGE = 'table_window_broken_declaration_page';

    /**
     * Resolves a page whose declaration names something that is not a signal.
     *
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if ($page !== self::PAGE) {
            return null;
        }

        return BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => ['not', 'a', 'name']]);
    }
}

final class TableWindowGuardUnitBrowserContext extends BrowserContext
{
    public const string PAGE = 'table_window_guard_unit_page';
    public const string SIGNAL = 'table_window_guard_unit_signal';

    /**
     * Resolves a guarded page config whose DB_EXISTS guard always fails (its source
     * is absent), so the page never delivers a window for it.
     *
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Guarded page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if ($page !== self::PAGE) {
            return null;
        }

        return BrowserPageConfig::fromArray([
            BrowserConfigKey::SIGNAL => self::SIGNAL,
            BrowserConfigKey::GUARDS => [
                [
                    BrowserGuardKey::TYPE => BrowserGuardType::DB_EXISTS,
                    BrowserGuardKey::SOURCE => [
                        BrowserSourceKey::TYPE => BrowserSourceType::RT,
                        BrowserSourceKey::KEY => 'no_such_source',
                    ],
                    BrowserGuardKey::KEY => [
                        BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM,
                        BrowserRefKey::KEY => 'id',
                    ],
                    BrowserGuardKey::ERROR => BrowserSubscriptionError::NOT_FOUND,
                ],
            ],
        ]);
    }
}

final class TableWindowUnitTableContext extends TableContext
{
    /**
     * @param list<TableWindowUnitRow> $rows Snapshot rows the table returns
     */
    public function __construct(private readonly array $rows = [])
    {
    }

    public function configure(): void
    {
        $this->register(TableWindowUnitTable::TABLE, new TableWindowUnitTable($this->rows));
        $this->register(TableWindowRefusedRowTable::TABLE, new TableWindowRefusedRowTable());
    }
}

final class TableWindowUnitTable extends TableDefinition implements SelfSnapshotTable
{
    public const string TABLE = 'windowUnitTable';
    public const string SLOT = 'windowUnitRows';

    /** Filter key the fixture counts its rows by. */
    public const string FILTER_LABEL = 'label';

    /** Filter key the fixture refuses to count by, standing in for a count that fails. */
    public const string FILTER_REFUSED = 'refused';

    /**
     * @param list<TableWindowUnitRow> $rows Snapshot rows the table owns
     */
    public function __construct(private readonly array $rows = [])
    {
        parent::__construct();
    }

    /**
     * No source-change reaction in this fixture.
     *
     * @param SourceChange $change Source change (unused)
     * @return ?TableRowMutationDTO Always null
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * Serializes a row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Self-snapshot row
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
     * Counts the injected rows by label, and refuses when asked about the refusing filter.
     *
     * @param TableQueryDTO $query Window query whose filters describe the set
     * @param array<string, list<int|float|string|bool>> $wanted Options to count, by filter key
     * @return array<string, array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>}> Counts by filter key
     * @throws InvalidFormatException When asked to count by the filter this fixture refuses
     */
    public function facetCounts(TableQueryDTO $query, array $wanted): ?array
    {
        if (array_key_exists(self::FILTER_REFUSED, $wanted)) {
            throw new InvalidFormatException('This table cannot count by that filter');
        }

        return TableFacetTally::forFilters(
            $query,
            array_intersect_key($wanted, [self::FILTER_LABEL => true]),
            fn(TableQueryDTO $set): TableFacetCountDTO => new TableFacetCountDTO(
                count(array_filter(
                    $this->rows,
                    static fn(TableWindowUnitRow $row): bool => !array_key_exists(self::FILTER_LABEL, $set->filter)
                        || $set->filter[self::FILTER_LABEL] === $row->label,
                )),
                true,
            ),
        );
    }

    /**
     * Configures the row class so makeRows rebuilds typed rows from the filter output.
     */
    protected function init(): void
    {
        $this->setRowClass(TableWindowUnitRow::class);
    }

    /**
     * Applies the in-memory filter to the injected rows.
     *
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Windowed snapshot
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $rows = array_map(static fn(TableWindowUnitRow $row): array => $row->toArray(), $this->rows);

        return $this->filterInMemory($rows, $query);
    }
}

final class TableWindowUnitRow extends AbstractTableRow
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
        return [
            'key' => $this->key,
            'label' => $this->label,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static Row instance
     * @throws InvalidFormatException When the payload is missing a field the row is built from
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, 'key'),
            self::requireString($data, 'label'),
        );
    }
}

/**
 * Table whose snapshot rows lost a field the row class is built from — what a row
 * constructor drifting from its own toArray() produces.
 */
final class TableWindowRefusedRowTable extends TableDefinition implements SelfSnapshotTable
{
    public const string TABLE = 'windowRefusedRowTable';
    public const string SLOT = 'windowRefusedRows';

    /**
     * No source-change reaction in this fixture.
     *
     * @param SourceChange $change Source change (unused)
     * @return ?TableRowMutationDTO Always null
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * Serializes a row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Self-snapshot row
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
        $this->setRowClass(TableWindowUnitRow::class);
    }

    /**
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Windowed snapshot of rows missing the label field
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory([['key' => 'a']], $query);
    }
}
