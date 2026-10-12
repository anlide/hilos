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
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\Definition\MultiRowViewportTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/** One source fact reaches several window-independent rows through ordinary viewport delivery. */
final class BrowserContextMultiRowTableTest extends TestCase
{
    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new MultiRowUnitTableContext();
        Hilos::$table->configure();
        foreach (['one', 'two'] as $acceptKey) {
            Hilos::$sr->subscribeToPage(
                MultiRowUnitBrowserContext::PAGE,
                new WebSocketPageSubscribeSignalDTO($acceptKey, MultiRowUnitBrowserContext::PAGE),
            );
            $this->window($acceptKey, ['a', 'b']);
        }
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$table = null;
        Hilos::resetBrowser();
        parent::tearDown();
    }

    public function testEveryMutationReachesBothWindowsWithoutAWholeWindowRefresh(): void
    {
        $context = new MultiRowUnitBrowserContext();
        $context->record(SourceChange::rtUpdated(MultiRowUnitTable::SOURCE, 'fact', ['label' => 'After']));
        $context->flushToSignalRouter();

        $deltas = $this->deltas();
        foreach (['one', 'two'] as $acceptKey) {
            self::assertCount(2, $deltas[$acceptKey]);
            self::assertSame(['a', 'b'], array_map(static fn ($delta) => $delta->rowKey, $deltas[$acceptKey]));
            foreach ($deltas[$acceptKey] as $delta) {
                self::assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta->kind);
                self::assertSame('After', $delta->row[PagePayload::slots][MultiRowUnitTable::SLOT]['label']);
            }
        }
    }

    public function testAFocusedRowOutsideTheWindowReceivesItsMutation(): void
    {
        $this->window('two', ['a']);
        Hilos::$sr->setTableFocus('two', MultiRowUnitTable::TABLE, 'b');
        $context = new MultiRowUnitBrowserContext();
        $context->record(SourceChange::rtUpdated(MultiRowUnitTable::SOURCE, 'fact', ['label' => 'Focused']));
        $context->flushToSignalRouter();

        $deltas = $this->deltas()['two'];
        self::assertSame(['a', 'b'], array_map(static fn ($delta) => $delta->rowKey, $deltas));
        self::assertSame('Focused', $deltas[1]->row[PagePayload::slots][MultiRowUnitTable::SLOT]['label']);
        self::assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $deltas[1]->kind);
        self::assertSame(TableViewportDeltaDTO::REASON_MOVED_OUT, $deltas[1]->reason);
    }

    public function testAnUnrelatedSourceEmitsNothing(): void
    {
        $context = new MultiRowUnitBrowserContext();
        $context->record(SourceChange::rtUpdated('unrelated', 'fact', ['label' => 'After']));
        $context->flushToSignalRouter();
        self::assertSame([], $this->deltas());
    }

    /**
     * @param string $acceptKey Subscribed connection
     * @param list<string> $keys Delivered row keys
     */
    private function window(string $acceptKey, array $keys): void
    {
        $viewport = new TableViewportSubscription(tableKey: MultiRowUnitTable::TABLE, limit: 10);
        $rows = [];
        foreach ($keys as $key) {
            $rows[$key] = [PagePayload::rowKey => $key, PagePayload::slots => []];
        }
        $viewport->recordWindow($rows, 2, true, null, null);
        Hilos::$sr->setTableViewport($acceptKey, $viewport);
    }

    /** @return array<string, list<TableViewportDeltaDTO>> Addressed deltas, excluding all whole-window frames */
    private function deltas(): array
    {
        $deltas = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertNotSame(SignalTypeConstants::TABLE_WINDOW, $signal->signalName->getName());
            self::assertNotSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
            if ($signal->signalName->getName() === SignalTypeConstants::TABLE_VIEWPORT_DELTA) {
                self::assertInstanceOf(TableViewportDeltaDTO::class, $signal->data->data);
                $deltas[$signal->data->targetAcceptKey][] = $signal->data->data;
            }
        }

        return $deltas;
    }
}

final class MultiRowUnitBrowserContext extends BrowserContext
{
    public const string PAGE = 'multi_row_page';

    /**
     * @param string $page Subscribed page
     * @return ?BrowserPageConfig Test page metadata
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        return $page === self::PAGE ? BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => 'multi_row_signal']) : null;
    }

    /**
     * @param string $page Subscribed page
     * @return BrowserPageBindings Test table binding
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return $page === self::PAGE
            ? BrowserPageBindings::fromArray([MultiRowUnitTable::TABLE => []])
            : BrowserPageBindings::empty();
    }
}

final class MultiRowUnitTableContext extends TableContext
{
    /** Registers the multi-row table. */
    public function configure(): void
    {
        $this->register(MultiRowUnitTable::TABLE, new MultiRowUnitTable());
    }
}

final class MultiRowUnitTable extends TableDefinition implements MultiRowViewportTable
{
    public const string TABLE = 'multiRowTable';
    public const string SOURCE = 'multiRowSource';
    public const string SLOT = 'multiRowSlot';

    /**
     * @param SourceChange $change Source fact
     * @return list<TableRowMutationDTO> Updates to a and b, independent of the window
     */
    public function buildMutationsForSourceEvent(SourceChange $change): array
    {
        if ($change->sourceKey !== self::SOURCE) {
            return [];
        }

        return array_map(
            fn (string $key): TableRowMutationDTO => $this->mutation(
                TableMutationType::Update,
                $key,
                new MultiRowUnitRow($key, $change->row['label']),
            ),
            ['a', 'b'],
        );
    }

    /**
     * @param AbstractTableRow $row Table row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Browser envelope
     * @throws TableRowKeyMissingException When the row lacks its key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::SLOT => $row->toArray()],
        ];
    }

    /**
     * @param int|string $rowKey Language-independent key
     * @param TableQueryDTO $query Window query
     * @return bool Whether the row belongs to this table
     */
    public function containsRow(int|string $rowKey, TableQueryDTO $query): bool
    {
        return in_array($rowKey, ['a', 'b'], true);
    }

    /** Configures the test row. */
    protected function init(): void
    {
        $this->setRowClass(MultiRowUnitRow::class);
    }

    /**
     * @param TableQueryDTO $query Window query
     * @return TableSnapshotDTO Current rows
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory([['key' => 'a', 'label' => 'After'], ['key' => 'b', 'label' => 'After']], $query);
    }
}

final class MultiRowUnitRow extends AbstractTableRow
{
    /**
     * @param string $key Row key
     * @param string $label Current label
     */
    public function __construct(public readonly string $key, public readonly string $label)
    {
    }

    /** @return string Row key */
    public function getRowKey(): string
    {
        return $this->key;
    }

    /** @return string Row key field */
    public static function keyField(): string
    {
        return 'key';
    }

    /** @return array<string, string> Serialized row */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label];
    }

    /**
     * @param array<string, mixed> $data Row payload
     * @return static Hydrated row
     */
    public static function fromArray(array $data): static
    {
        return new static($data['key'], $data['label']);
    }
}
