<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\AbstractPageFactory;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\PageNotFoundException;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Bulk\TableBulkRun;
use Hilos\Core\Table\DTO\TableBulkAcceptedReplyDTO;
use Hilos\Core\Table\DTO\TableBulkActionDTO;
use Hilos\Core\Table\DTO\TableBulkReportSignalData;
use Hilos\Core\Table\DTO\TableProgressDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\Exception\TableActionException;
use Hilos\Core\Table\Exception\TableBulkRunBusyException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableProgressScope;
use Hilos\Hilos as HilosFacade;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the mass operation the worker tick drives (HIL-799).
 *
 * What is pinned here is the promise the ticket is made of: the server judges EVERY row it was
 * pointed at, one at a time, and the run ends by naming the ones it did not touch. Around that
 * sit the rules that keep the run from lying - a row that left the set before its turn is
 * reported rather than silently missing, the walk over a condition follows the set as the run
 * changes it, and the report goes out before the bar comes down so the panel is never empty
 * between them.
 */
final class TableBulkRunTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-bulk-1';

    private ?SignalRouter $previousSignalRouter = null;

    /** @var list<array{name: string, acceptKey: string, payload: array<string, mixed>}> Frames drained from the queue so far */
    private array $drainedFrames = [];

    protected function setUp(): void
    {
        $this->previousSignalRouter = HilosFacade::$sr;
        HilosFacade::$sr = new SignalRouter();
        $this->drainedFrames = [];
    }

    protected function tearDown(): void
    {
        ExecutionContext::clear();
        HilosFacade::$sr = $this->previousSignalRouter;

        parent::tearDown();
    }

    public function testANamedTargetIsJudgedRowByRowAndReportedWhole(): void
    {
        [$router, $page] = $this->openLine(new BulkRunTestTable($this->rows(3)));
        $reply = $this->startRun($page, ['r1', 'r2', 'r3']);

        $this->assertSame(3, $reply->total);
        $this->drive($router);

        $this->assertSame(['r1', 'r2', 'r3'], $page->judged);
        $report = $this->report();
        $this->assertSame(3, $report->touched);
        $this->assertSame([], $report->untouched);
        $this->assertNull($report->untouchedOmitted);
    }

    public function testARowASomebodyElseDeletedIsReportedUntouchedWithItsReason(): void
    {
        $table = new BulkRunTestTable($this->rows(3));
        $table->gone = ['r2'];
        [$router, $page] = $this->openLine($table);
        $this->startRun($page, ['r1', 'r2', 'r3']);

        $this->drive($router);

        $this->assertSame(['r1', 'r3'], $page->judged);
        $report = $this->report();
        $this->assertSame(2, $report->touched);
        $this->assertCount(1, $report->untouched);
        $this->assertSame('r2', $report->untouched[0]->rowKey);
        $this->assertSame(TableConstants::BULK_REASON_ROW_GONE, $report->untouched[0]->reason);
    }

    public function testAConditionIsWalkedByAnchorUntilTheSetRunsOut(): void
    {
        $table = new BulkRunTestTable($this->rows(23));
        [$router, $page] = $this->openLine($table);
        $reply = $this->startRun($page, null, ['kind' => 'all']);

        $this->assertSame(23, $reply->total);
        $this->drive($router);

        $this->assertCount(23, $page->judged);
        $this->assertSame('r1', $page->judged[0]);
        $this->assertSame('r23', $page->judged[22]);
        $this->assertSame(23, $this->report()->touched);
    }

    public function testAConditionKeepsItsPlaceWhileTheRunEmptiesTheSetUnderIt(): void
    {
        $table = new BulkRunTestTable($this->rows(23));
        [$router, $page] = $this->openLine($table);
        $page->mode = BulkRunTestPage::MODE_CONSUME;
        $this->startRun($page, null, ['kind' => 'all']);

        $this->drive($router);

        $this->assertCount(23, $page->judged);
        $this->assertSame(23, count(array_unique($page->judged)));
        $this->assertSame(23, $this->report()->touched);
    }

    public function testNoMoreThanAHandfulOfRowsAreEverInFlight(): void
    {
        [$router, $page] = $this->openLine(new BulkRunTestTable($this->rows(40)));
        $page->mode = BulkRunTestPage::MODE_DEFER;
        $this->startRun($page, null, ['kind' => 'all']);

        $router->advanceBulkRuns();
        $this->assertCount(TableConstants::BULK_ROWS_IN_FLIGHT, $page->judged);

        $router->advanceBulkRuns();
        $router->advanceBulkRuns();
        $this->assertCount(TableConstants::BULK_ROWS_IN_FLIGHT, $page->judged);
    }

    public function testAVerdictDeclaredLaterLetsTheRunGoOn(): void
    {
        [$router, $page] = $this->openLine(new BulkRunTestTable($this->rows(12)));
        $page->mode = BulkRunTestPage::MODE_DEFER;
        $reply = $this->startRun($page, null, ['kind' => 'all']);

        $router->advanceBulkRuns();
        $this->assertCount(TableConstants::BULK_ROWS_IN_FLIGHT, $page->judged);

        foreach ($page->judged as $rowKey) {
            $router->declareBulkVerdict($reply->progressKey, $rowKey, null);
        }
        $page->mode = BulkRunTestPage::MODE_TOUCH;
        $this->drive($router);

        $this->assertCount(12, $page->judged);
        $this->assertSame(12, $this->report()->touched);
    }

    public function testTheBarMovesOnceForEveryRowJudgedAndComesDownLast(): void
    {
        [$router, $page] = $this->openLine(new BulkRunTestTable($this->rows(3)));
        $reply = $this->startRun($page, ['r1', 'r2', 'r3']);

        $this->drive($router);

        $names = array_column($this->frames(), 'name');
        $this->assertSame(
            [
                'table_progress',
                'table_progress',
                'table_progress',
                'table_bulk_report',
                'table_progress',
            ],
            $names,
        );

        $bars = array_values(array_filter(
            $this->frames(),
            static fn(array $frame): bool => $frame['name'] === 'table_progress',
        ));
        $this->assertSame([1, 2, 3, 3], array_map(
            static fn(array $frame): int => (int)$frame['payload'][TableProgressDTO::current],
            $bars,
        ));
        $this->assertArrayNotHasKey(TableProgressDTO::ended, $bars[2]['payload']);
        $this->assertTrue($bars[3]['payload'][TableProgressDTO::ended]);
        $this->assertSame(
            TableProgressScope::Bulk->value,
            $bars[0]['payload'][TableProgressDTO::scope],
        );
        $this->assertSame($reply->progressKey, $bars[0]['payload'][TableProgressDTO::progressKey]);
    }

    public function testEveryFrameOfARunGoesToTheConnectionThatStartedIt(): void
    {
        [$router, $page] = $this->openLine(new BulkRunTestTable($this->rows(2)));
        $this->startRun($page, ['r1', 'r2']);

        $this->drive($router);

        foreach ($this->frames() as $frame) {
            $this->assertSame(self::ACCEPT_KEY, $frame['acceptKey']);
        }
    }

    public function testASecondRunOnTheSameTableFromTheSameConnectionIsRefused(): void
    {
        [, $page] = $this->openLine(new BulkRunTestTable($this->rows(2)));
        $page->mode = BulkRunTestPage::MODE_DEFER;
        $this->startRun($page, ['r1']);

        $this->expectException(TableBulkRunBusyException::class);

        $this->startRun($page, ['r2']);
    }

    public function testTheSameTableKeyOnAnotherPageIsARunOfItsOwn(): void
    {
        $factory = new BulkRunTestPageFactory(new BulkRunTestAgent());
        $router = new PageSignalRouter($factory, new ActionRouteConfig([
            BulkRunTestPage::ACTION => BulkRunTestPage::PAGE,
        ]));
        $first = $factory->getPage(BulkRunTestPage::PAGE);
        $second = $factory->getPage(BulkRunSecondTestPage::PAGE);
        $this->assertInstanceOf(BulkRunTestPage::class, $first);
        $this->assertInstanceOf(BulkRunSecondTestPage::class, $second);
        $first->bindSignalRouter($router);
        $second->bindSignalRouter($router);
        $first->table = new BulkRunTestTable($this->rows(2));
        $first->mode = BulkRunTestPage::MODE_DEFER;

        $this->startRun($first, ['r1']);
        $accepted = $second->openBulkRun(
            self::ACCEPT_KEY,
            new BulkRunActionDTO(BulkRunTestTable::TABLE, ['r2'], null),
        );

        $this->assertNotSame('', $accepted->progressKey);
    }

    public function testAWindowThatDoesNotMovePastItsAnchorEndsTheWalk(): void
    {
        $table = new BulkRunTestTable($this->rows(23));
        $table->ignoresAnchor = true;
        [$router, $page] = $this->openLine($table);
        $this->startRun($page, null, ['kind' => 'all']);

        $this->drive($router);

        $this->assertCount(TableConstants::BULK_ROWS_IN_FLIGHT, $page->judged);
        $this->assertSame(TableConstants::BULK_ROWS_IN_FLIGHT, $this->report()->touched);
    }

    public function testAJudgeThatRaisedLeavesItsRowUntouchedRatherThanStoppingTheRun(): void
    {
        [$router, $page] = $this->openLine(new BulkRunTestTable($this->rows(2)));
        $page->mode = BulkRunTestPage::MODE_RAISE;
        $this->startRun($page, ['r1', 'r2']);

        $this->drive($router);

        $report = $this->report();
        $this->assertSame(0, $report->touched);
        $this->assertCount(2, $report->untouched);
        $this->assertSame(BulkRunTestPage::REFUSAL, $report->untouched[0]->reason);
    }

    public function testAVerdictNobodyIsWaitingForChangesNothing(): void
    {
        [$router, $page] = $this->openLine(new BulkRunTestTable($this->rows(1)));
        $reply = $this->startRun($page, ['r1']);

        $this->drive($router);
        $router->declareBulkVerdict($reply->progressKey, 'r1', 'too late');

        $this->assertSame(1, $this->report()->touched);
    }

    public function testAnOverdueVerdictNamesItsRowUntouchedAndTheRunGoesOn(): void
    {
        $run = $this->bareRun();
        $run->handOut('r1', microtime(true) + TableConstants::BULK_VERDICT_TIMEOUT_SECONDS);
        $run->handOut('r2', microtime(true) + TableConstants::BULK_VERDICT_TIMEOUT_SECONDS);

        $this->assertSame([], $run->overdue(microtime(true)));

        $late = $run->overdue(microtime(true) + TableConstants::BULK_VERDICT_TIMEOUT_SECONDS + 1.0);
        $this->assertSame(['r1', 'r2'], $late);
        $this->assertFalse($run->settle('r1'));

        foreach ($late as $rowKey) {
            $run->recordUntouched($rowKey, TableConstants::BULK_REASON_NO_VERDICT);
        }
        $this->assertTrue($run->isFinished());
        $this->assertSame(TableConstants::BULK_REASON_NO_VERDICT, $run->untouched[0]->reason);
    }

    public function testUntouchedRowsAreNamedUpToTheCeilingAndCountedAfterIt(): void
    {
        $run = $this->bareRun();
        $over = TableConstants::BULK_UNTOUCHED_NAME_CEILING + 7;
        for ($i = 0; $i < $over; $i++) {
            $run->recordUntouched('r' . $i, TableConstants::BULK_REASON_ROW_GONE);
        }

        $this->assertCount(TableConstants::BULK_UNTOUCHED_NAME_CEILING, $run->untouched);
        $this->assertSame(7, $run->untouchedOmitted);
        $this->assertSame($over, $run->judged);
    }

    /**
     * Builds a run with no line behind it, for the rules that are the record's own.
     *
     * @return TableBulkRun Run over two named rows
     */
    private function bareRun(): TableBulkRun
    {
        return new TableBulkRun(
            progressKey: 'bulk-1',
            acceptKey: self::ACCEPT_KEY,
            page: BulkRunTestPage::PAGE,
            tableKey: BulkRunTestTable::TABLE,
            table: new BulkRunTestTable($this->rows(2)),
            action: BulkRunTestPage::ACTION,
            rowKeys: [],
            filter: null,
            total: 0,
        );
    }

    /**
     * Stands up a router, a page and a table, the way a worker would.
     *
     * @param BulkRunTestTable $table Table the run will be over
     * @return array{PageSignalRouter, BulkRunTestPage} Router driving the run, and the page judging rows
     * @throws PageNotFoundException When the fixture page cannot be resolved
     */
    private function openLine(BulkRunTestTable $table): array
    {
        $factory = new BulkRunTestPageFactory(new BulkRunTestAgent());
        $router = new PageSignalRouter($factory, new ActionRouteConfig([
            BulkRunTestPage::ACTION => BulkRunTestPage::PAGE,
        ]));
        $page = $factory->getPage(BulkRunTestPage::PAGE);
        $this->assertInstanceOf(BulkRunTestPage::class, $page);
        $page->bindSignalRouter($router);
        $page->table = $table;

        return [$router, $page];
    }

    /**
     * Starts one run the way a page's action handler would.
     *
     * @param BulkRunTestPage $page Page whose action opens the run
     * @param ?list<string> $rowKeys Rows named one by one, or null for a condition
     * @param ?array<string, mixed> $filter Condition, or null when the rows are named
     * @return TableBulkAcceptedReplyDTO Acceptance the action would have replied with
     * @throws InvalidFormatException When the fixture builds a target of the wrong shape
     * @throws TableBulkRunBusyException When this connection already has a run on this table
     */
    private function startRun(BulkRunTestPage $page, ?array $rowKeys, ?array $filter = null): TableBulkAcceptedReplyDTO
    {
        return $page->openBulkRun(
            self::ACCEPT_KEY,
            new BulkRunActionDTO(BulkRunTestTable::TABLE, $rowKeys, $filter),
        );
    }

    /**
     * Turns the worker tick until the run has nothing left to do.
     *
     * @param PageSignalRouter $router Router holding the run
     */
    private function drive(PageSignalRouter $router): void
    {
        for ($tick = 0; $tick < 100; $tick++) {
            $router->advanceBulkRuns();
        }
    }

    /**
     * Drains the signal queue into the frames one connection was sent, in order.
     *
     * @return list<array{name: string, acceptKey: string, payload: array<string, mixed>}> Frames, in the order they were queued
     */
    private function frames(): array
    {
        while (($signal = HilosFacade::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $data = $signal->data;
            if (!$data instanceof WebSocketSignalData) {
                continue;
            }

            $this->drainedFrames[] = [
                'name' => $signal->signalName->getName(),
                'acceptKey' => (string)$data->targetAcceptKey,
                'payload' => $data->data->toArray(),
            ];
        }

        return $this->drainedFrames;
    }

    /**
     * Reads the one report the run ended with.
     *
     * @return TableBulkReportSignalData Report as the connection received it
     * @throws InvalidFormatException When the frame is not a report after all
     */
    private function report(): TableBulkReportSignalData
    {
        $reports = array_values(array_filter(
            $this->frames(),
            static fn(array $frame): bool => $frame['name'] === 'table_bulk_report',
        ));
        $this->assertCount(1, $reports);

        return TableBulkReportSignalData::fromArray($reports[0]['payload']);
    }

    /**
     * Builds a set of rows keyed r1..rN.
     *
     * @param int $count How many rows the table holds
     * @return list<BulkRunTestRow> Rows in their natural order
     */
    private function rows(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = new BulkRunTestRow('r' . $i);
        }

        return $rows;
    }
}

