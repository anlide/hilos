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
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\PageInternalErrorException;
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
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BrowserContext::answerTableRowFocus, the answer to a tab's focus on a row of a table (HIL-1050).
 *
 * The focus is recorded before the answer and answered only when the tab's window does not hold
 * the row: the window's own frames carry a row it holds, while a row that left it - while the focus
 * frame was in flight, or while the connection was down - has to be handed over here, as the frame
 * that took it out, carrying its body. The table's own set is the boundary of what is handed over.
 */
final class BrowserContextTableRowFocusTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-1';

    public function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$table = null;

        parent::tearDown();
    }

    public function testARowTheWindowHoldsIsAnsweredWithNothing(): void
    {
        $context = $this->boot([new RowFocusUnitRow('a', 'Alpha'), new RowFocusUnitRow('b', 'Beta')], ['a', 'b']);
        Hilos::$sr?->setTableFocus(self::ACCEPT_KEY, RowFocusUnitTable::TABLE, 'a');

        $context->answerTableRowFocus(RowFocusUnitBrowserContext::PAGE, self::ACCEPT_KEY, RowFocusUnitTable::TABLE, 'a');

        // The window's own frames carry it; a second road for the same row would say things twice.
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        $this->assertSame('a', Hilos::$sr?->getTableFocus(self::ACCEPT_KEY, RowFocusUnitTable::TABLE));
    }

    public function testARowOutsideTheWindowIsAnsweredWithItsBody(): void
    {
        $context = $this->boot([new RowFocusUnitRow('a', 'Alpha'), new RowFocusUnitRow('b', 'Beta')], ['a']);
        Hilos::$sr?->setTableFocus(self::ACCEPT_KEY, RowFocusUnitTable::TABLE, 'b');

        $context->answerTableRowFocus(RowFocusUnitBrowserContext::PAGE, self::ACCEPT_KEY, RowFocusUnitTable::TABLE, 'b');

        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $delta->kind);
        $this->assertSame(TableViewportDeltaDTO::REASON_MOVED_OUT, $delta->reason);
        $this->assertSame('b', $delta->rowKey);
        $this->assertSame(
            [
                PagePayload::rowKey => 'b',
                PagePayload::slots => [RowFocusUnitTable::SLOT => ['key' => 'b', 'label' => 'Beta']],
            ],
            $delta->row,
        );
        $this->assertFalse($delta->own);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        $this->assertSame('b', Hilos::$sr?->getTableFocus(self::ACCEPT_KEY, RowFocusUnitTable::TABLE));
    }

    /**
     * The reason is the window's: a narrowed set that has let the row go says so, as a change would.
     */
    public function testARowOutsideANarrowedWindowsSetIsAnsweredAsLeftSet(): void
    {
        $context = $this->boot(
            [new RowFocusUnitRow('a', 'Alpha'), new RowFocusUnitRow('b', 'Beta')],
            ['a'],
            filter: ['label' => 'Alpha'],
            inSet: false,
        );
        Hilos::$sr?->setTableFocus(self::ACCEPT_KEY, RowFocusUnitTable::TABLE, 'b');

        $context->answerTableRowFocus(RowFocusUnitBrowserContext::PAGE, self::ACCEPT_KEY, RowFocusUnitTable::TABLE, 'b');

        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::REASON_LEFT_SET, $delta->reason);
        $this->assertNotNull($delta->row);
    }

    public function testARowTheOwnSetDoesNotHoldIsAnsweredWithoutABodyAndLetGoOf(): void
    {
        $context = $this->boot([new RowFocusUnitRow('a', 'Alpha')], ['a']);
        Hilos::$sr?->setTableFocus(self::ACCEPT_KEY, RowFocusUnitTable::TABLE, 'gone');

        $context->answerTableRowFocus(RowFocusUnitBrowserContext::PAGE, self::ACCEPT_KEY, RowFocusUnitTable::TABLE, 'gone');

        // Past the table's own set the tab may read nothing: the dialog is told the row is gone.
        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $delta->kind);
        $this->assertSame(TableViewportDeltaDTO::REASON_LEFT_SET, $delta->reason);
        $this->assertSame('gone', $delta->rowKey);
        $this->assertNull($delta->row);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        $this->assertNull(Hilos::$sr?->getTableFocus(self::ACCEPT_KEY, RowFocusUnitTable::TABLE));
    }

    public function testARowTheTableCannotReadIsAnsweredWithNothingAndStaysInFocus(): void
    {
        $context = $this->boot([new RowFocusUnitRow('a', 'Alpha')], ['a'], readRefuses: true);
        Hilos::$sr?->setTableFocus(self::ACCEPT_KEY, RowFocusUnitTable::TABLE, 'b');

        $context->answerTableRowFocus(RowFocusUnitBrowserContext::PAGE, self::ACCEPT_KEY, RowFocusUnitTable::TABLE, 'b');

        // The refusal says nothing about the row: the next change puts the question again, and a
        // focus let go of here would leave the dialog on a body nobody refreshes. The log says it happened.
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        $this->assertSame('b', Hilos::$sr?->getTableFocus(self::ACCEPT_KEY, RowFocusUnitTable::TABLE));
    }

    public function testAConnectionWithNoWindowForTheTableIsAnsweredWithNothing(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new RowFocusUnitTableContext([new RowFocusUnitRow('a', 'Alpha')]);
        Hilos::$table->configure();
        Hilos::$sr->setTableFocus(self::ACCEPT_KEY, RowFocusUnitTable::TABLE, 'a');

        new RowFocusUnitBrowserContext()->answerTableRowFocus(
            RowFocusUnitBrowserContext::PAGE,
            self::ACCEPT_KEY,
            RowFocusUnitTable::TABLE,
            'a',
        );

        // The first window of the table will carry the row, or the focus re-sent with it will ask again.
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testASubscriptionThePageGuardsRefuseIsHandedNoRow(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new RowFocusUnitTableContext([new RowFocusUnitRow('a', 'Alpha'), new RowFocusUnitRow('b', 'Beta')]);
        Hilos::$table->configure();
        // The guarded page's DB_EXISTS guard cannot resolve resource '1' (its source is absent), so
        // the page guard fails; the row must not be handed over even though the focus is valid.
        Hilos::$sr->subscribeToPage(
            RowFocusGuardUnitBrowserContext::PAGE,
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, RowFocusGuardUnitBrowserContext::PAGE, ['id' => '1']),
        );
        $viewport = new TableViewportSubscription(tableKey: RowFocusUnitTable::TABLE, limit: 10);
        $viewport->recordWindow(self::windowOf(['a']), 2, true, null, null);
        Hilos::$sr->setTableViewport(self::ACCEPT_KEY, $viewport);
        Hilos::$sr->setTableFocus(self::ACCEPT_KEY, RowFocusUnitTable::TABLE, 'b');

        new RowFocusGuardUnitBrowserContext()->answerTableRowFocus(
            RowFocusGuardUnitBrowserContext::PAGE,
            self::ACCEPT_KEY,
            RowFocusUnitTable::TABLE,
            'b',
        );

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * Boots the router, the fixture table, a page subscription and a window holding the given rows.
     *
     * @param list<RowFocusUnitRow> $rows Rows the table owns
     * @param list<string> $windowRowIds Row keys the connection's window holds
     * @param array<string, mixed> $filter Filter map narrowing the window's set
     * @param ?bool $inSet What the table answers about a row's membership, or null when it cannot say
     * @param bool $readRefuses Whether the table refuses to read its rows
     * @return RowFocusUnitBrowserContext Booted browser context
     */
    private function boot(
        array $rows,
        array $windowRowIds,
        array $filter = [],
        ?bool $inSet = null,
        bool $readRefuses = false,
    ): RowFocusUnitBrowserContext {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new RowFocusUnitTableContext($rows, $inSet, $readRefuses);
        Hilos::$table->configure();
        Hilos::$sr->subscribeToPage(
            RowFocusUnitBrowserContext::PAGE,
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, RowFocusUnitBrowserContext::PAGE),
        );
        $viewport = new TableViewportSubscription(tableKey: RowFocusUnitTable::TABLE, filter: $filter, limit: 10);
        $viewport->recordWindow(self::windowOf($windowRowIds), count($rows), true, null, null);
        Hilos::$sr->setTableViewport(self::ACCEPT_KEY, $viewport);

        return new RowFocusUnitBrowserContext();
    }

    /**
     * Turns a list of row keys into a window of placeholder wire rows.
     *
     * @param list<string> $rowIds Row keys the connection holds, in display order
     * @return array<string, array{rowKey: int|string, slots: array<string, mixed>}> Window of placeholder rows
     */
    private static function windowOf(array $rowIds): array
    {
        $window = [];
        foreach ($rowIds as $rowId) {
            $window[$rowId] = [PagePayload::rowKey => $rowId, PagePayload::slots => []];
        }

        return $window;
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
        $this->assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
        $this->assertInstanceOf(TableViewportDeltaDTO::class, $signal->data->data);

        return $signal->data->data;
    }
}

