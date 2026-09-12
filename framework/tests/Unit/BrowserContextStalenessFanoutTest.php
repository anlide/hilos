<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Hilos;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * How a source going quiet reaches the tables built on it (HIL-800).
 *
 * The freezing itself is delivered to the worker by HIL-711; what is pinned here is the step
 * after it — turning "these rows of this collection stopped moving" into something addressed
 * to the connections that are looking at them.
 *
 * The two kinds of subscriber are told in the two different ways they have to be. A window
 * gets a delta of its own kind, carrying the list and no row: the values did not move, and
 * either of the deltas that already exist would buy the mark at a price the ticket forbids —
 * one waits behind the Apply gate, leaving a frozen number looking fresh until it is pressed,
 * and the other resolves everything the reader has not accepted yet. A subscription with no
 * window has no gate at all, so its row simply goes out whole with the list inside it.
 */
final class BrowserContextStalenessFanoutTest extends TestCase
{
    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new StaleFanoutRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->addRow(StaleFanoutState::create('a', 'Alpha'));
        Hilos::$rt->addRow(StaleFanoutState::create('b', 'Beta'));
    }

    protected function tearDown(): void
    {
        RtStaleness::reset();
        Hilos::$sr = null;
        Hilos::$rt = null;
        Hilos::$table = null;
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testAWindowIsToldWhichOfItsRowsWentQuiet(): void
    {
        $context = $this->bootWindow(['a']);
        RtStaleness::mark(StaleFanoutRtContext::ROWS, ['a'], 1000.0);

        $context->recordSourceStaleness(StaleFanoutRtContext::ROWS, ['a']);
        $context->flushToSignalRouter();

        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_STALE, $delta->kind);
        $this->assertSame('a', $delta->rowKey);
        $this->assertSame([StaleFanoutViewportTable::SLOT], $delta->staleSources);
        $this->assertNull($delta->row, 'the values did not move, so none are sent');
        $this->assertFalse($delta->own, 'nothing the reader has queued may be resolved by this');
    }

    public function testARowTheWindowIsNotShowingIsNotMentionedToIt(): void
    {
        $context = $this->bootWindow(['a']);
        RtStaleness::mark(StaleFanoutRtContext::ROWS, ['b'], 1000.0);

        $context->recordSourceStaleness(StaleFanoutRtContext::ROWS, ['b']);
        $context->flushToSignalRouter();

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /**
     * A thaw travels the same road: the list is the whole answer for the row, so an empty one
     * is how the mark comes off.
     */
    public function testAThawIsTheSameFrameCarryingAnEmptyList(): void
    {
        $context = $this->bootWindow(['a']);

        $context->recordSourceStaleness(StaleFanoutRtContext::ROWS, ['a']);
        $context->flushToSignalRouter();

        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_STALE, $delta->kind);
        $this->assertSame([], $delta->staleSources);
    }

    public function testAViewportTableWithNoOpenWindowIsToldNothing(): void
    {
        $context = $this->bootWindow([]);
        RtStaleness::mark(StaleFanoutRtContext::ROWS, ['a'], 1000.0);

        $context->recordSourceStaleness(StaleFanoutRtContext::ROWS, ['a']);
        $context->flushToSignalRouter();

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /**
     * The declarative half, and the trigger list is the point of it: those fields say which
     * changes of the source rebuild the row, and freshness is not one of them. Asked, they
     * would answer no every time and the mark would never leave the worker.
     */
    public function testASubscriptionWithoutAWindowGetsTheWholeRowInstead(): void
    {
        Hilos::$sr?->subscribeToPage(
            StaleFanoutDeclarativeContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-2', StaleFanoutDeclarativeContext::PAGE),
        );
        RtStaleness::mark(StaleFanoutRtContext::ROWS, ['a'], 1000.0);

        $context = new StaleFanoutDeclarativeContext();
        $context->recordSourceStaleness(StaleFanoutRtContext::ROWS, ['a']);
        $context->flushToSignalRouter();

        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-2', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertSame(
            [
                PageResponseSignalData::page => StaleFanoutDeclarativeContext::PAGE,
                PageResponseSignalData::payload => [
                    PagePayload::tables => [
                        StaleFanoutDeclarativeContext::TABLE => [
                            PagePayload::rows => [
                                [
                                    PagePayload::rowKey => 'a',
                                    PagePayload::slots => [
                                        StaleFanoutRtContext::ROWS => ['id' => 'a', 'name' => 'Alpha'],
                                    ],
                                    PagePayload::staleSources => [StaleFanoutRtContext::ROWS],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            $signal->data->data->toArray(),
        );
    }

    /**
     * Boots the windowed page: one subscriber whose viewport has been served the given rows.
     *
     * @param list<string> $deliveredRowKeys Row keys the window has already delivered
     * @return StaleFanoutWindowContext Browser context bound to the windowed page
     */
    private function bootWindow(array $deliveredRowKeys): StaleFanoutWindowContext
    {
        Hilos::$table = new StaleFanoutTableContext();
        Hilos::$table->configure();
        Hilos::$sr?->subscribeToPage(
            StaleFanoutWindowContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-1', StaleFanoutWindowContext::PAGE),
        );

        if ($deliveredRowKeys !== []) {
            $viewport = new TableViewportSubscription(tableKey: StaleFanoutViewportTable::TABLE, limit: 10);
            $wireRows = [];
            foreach ($deliveredRowKeys as $rowKey) {
                $wireRows[$rowKey] = [
                    PagePayload::rowKey => $rowKey,
                    PagePayload::slots => [StaleFanoutViewportTable::SLOT => ['id' => $rowKey]],
                ];
            }
            $viewport->recordWindow($wireRows, count($wireRows), true, null, null);
            Hilos::$sr?->setTableViewport('ak-1', $viewport);
        }

        return new StaleFanoutWindowContext();
    }

    /**
     * Asserts the next queued signal is an addressed viewport delta and returns it.
     *
     * @return TableViewportDeltaDTO The delta payload
     */
    private function nextDelta(): TableViewportDeltaDTO
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::TABLE_VIEWPORT_DELTA, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(TableViewportDeltaDTO::class, $signal->data->data);

        return $signal->data->data;
    }
}

/**
 * A page bound to the windowed table and to nothing else.
 */
final class StaleFanoutWindowContext extends BrowserContext
{
    public const string PAGE = 'stale_fanout_window_page';
    public const string SIGNAL = 'stale_fanout_window_signal';

    /**
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Test page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if ($page !== self::PAGE) {
            return null;
        }

        return BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => self::SIGNAL]);
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Test page table bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        if ($page !== self::PAGE) {
            return BrowserPageBindings::empty();
        }

        return BrowserPageBindings::fromArray([StaleFanoutViewportTable::TABLE => []]);
    }
}

/**
 * A page whose table is declarative: it has no window, and its rows ride page_response.
 */
final class StaleFanoutDeclarativeContext extends BrowserContext
{
    public const string PAGE = 'stale_fanout_declarative_page';
    public const string SIGNAL = 'stale_fanout_declarative_signal';
    public const string TABLE = 'staleFanoutDeclarativeRows';

    /**
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Test page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if ($page !== self::PAGE) {
            return null;
        }

        return BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => self::SIGNAL]);
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Test page table bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        if ($page !== self::PAGE) {
            return BrowserPageBindings::empty();
        }

        return BrowserPageBindings::fromArray([self::TABLE => []]);
    }

    /**
     * Declares the table with a trigger list, which the freshness fan-out must ignore.
     *
     * @param string $browserKey Browser table key
     * @return ?BrowserSourceConfig Test browser-only table config
     */
    protected function resolveBrowserOnlyConfig(string $browserKey): ?BrowserSourceConfig
    {
        if ($browserKey !== self::TABLE) {
            return null;
        }

        return BrowserSourceConfig::fromArray([
            BrowserTableConfigKey::ROWS => [
                [
                    BrowserTableFieldKey::SOURCE => [
                        BrowserSourceKey::TYPE => BrowserSourceType::RT,
                        BrowserSourceKey::KEY => StaleFanoutRtContext::ROWS,
                    ],
                    BrowserTableFieldKey::ROW_KEY => 'id',
                    BrowserTableFieldKey::FIELDS => ['id', 'name'],
                    BrowserTableFieldKey::TRIGGERS => ['name'],
                ],
            ],
        ]);
    }
}

final class StaleFanoutTableContext extends TableContext
{
    public function configure(): void
    {
        $this->register(StaleFanoutViewportTable::TABLE, new StaleFanoutViewportTable());
    }
}

/**
 * A windowed table over the same runtime collection, naming its own frozen slot.
 */
final class StaleFanoutViewportTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'staleFanoutWindowRows';
    public const string SLOT = 'staleFanoutSlot';

    /**
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Row mutation, or null for another source
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== StaleFanoutRtContext::ROWS) {
            return null;
        }

        return $this->mutation($change->mutationType, $change->sourceId, new StaleFanoutRow($change->sourceId));
    }

    /**
     * @param AbstractTableRow $row Viewport row
     * @return array{rowKey: int|string, sources: array<string, mixed>, staleSources?: list<string>} Envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        $rowKey = $row->requireRowKey();
        $browserRow = [
            BrowserPageSignalData::rowKey => $rowKey,
            BrowserPageSignalData::sources => [self::SLOT => $row->toArray()],
        ];
        if (RtStaleness::staleSince(StaleFanoutRtContext::ROWS, (string) $rowKey) !== null) {
            $browserRow[BrowserPageSignalData::staleSources] = [self::SLOT];
        }

        return $browserRow;
    }

    protected function init(): void
    {
        $this->setRowClass(StaleFanoutRow::class);
    }

    /**
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Windowed snapshot
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory([[StaleFanoutRow::id => 'a'], [StaleFanoutRow::id => 'b']], $query);
    }
}

final class StaleFanoutRow extends AbstractTableRow
{
    public const string id = 'id';

    public function __construct(public readonly string $id)
    {
    }

    public function getRowKey(): string
    {
        return $this->id;
    }

    /**
     * @return string Payload key the row key travels under
     */
    public static function keyField(): string
    {
        return self::id;
    }

    /**
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return [self::id => $this->id];
    }

    /**
     * @param array<string, mixed> $data Raw row payload
     * @return static Restored row
     * @throws InvalidFormatException When the payload carries no row id
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, self::id));
    }
}

final class StaleFanoutRtContext extends RtContext
{
    public const string ROWS = 'staleFanoutRows';

    public function configure(): void
    {
        $this->_stateCollections[self::ROWS] = StaleFanoutStates::init();
        $this->setRepresent(self::ROWS, StaleFanoutCollection::class);
    }

    public function addRow(StaleFanoutState $row): void
    {
        $this->_stateCollections[self::ROWS]->add($row);
    }
}

final class StaleFanoutStates extends RtStates
{
    public const string STATE_CLASS = StaleFanoutState::class;
}

final class StaleFanoutState extends RtState
{
    private function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {
        parent::__construct();
    }

    public static function create(string $id, string $name): self
    {
        return new self($id, $name);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public static function fromRow(array $row): static
    {
        return new static((string) $row['id'], (string) $row['name']);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}

final class StaleFanoutCollection extends RtCollection
{
    protected function createRtItem(RtState $state): RtItem
    {
        return new StaleFanoutItem($state);
    }
}

final class StaleFanoutItem extends RtItem
{
    public function __get(string $name): mixed
    {
        $data = $this->toArray();

        return $data[$name] ?? parent::__get($name);
    }

    public function toArray(): array
    {
        return $this->getState()->toArray();
    }
}