/**
 * The concrete bulk action a page declares, standing in for a real mass delete.
 */
final class BulkRunActionDTO extends TableBulkActionDTO
{
    /**
     * Gets the action name this DTO represents.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return BulkRunTestPage::ACTION;
    }
}

/**
 * Page fixture that opens a run and judges the rows it is handed.
 */
final class BulkRunTestPage extends AbstractPage
{
    public const string PAGE = 'bulk_run';

    public const string ACTION = 'bulkDelete';

    /** Judge that answers "changed" in the very call it is asked. */
    public const string MODE_TOUCH = 'touch';

    /** Judge that answers nothing, the way a page waiting on the row's owner does. */
    public const string MODE_DEFER = 'defer';

    /** Judge that raises instead of deciding. */
    public const string MODE_RAISE = 'raise';

    /** Judge that answers "changed" and takes the row out of the set, as a delete does. */
    public const string MODE_CONSUME = 'consume';

    /** What a raising judge tells the person who started the run. */
    public const string REFUSAL = 'This row belongs to somebody else';

    /** Table the page hands the framework; set by the test. */
    public ?BulkRunTestTable $table = null;

    /** How this page's judge answers. */
    public string $mode = self::MODE_TOUCH;

    /** @var list<string> Rows the framework handed over, in the order it did */
    public array $judged = [];

