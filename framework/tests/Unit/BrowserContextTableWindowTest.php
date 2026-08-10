<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

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
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
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
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
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
        new TableWindowUnitBrowserContext()->sendTableWindow(
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
                        PagePayload::rowKey => 'b',
                        PagePayload::slots => [
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

        new TableWindowGuardUnitBrowserContext()->sendTableWindow(
            TableWindowGuardUnitBrowserContext::PAGE,
            'ak-1',
            new TableViewportSubscription(tableKey: TableWindowUnitTable::TABLE, offset: 0, limit: 10),
        );

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
            new TableViewportSubscription(tableKey: TableWindowUnitTable::TABLE, offset: 0, limit: 10),
        );

        $this->assertNull(
            Hilos::$sr->getNextQueuedSignal(),
            'a broken declaration must receive no table window',
        );
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
            (string) $data['key'],
            (string) $data['label'],
        );
    }
}
