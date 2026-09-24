<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Tables\Communications;

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
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\DTO\TableViewportAnnounceDTO;
use Hilos\Core\Table\DTO\TableViewportCountDTO;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableRowPlacement;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Communications\HilosNotificationDeliveryTableRow;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the delivery journal living on the common window mechanism (HIL-1049).
 *
 * The journal is served from SQL, so its windows are recorded here the way its SQL writes them:
 * the boundaries in the columns of the source with the id as text, the places of the shown rows
 * named by the table itself. A change of the notificationDeliveries collection then reaches the
 * window through {@see BrowserContext} exactly as it reaches any other viewport table - no
 * journal-specific path, only the table's own mutation and places.
 */
final class HilosNotificationDeliveriesLiveTest extends TestCase
{
    public function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$table = null;

        parent::tearDown();
    }

    /**
     * A delivery newer than the first row of a newest-first window is announced above it, not put in.
     */
    public function testANewDeliveryAboveTheWindowIsAnnounced(): void
    {
        $shown = [self::delivery(12, '2026-09-23 10:00:02'), self::delivery(11, '2026-09-23 10:00:01')];
        $viewport = $this->boot([...$shown, self::delivery(13, '2026-09-23 10:00:03')], $shown);

        $this->record(SourceChange::dbCreated(HilosDbContext::notificationDeliveries, '13', []));

        $announce = $this->nextAnnounce();
        $this->assertSame(TableRowPlacement::Above, $announce->placement);
        $this->assertSame(3, $announce->totalCount);
        $this->assertFalse($viewport->hasRow('13'));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /**
     * A delivery made in the same second as the first row is settled by its id - as a number, though the
     * boundary carries it as text: delivery 10 stands above delivery 9, where text would put it below.
     */
    public function testANewDeliveryInTheSameSecondIsSettledByItsIdAsANumber(): void
    {
        $shown = [self::delivery(9, '2026-09-23 10:00:00'), self::delivery(8, '2026-09-23 09:59:59')];
        $this->boot([...$shown, self::delivery(10, '2026-09-23 10:00:00')], $shown);

        $this->record(SourceChange::dbCreated(HilosDbContext::notificationDeliveries, '10', []));

        $this->assertSame(TableRowPlacement::Above, $this->nextAnnounce()->placement);
    }

    /**
     * A shown delivery going from pending to sent is updated in place, carrying the row read again.
     */
    public function testAStatusChangeOfAShownDeliveryIsUpdatedInPlace(): void
    {
        $shown = [self::delivery(12, '2026-09-23 10:00:02'), self::delivery(11, '2026-09-23 10:00:01')];
        $this->boot([self::delivery(12, '2026-09-23 10:00:02', 'sent'), $shown[1]], $shown);

        $this->record(SourceChange::dbUpdated(HilosDbContext::notificationDeliveries, '12', ['status' => 'sent']));

        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta->kind);
        $this->assertFalse($delta->own);
        $this->assertSame('sent', $delta->row[PagePayload::slots]['delivery']['status'] ?? null);
        $this->assertSame('Welcome', $delta->row[PagePayload::slots]['delivery']['notificationTitle'] ?? null);
    }

    /**
     * The same change written for the connection that asked for it arrives as its own.
     */
    public function testAStatusChangeWrittenForTheViewerIsTheirOwn(): void
    {
        $shown = [self::delivery(12, '2026-09-23 10:00:02'), self::delivery(11, '2026-09-23 10:00:01')];
        $this->boot([self::delivery(12, '2026-09-23 10:00:02', 'sent'), $shown[1]], $shown);

        $this->record(SourceChange::dbUpdated(HilosDbContext::notificationDeliveries, '12', ['status' => 'sent'], 'ak-1'));

        $this->assertTrue($this->nextDelta()->own);
    }

    /**
     * A shown delivery deleted from the journal leaves the window.
     */
    public function testADeletedShownDeliveryIsRemoved(): void
    {
        $shown = [self::delivery(12, '2026-09-23 10:00:02'), self::delivery(11, '2026-09-23 10:00:01')];
        $viewport = $this->boot([$shown[1]], $shown);

        $this->record(SourceChange::dbDeleted(HilosDbContext::notificationDeliveries, '12'));

        $this->assertSame(1, $this->nextCount()->totalCount);
        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $delta->kind);
        $this->assertSame(TableViewportDeltaDTO::REASON_DELETED, $delta->reason);
        $this->assertFalse($viewport->hasRow('12'));
    }

    /**
     * A retried delivery in a window of failed ones is no longer failed, so it leaves that set.
     */
    public function testARetriedDeliveryLeavesAWindowOfFailedOnes(): void
    {
        $shown = [self::delivery(12, '2026-09-23 10:00:02', 'failed'), self::delivery(11, '2026-09-23 10:00:01', 'failed')];
        $this->boot(
            [self::delivery(12, '2026-09-23 10:00:02'), $shown[1]],
            $shown,
            [HilosNotificationDeliveriesTable::FILTER_STATUS => 'failed'],
        );

        $this->record(SourceChange::dbUpdated(HilosDbContext::notificationDeliveries, '12', ['status' => 'pending']));

        // Leaving the set is the one move that shrinks it, so the count goes first.
        $this->assertSame(1, $this->nextCount()->totalCount);
        $delta = $this->nextDelta();
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $delta->kind);
        $this->assertSame(TableViewportDeltaDTO::REASON_LEFT_SET, $delta->reason);
    }

    /**
     * Builds one delivery row of the journal.
     *
     * @param int $id Delivery id
     * @param string $createdAt Moment the delivery was created
     * @param string $status Delivery status
     * @return HilosNotificationDeliveryTableRow Delivery row
     */
    private static function delivery(int $id, string $createdAt, string $status = 'pending'): HilosNotificationDeliveryTableRow
    {
        return new HilosNotificationDeliveryTableRow(
            rowKey: $id,
            createdAt: $createdAt,
            channel: 'email',
            status: $status,
            attempts: 1,
            deliveredAt: $status === 'sent' ? $createdAt : null,
            lastError: null,
            userId: 3,
            userLabel: null,
            notificationType: 'welcome',
            notificationTitle: 'Welcome',
        );
    }

    /**
     * Writes a boundary of the window the way the journal's SQL hands it over: its columns, the id as text.
     *
     * @param HilosNotificationDeliveryTableRow $row Boundary row
     * @return TableAnchorDTO Boundary in the columns of the source
     */
    private static function sqlBoundary(HilosNotificationDeliveryTableRow $row): TableAnchorDTO
    {
        return new TableAnchorDTO(['created_at' => $row->createdAt, 'id' => (string) $row->rowKey]);
    }

    /**
     * Boots a connection holding a newest-first window of the journal.
     *
     * @param list<HilosNotificationDeliveryTableRow> $stored Rows the database holds, as they are AFTER the change
     * @param list<HilosNotificationDeliveryTableRow> $shown Rows the connection was delivered, in display order
     * @param array<string, mixed> $filter Open filter map narrowing the window's set
     * @return TableViewportSubscription The recorded viewport
     */
    private function boot(array $stored, array $shown, array $filter = []): TableViewportSubscription
    {
        $table = new DeliveriesLiveUnitTable($stored, $filter === []);
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new DeliveriesLiveUnitTableContext($table);
        Hilos::$table->configure();
        Hilos::$sr->subscribeToPage(
            DeliveriesLiveUnitContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-1', DeliveriesLiveUnitContext::PAGE),
        );

        $sort = TableSortOrderDTO::of(new TableSortDTO(HilosNotificationDeliveryTableRow::createdAt, TableConstants::ORDER_DESC));
        $viewport = new TableViewportSubscription(
            tableKey: HilosNotificationDeliveriesTable::TABLE,
            filter: $filter,
            sort: $sort,
            limit: 10,
        );
        $query = new TableQueryDTO(sort: $sort, filter: $filter);
        $window = [];
        $anchors = [];
        foreach ($shown as $row) {
            $browserRow = $table->browserRow($row);
            $key = (string) $row->rowKey;
            $window[$key] = [
                PagePayload::rowKey => $browserRow[BrowserPageSignalData::rowKey],
                PagePayload::slots => $browserRow[BrowserPageSignalData::sources],
            ];
            $anchors[$key] = $table->anchorForRow($row, $query);
        }
        $viewport->recordWindow(
            $window,
            count($shown),
            true,
            self::sqlBoundary($shown[0]),
            self::sqlBoundary($shown[count($shown) - 1]),
            $anchors,
        );
        Hilos::$sr->setTableViewport('ak-1', $viewport);

        return $viewport;
    }

    /**
     * Hands one change of the collection to the browser context and flushes what it built.
     *
     * @param SourceChange $change Change of the notificationDeliveries collection
     */
    private function record(SourceChange $change): void
    {
        $context = new DeliveriesLiveUnitContext();
        $context->record($change);
        $context->flushToSignalRouter();
    }

    /**
     * Asserts the next queued signal is an addressed table viewport delta and returns it.
     *
     * @return TableViewportDeltaDTO The delta payload
     */
    private function nextDelta(): TableViewportDeltaDTO
    {
        $data = $this->nextFrame(SignalTypeConstants::TABLE_VIEWPORT_DELTA);
        $this->assertInstanceOf(TableViewportDeltaDTO::class, $data);

        return $data;
    }

    /**
     * Asserts the next queued signal is an addressed table viewport count and returns it.
     *
     * @return TableViewportCountDTO The count payload
     */
    private function nextCount(): TableViewportCountDTO
    {
        $data = $this->nextFrame(SignalTypeConstants::TABLE_VIEWPORT_COUNT);
        $this->assertInstanceOf(TableViewportCountDTO::class, $data);

        return $data;
    }

    /**
     * Asserts the next queued signal is an addressed table viewport announcement and returns it.
     *
     * @return TableViewportAnnounceDTO The announce payload
     */
    private function nextAnnounce(): TableViewportAnnounceDTO
    {
        $data = $this->nextFrame(SignalTypeConstants::TABLE_VIEWPORT_ANNOUNCE);
        $this->assertInstanceOf(TableViewportAnnounceDTO::class, $data);

        return $data;
    }

    /**
     * Asserts the next queued signal is a frame of the given name addressed to the viewer, and returns its payload.
     *
     * @param string $name Frame name expected next
     * @return mixed The frame's payload
     */
    private function nextFrame(string $name): mixed
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
        $this->assertSame($name, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);

        return $signal->data->data;
    }
}