    /**
     * Opens a run the way this page's action handler would.
     *
     * @param string $acceptKey Connection that asked
     * @param TableBulkActionDTO $dto Request naming the table and the target
     * @return TableBulkAcceptedReplyDTO Acceptance the action replies with
     * @throws TableBulkRunBusyException When this connection already has a run on this table
     */
    public function openBulkRun(string $acceptKey, TableBulkActionDTO $dto): TableBulkAcceptedReplyDTO
    {
        return $this->startBulkAction($acceptKey, $dto, $this->table ?? new BulkRunTestTable());
    }

    /**
     * Judges one row the way its mode says.
     *
     * @param string $action Action the run was started under (unused)
     * @param string $progressKey Run the row belongs to
     * @param string $rowKey Row to judge
     * @throws TableBulkRunBusyException Never; declared because the seam it calls may raise
     */
    public function onBulkRow(string $action, string $progressKey, string $rowKey): void
    {
        $this->judged[] = $rowKey;
        if ($this->mode === self::MODE_RAISE) {
            throw new TableActionException(self::REFUSAL);
        }
        if ($this->mode === self::MODE_CONSUME) {
            $this->table?->remove($rowKey);
        }
        if ($this->mode === self::MODE_TOUCH || $this->mode === self::MODE_CONSUME) {
            $this->bulkRowTouched($progressKey, $rowKey);
        }
    }
}

/**
 * Page factory fixture exposing the bulk test page.
 *
 * @extends AbstractPageFactory<BulkRunTestAgent>
 */
final class BulkRunTestPageFactory extends AbstractPageFactory
{
    /**
     * Creates the bulk test page.
     *
     * @param string $pageName Page name
     * @return AbstractPage Test page instance
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        if ($pageName === BulkRunTestPage::PAGE) {
            return new BulkRunTestPage($this->agent);
        }
        if ($pageName === BulkRunSecondTestPage::PAGE) {
            return new BulkRunSecondTestPage($this->agent);
        }

        throw new PageNotFoundException($pageName);
    }

    /**
     * Reports whether the bulk test page is available.
     *
     * @param string $pageName Page name
     * @return bool True for either bulk test page
     */
    public function hasPage(string $pageName): bool
    {
        return $pageName === BulkRunTestPage::PAGE || $pageName === BulkRunSecondTestPage::PAGE;
    }
}

/**
 * A second page of the same agent, carrying a table of the same key.
 *
 * One connection may be on both at once, and the two tables are two places with two bars, so
 * a run on each of them is a run of its own.
 */
final class BulkRunSecondTestPage extends AbstractPage
{
    public const string PAGE = 'bulk_run_second';

