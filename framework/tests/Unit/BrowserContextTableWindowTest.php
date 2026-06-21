<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
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
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BrowserContext::sendTableWindow (the self-snapshot window builder).
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

        $viewport = new TableViewportSubscription(tableKey: TableWindowUnitTable::TABLE, offset: 1, limit: 1);
        (new TableWindowUnitBrowserContext())->sendTableWindow(
            TableWindowUnitBrowserContext::PAGE,
            'ak-1',
            $viewport,
        );

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
                        BrowserPageSignalData::rowKey => 'b',
                        BrowserPageSignalData::sources => [
                            TableWindowUnitTable::SLOT => ['key' => 'b', 'label' => 'Beta'],
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

    public function testSendTableWindowIgnoresAMissingTable(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new TableWindowUnitTableContext([]);
        Hilos::$table->configure();

        (new TableWindowUnitBrowserContext())->sendTableWindow(
            TableWindowUnitBrowserContext::PAGE,
            'ak-1',
            new TableViewportSubscription(tableKey: 'no_such_table'),
        );

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }
}

final class TableWindowUnitBrowserContext extends BrowserContext
{
    public const string PAGE = 'table_window_unit_page';
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
    }
}

final class TableWindowUnitTable extends TableDefinition implements SelfSnapshotTable
{
    public const string TABLE = 'windowUnitTable';
    public const string SLOT = 'windowUnitRows';

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

        return InMemoryTableFilter::apply($rows, $query);
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