final class RowFocusUnitBrowserContext extends BrowserContext
{
    public const string PAGE = 'row_focus_unit_page';
}

final class RowFocusGuardUnitBrowserContext extends BrowserContext
{
    public const string PAGE = 'row_focus_guard_unit_page';
    public const string SIGNAL = 'row_focus_guard_unit_signal';

    /**
     * Resolves a guarded page config whose DB_EXISTS guard always fails (its source is absent).
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

final class RowFocusUnitTableContext extends TableContext
{
    /**
     * @param list<RowFocusUnitRow> $rows Snapshot rows the table owns
     * @param ?bool $inSet What the table answers about a row's membership, or null when it cannot say
     * @param bool $readRefuses Whether the table refuses to read its rows
     */
    public function __construct(
        private readonly array $rows = [],
        private readonly ?bool $inSet = null,
        private readonly bool $readRefuses = false,
    ) {
    }

    public function configure(): void
    {
        $this->register(RowFocusUnitTable::TABLE, new RowFocusUnitTable($this->rows, $this->inSet, $this->readRefuses));
    }
}

final class RowFocusUnitTable extends TableDefinition implements SelfSnapshotTable
{
    public const string TABLE = 'rowFocusUnitTable';
    public const string SLOT = 'rowFocusUnitRows';