    /**
     * Opens a run the way this page's action handler would.
     *
     * @param string $acceptKey Connection that asked
     * @param TableBulkActionDTO $dto Request naming the table and the target
     * @return TableBulkAcceptedReplyDTO Acceptance the action replies with
     * @throws TableBulkRunBusyException When this connection already has a run on this table
     */
    public function openBulkRun(string $acceptKey, TableBulkActionDTO $dto): TableBulkAcceptedReplyDTO
    {
        return $this->startBulkAction($acceptKey, $dto, new BulkRunTestTable());
    }

    /**
     * Answers nothing, the way a page waiting on the row's owner does.
     *
     * @param string $action Action the run was started under (unused)
     * @param string $progressKey Run the row belongs to (unused)
     * @param string $rowKey Row to judge (unused)
     */
    public function onBulkRow(string $action, string $progressKey, string $rowKey): void
    {
    }
}

final class BulkRunTestAgent implements PageAgentInterface
{
    /**
     * Returns the fixture agent id.
     *
     * @return string Agent id
     */
    public function getId(): string
    {
        return 'unit-bulk-run-agent';
    }

    /**
     * Returns the fixture signal source the run's frames are sent from.
     *
     * @return SignalSourceInterface Signal source
     */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'bulk_run_agent');
    }
}