final class DeliveriesLiveUnitContext extends BrowserContext
{
    public const string PAGE = 'deliveries_live_page';
    public const string SIGNAL = 'deliveries_live_signal';

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
     * Binds the test page to the delivery journal.
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
            HilosNotificationDeliveriesTable::TABLE => [],
        ]);
    }
}

final class DeliveriesLiveUnitTableContext extends TableContext
{
    /**
     * @param DeliveriesLiveUnitTable $table Journal the context registers
     */
    public function __construct(private readonly DeliveriesLiveUnitTable $table)
    {
    }

    public function configure(): void
    {
        $this->register(HilosNotificationDeliveriesTable::TABLE, $this->table);
    }
}

final class DeliveriesLiveUnitTable extends HilosNotificationDeliveriesTable
{
    /** @var array<int, HilosNotificationDeliveryTableRow> Rows the database holds, by delivery id */
    private array $stored = [];

    /**
     * @param list<HilosNotificationDeliveryTableRow> $stored Rows the database holds
     * @param bool $inSet What the database answers about a delivery's membership in a window's set
     */
    public function __construct(array $stored, private readonly bool $inSet)
    {
        parent::__construct();
        foreach ($stored as $row) {
            $this->stored[$row->rowKey] = $row;
        }
    }

    /**
     * Reads one row from memory instead of the database.
     *
     * @param int $deliveryId Delivery id to read
     * @return ?HilosNotificationDeliveryTableRow Row held for that id, or null when none is
     */
    protected function readRow(int $deliveryId): ?HilosNotificationDeliveryTableRow
    {
        return $this->stored[$deliveryId] ?? null;
    }

    /**
     * Answers the membership question from the fixture instead of the database.
     *
     * @param string $where The ` WHERE ...` clause of the set with the key condition in it
     * @param list<mixed> $params Parameters bound to the placeholders of the clause, in order
     * @return bool Answer the fixture was built with
     */
    protected function existsInSet(string $where, array $params): bool
    {
        return $this->inSet;
    }
}