    /**
     * @param list<RowFocusUnitRow> $rows Snapshot rows the table owns
     * @param ?bool $inSet What this table answers about a row's membership, or null when it cannot say
     * @param bool $readRefuses Whether this table refuses to read its rows
     */
    public function __construct(
        private readonly array $rows = [],
        private readonly ?bool $inSet = null,
        private readonly bool $readRefuses = false,
    ) {
        parent::__construct();
    }

    /**
     * Answers whether one row belongs to the set, from what the fixture was told to say.
     *
     * @param string|int $rowKey Row key to place against the set (ignored: the fixture states the answer)
     * @param TableQueryDTO $query Window query whose search and filters describe the set
     * @return ?bool Whether the row is in the set, or null when this table cannot answer
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return $this->inSet;
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
        $this->setRowClass(RowFocusUnitRow::class);
    }

    /**
     * Applies the in-memory filter to the injected rows, or refuses the read.
     *
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Windowed snapshot
     * @throws InvalidFormatException When the fixture was told to refuse the read
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        if ($this->readRefuses) {
            throw new InvalidFormatException('This table cannot read its rows');
        }

        $rows = array_map(static fn(RowFocusUnitRow $row): array => $row->toArray(), $this->rows);

        return $this->filterInMemory($rows, $query);
    }
}

final class RowFocusUnitRow extends AbstractTableRow
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