/**
 * Table fixture holding its rows in memory, and able to say which of them have left the set.
 */
final class BulkRunTestTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'bulkRunTable';

    public const string SLOT = 'bulkRunRows';

    /** @var list<string> Rows the table answers "no longer here" about */
    public array $gone = [];

    /** Whether the table hands back the same first window however it is anchored. */
    public bool $ignoresAnchor = false;

    /** @var list<BulkRunTestRow> Rows the table holds */
    private array $rows;

    /**
     * @param list<BulkRunTestRow> $rows Rows the table holds
     */
    public function __construct(array $rows = [])
    {
        $this->rows = $rows;
        parent::__construct();
    }

    /**
     * Takes one row out of the set, the way a delete the run asked for would.
     *
     * @param string $rowKey Row to take out
     */
    public function remove(string $rowKey): void
    {
        $this->rows = array_values(array_filter(
            $this->rows,
            static fn(BulkRunTestRow $row): bool => $row->key !== $rowKey,
        ));
    }

    /**
     * Answers whether one row is still in the set the run is walking.
     *
     * @param string|int $rowKey Row key to place against the set
     * @param TableQueryDTO $query Window query describing the set (unused by the fixture)
     * @return ?bool Whether the row is in the set
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return !in_array((string)$rowKey, $this->gone, true);
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
     * @param AbstractTableRow $row Row to serialize
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => (string)$row->getRowKey(),
            BrowserPageSignalData::sources => [self::SLOT => $row->toArray()],
        ];
    }

    /**
     * Configures the row class so the window rebuilds typed rows.
     */
    protected function init(): void
    {
        $this->setRowClass(BulkRunTestRow::class);
    }

    /**
     * Windows the rows the fixture holds.
     *
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Windowed snapshot
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory(
            array_map(static fn(BulkRunTestRow $row): array => $row->toArray(), $this->rows),
            $this->ignoresAnchor
                ? new TableQueryDTO(
                    search: $query->search,
                    sort: $query->sort,
                    limit: $query->limit,
                    filter: $query->filter,
                    searchableFields: $query->searchableFields,
                )
                : $query,
        );
    }
}

final class BulkRunTestRow extends AbstractTableRow
{
    /**
     * @param string $key Row key
     */
    public function __construct(public readonly string $key)
    {
    }

    /**
     * @return string Row key
     */
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
     * @throws InvalidFormatException When the payload carries no key
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, 'key'));
    }
}
