<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

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
use Hilos\Core\Table\DTO\TableBulkAcceptedReplyDTO;
use Hilos\Core\Table\DTO\TableBulkActionDTO;
use Hilos\Core\Table\DTO\TableBulkReportSignalData;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\Definition\ViewportTable;
use Hilos\Core\Table\Exception\TableBulkRunBusyException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\PhpType;
use Hilos\Hilos as HilosFacade;

/**
 * Integration test: the bulk action's acceptance criterion, against a real row source (HIL-799).
 *
 * The ticket names one proof and this is it - a row somebody else deleted before its turn came
 * arrives in the report as untouched, with a reason. A fixture table can be told to say a row is
 * gone; only a real one can have the row actually gone, with the walk over the rest of the set
 * continuing across the hole the delete left.
 */
final class TableBulkActionIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Scratch table the run is over. */
    private const string TABLE = 'hilos_fw_test_bulk_rows';

    /** Rows the scratch table holds, more than one window of the run. */
    private const int ROW_COUNT = 25;

    /** Connection the run is started from, and the only one its frames go to. */
    private const string ACCEPT_KEY = 'ak-bulk-integration';

    private ?SignalRouter $previousSignalRouter = null;

    /**
     * Raises the scratch table with an unbroken run of rows.
     *
     * @throws DatabaseException When the scratch schema cannot be raised
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSignalRouter = HilosFacade::$sr;
        HilosFacade::$sr = new SignalRouter();

        Database::sql('DROP TABLE IF EXISTS `' . self::TABLE . '`');
        Database::sql(
            'CREATE TABLE `' . self::TABLE . '` ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . '`label` VARCHAR(32) NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
        for ($id = 1; $id <= self::ROW_COUNT; $id++) {
            Database::sql('INSERT INTO `' . self::TABLE . '` (`label`) VALUES (?)', ["row-{$id}"]);
        }
    }

    /**
     * Drops the scratch table before the connection is closed by the parent.
     *
     * @throws DatabaseException When the scratch table cannot be dropped
     */
    protected function tearDown(): void
    {
        if (Database::isConnected()) {
            Database::sql('DROP TABLE IF EXISTS `' . self::TABLE . '`');
        }
        ExecutionContext::clear();
        HilosFacade::$sr = $this->previousSignalRouter;

        parent::tearDown();
    }

    /**
     * @throws DatabaseException When a scratch query fails
     * @throws InvalidFormatException When the report frame is malformed
     * @throws PageNotFoundException When the fixture page cannot be resolved
     * @throws TableBulkRunBusyException When the run is refused, which this case does not expect
     */
    public function testARowDeletedBeforeItsTurnComesBackNamedAndWithItsReason(): void
    {
        [$router, $page] = $this->openLine();
        Database::sql('DELETE FROM `' . self::TABLE . '` WHERE `id` = ?', [3]);

        $this->startRun($page, ['1', '2', '3', '4']);
        $this->drive($router);

        $report = $this->report();
        $this->assertSame(3, $report->touched);
        $this->assertCount(1, $report->untouched);
        $this->assertSame('3', $report->untouched[0]->rowKey);
        $this->assertSame(TableConstants::BULK_REASON_ROW_GONE, $report->untouched[0]->reason);
        $this->assertSame([5, 6], $this->remainingIds(2));
    }

    /**
     * @throws DatabaseException When a scratch query fails
     * @throws InvalidFormatException When the report frame is malformed
     * @throws PageNotFoundException When the fixture page cannot be resolved
     * @throws TableBulkRunBusyException When the run is refused, which this case does not expect
     */
    public function testAConditionIsWalkedToTheEndWhileTheRunEmptiesTheSetUnderIt(): void
    {
        [$router, $page] = $this->openLine();

        $reply = $this->startRun($page, null, []);
        $this->assertSame(self::ROW_COUNT, $reply->total);
        $this->drive($router);

        $this->assertSame(self::ROW_COUNT, $this->report()->touched);
        $this->assertSame([], $this->remainingIds(self::ROW_COUNT));
        $this->assertCount(self::ROW_COUNT, array_unique($page->judged));
    }

    /**
     * @throws DatabaseException When a scratch query fails
     * @throws PageNotFoundException When the fixture page cannot be resolved
     * @throws TableBulkRunBusyException When the second run is refused, which this case expects
     */
    public function testASecondRunOnTheSameTableIsRefusedWhileTheFirstIsGoing(): void
    {
        [, $page] = $this->openLine();
        $page->defers = true;
        $this->startRun($page, ['1']);

        $this->expectException(TableBulkRunBusyException::class);

        $this->startRun($page, ['2']);
    }

    /**
     * Stands up a router and a page over the scratch table.
     *
     * @return array{PageSignalRouter, BulkIntegrationPage} Router driving the run, and the page judging rows
     * @throws PageNotFoundException When the fixture page cannot be resolved
     */
    private function openLine(): array
    {
        $factory = new BulkIntegrationPageFactory(new BulkIntegrationAgent());
        $router = new PageSignalRouter($factory, new ActionRouteConfig([
            BulkIntegrationPage::ACTION => BulkIntegrationPage::PAGE,
        ]));
        $page = $factory->getPage(BulkIntegrationPage::PAGE);
        $this->assertInstanceOf(BulkIntegrationPage::class, $page);
        $page->bindSignalRouter($router);

        return [$router, $page];
    }

    /**
     * Starts one run the way a page's action handler would.
     *
     * @param BulkIntegrationPage $page Page whose action opens the run
     * @param ?list<string> $rowKeys Rows named one by one, or null for a condition
     * @param ?array<string, mixed> $filter Condition, or null when the rows are named
     * @return TableBulkAcceptedReplyDTO Acceptance the action would have replied with
     * @throws InvalidFormatException When the fixture builds a target of the wrong shape
     * @throws TableBulkRunBusyException When this connection already has a run on this table
     */
    private function startRun(
        BulkIntegrationPage $page,
        ?array $rowKeys,
        ?array $filter = null,
    ): TableBulkAcceptedReplyDTO {
        return $page->openBulkRun(
            self::ACCEPT_KEY,
            new BulkIntegrationActionDTO(BulkIntegrationTable::TABLE, $rowKeys, $filter),
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
     * Reads the one report the run ended with.
     *
     * @return TableBulkReportSignalData Report as the connection received it
     * @throws InvalidFormatException When the frame is not a report after all
     */
    private function report(): TableBulkReportSignalData
    {
        $reports = [];
        while (($signal = HilosFacade::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $data = $signal->data;
            if (!$data instanceof WebSocketSignalData || $signal->signalName->getName() !== 'table_bulk_report') {
                continue;
            }

            $this->assertSame(self::ACCEPT_KEY, $data->targetAcceptKey);
            $reports[] = $data->data->toArray();
        }
        $this->assertCount(1, $reports);

        return TableBulkReportSignalData::fromArray($reports[0]);
    }

    /**
     * Reads the ids the scratch table still holds.
     *
     * @param int $limit How many to read
     * @return list<int> Ids still in the table, in key order
     * @throws DatabaseException When the query fails
     */
    private function remainingIds(int $limit): array
    {
        $ids = [];
        $rows = Database::sql(
            'SELECT `id` FROM `' . self::TABLE . '` ORDER BY `id` LIMIT ' . $limit,
        )->rows();
        foreach ($rows as $row) {
            $ids[] = (int)$row['id'];
        }

        return $ids;
    }
}

/**
 * The concrete bulk action the fixture page declares.
 */
final class BulkIntegrationActionDTO extends TableBulkActionDTO
{
    /**
     * Gets the action name this DTO represents.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return BulkIntegrationPage::ACTION;
    }
}

/**
 * Page fixture whose judge deletes the row it is handed, standing in for the row's owner.
 */
final class BulkIntegrationPage extends AbstractPage
{
    public const string PAGE = 'bulk_integration';

    public const string ACTION = 'bulkDelete';

    /** Whether the judge answers nothing, the way a page waiting on another agent does. */
    public bool $defers = false;

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
        return $this->startBulkAction($acceptKey, $dto, new BulkIntegrationTable());
    }

    /**
     * Deletes one row and says so, or says nothing at all.
     *
     * @param string $action Action the run was started under (unused)
     * @param string $progressKey Run the row belongs to
     * @param string $rowKey Row to judge
     * @throws DatabaseException When the delete fails
     */
    public function onBulkRow(string $action, string $progressKey, string $rowKey): void
    {
        $this->judged[] = $rowKey;
        if ($this->defers) {
            return;
        }

        Database::sql('DELETE FROM `' . BulkIntegrationEntity::_table . '` WHERE `id` = ?', [(int)$rowKey]);
        $this->bulkRowTouched($progressKey, $rowKey);
    }
}

/**
 * Page factory fixture exposing the bulk integration page.
 *
 * @extends AbstractPageFactory<BulkIntegrationAgent>
 */
final class BulkIntegrationPageFactory extends AbstractPageFactory
{
    /**
     * Creates the bulk integration page.
     *
     * @param string $pageName Page name
     * @return AbstractPage Test page instance
     * @throws PageNotFoundException When an unexpected page is requested
     */
    protected function createPage(string $pageName): AbstractPage
    {
        if ($pageName === BulkIntegrationPage::PAGE) {
            return new BulkIntegrationPage($this->agent);
        }

        throw new PageNotFoundException($pageName);
    }

    /**
     * Reports whether the bulk integration page is available.
     *
     * @param string $pageName Page name
     * @return bool True for the bulk integration page
     */
    public function hasPage(string $pageName): bool
    {
        return $pageName === BulkIntegrationPage::PAGE;
    }
}

final class BulkIntegrationAgent implements PageAgentInterface
{
    /**
     * Returns the fixture agent id.
     *
     * @return string Agent id
     */
    public function getId(): string
    {
        return 'integration-bulk-agent';
    }

    /**
     * Returns the fixture signal source the run's frames are sent from.
     *
     * @return SignalSourceInterface Signal source
     */
    public function getAgentSignalSource(): SignalSourceInterface
    {
        return new SignalSource(SignalSource::AGENT, 'bulk_integration_agent');
    }
}

/**
 * Table over the scratch rows, windowed by the real keyset query.
 */
final class BulkIntegrationTable extends TableDefinition implements ViewportTable
{
    public const string TABLE = 'bulkIntegrationTable';

    public const string SLOT = 'bulkIntegrationRows';

    /**
     * Answers whether one row is still in the table.
     *
     * @param string|int $rowKey Row key to place against the set
     * @param TableQueryDTO $query Window query describing the set (the whole table here)
     * @return ?bool Whether the row is still there
     * @throws DatabaseException When the query fails
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return Database::sql(
            'SELECT `id` FROM `' . BulkIntegrationEntity::_table . '` WHERE `id` = ?',
            [(int)$rowKey],
        )->rows() !== [];
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
            'rowKey' => (string)$row->getRowKey(),
            'sources' => [self::SLOT => $row->toArray()],
        ];
    }

    /**
     * Configures the row class so the window rebuilds typed rows.
     */
    protected function init(): void
    {
        $this->setRowClass(BulkIntegrationTableRow::class);
    }

    /**
     * Windows the scratch rows through the real keyset query.
     *
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Windowed snapshot
     * @throws DatabaseException When the window query fails
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $page = BulkIntegrationObjects::initEmpty()->queryPage($query);
        $rows = [];
        foreach (array_keys($page[TableConstants::RESULT_KEY_OBJECTS]) as $key) {
            $rows[] = ['id' => (string)$key];
        }

        return new TableSnapshotDTO(
            rows: $rows,
            totalCount: $page[TableConstants::RESULT_KEY_TOTAL_COUNT],
            totalExact: $page[TableConstants::RESULT_KEY_TOTAL_EXACT],
            limit: $query->limit,
            firstAnchor: $page[TableConstants::RESULT_KEY_FIRST_ANCHOR],
            lastAnchor: $page[TableConstants::RESULT_KEY_LAST_ANCHOR],
        );
    }
}

final class BulkIntegrationTableRow extends AbstractTableRow
{
    /**
     * @param string $id Row key
     */
    public function __construct(public readonly string $id)
    {
    }

    /**
     * @return string Row key
     */
    public function getRowKey(): string
    {
        return $this->id;
    }

    /**
     * @return string Payload key the row key travels under
     */
    public static function keyField(): string
    {
        return 'id';
    }

    /**
     * @return array<string, mixed> Row fields
     */
    public function toArray(): array
    {
        return ['id' => $this->id];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static Row instance
     * @throws InvalidFormatException When the payload carries no key
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireString($data, 'id'));
    }
}

/**
 * Entity bound to the scratch table this test raises.
 */
final class BulkIntegrationEntity extends Entity
{
    public const string id = 'id';
    public const string label = 'label';

    public const string _table = 'hilos_fw_test_bulk_rows';
    public const string _primary = self::id;
    public const array _columns = [
        self::id,
        self::label,
    ];

    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::label => PhpType::STRING->value,
    ];

    public ?int $id = null;
    public string $label = '';
}

/**
 * Minimal stored row over that entity: the window query only ever reads it.
 */
final class BulkIntegrationObject extends Object_
{
    public const string ENTITY_CLASS = BulkIntegrationEntity::class;
}

/**
 * @extends Objects<BulkIntegrationObject>
 */
final class BulkIntegrationObjects extends Objects
{
    public const string OBJECT_CLASS = BulkIntegrationObject::class;
}
