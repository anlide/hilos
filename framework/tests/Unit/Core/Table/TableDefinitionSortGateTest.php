<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Table;

use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the sort gate {@see TableDefinition::getPage()} runs (HIL-561, HIL-789, HIL-917).
 *
 * The gate is placed where every table's row source is reached from, so what the tests
 * inspect is the query the concrete table is handed: a declared field arrives with the
 * column it may order by, a field the table does not sort by does not arrive at all, an
 * order of more than one column arrives only if the table offered that very order, and
 * everything else about the window is passed on untouched.
 */
final class TableDefinitionSortGateTest extends TestCase
{
    public function testADeclaredFieldReachesTheQueryCarryingItsColumn(): void
    {
        $table = new SortGateUnitTable([SortGateUnitRow::LABEL => 'row_label']);

        $table->getPage(new TableQueryDTO(
            sort: TableSortOrderDTO::of(new TableSortDTO(SortGateUnitRow::LABEL, TableConstants::ORDER_DESC)),
        ));

        $order = $table->received?->sort;
        self::assertNotNull($order);
        $component = $order->last();
        self::assertSame(SortGateUnitRow::LABEL, $component->field);
        self::assertSame(TableConstants::ORDER_DESC, $component->direction);
        self::assertSame('row_label', $component->column);
    }

    public function testAFieldTheTableDoesNotSortByLeavesTheWindowInItsDefaultOrder(): void
    {
        $table = new SortGateUnitTable([SortGateUnitRow::LABEL => 'row_label']);

        ob_start();
        $table->getPage(new TableQueryDTO(sort: TableSortOrderDTO::of(new TableSortDTO('label` DESC, (SELECT 1)'))));
        ob_end_clean();

        // No sort at all rather than a sort the table cannot serve: the concrete query
        // orders by its own default, and nothing built out of the client's name is left.
        self::assertNotNull($table->received);
        self::assertNull($table->received->sort);
    }

    public function testTheRestOfTheWindowSurvivesTheGate(): void
    {
        $table = new SortGateUnitTable([SortGateUnitRow::LABEL => 'row_label']);

        $table->getPage(new TableQueryDTO(
            search: 'alpha',
            sort: TableSortOrderDTO::of(new TableSortDTO(SortGateUnitRow::LABEL)),
            limit: 10,
            filter: ['channel' => 'email'],
            anchor: new TableAnchorDTO([SortGateUnitRow::KEY => 'a']),
            anchorDirection: TableAnchorDirection::Before,
        ));

        self::assertNotNull($table->received);
        self::assertSame('alpha', $table->received->search);
        self::assertSame(10, $table->received->limit);
        self::assertSame(['channel' => 'email'], $table->received->filter);
        self::assertSame([SortGateUnitRow::KEY => 'a'], $table->received->anchor?->toArray());
        self::assertSame(TableAnchorDirection::Before, $table->received->anchorDirection);
    }

    public function testATableThatDeclaresNoSortableFieldsSortsAsItAlwaysHas(): void
    {
        $table = new SortGateUnitTable();
        $order = TableSortOrderDTO::of(new TableSortDTO(SortGateUnitRow::LABEL));

        $table->getPage(new TableQueryDTO(sort: $order));

        // Its rows are ordered in PHP, where the field is an array key and no identifier
        // is built from it, so the gate has nothing to protect and does not interfere.
        self::assertSame($order, $table->received?->sort);
    }

    public function testADeclaredOrderReachesTheQueryWithAColumnUnderEveryComponent(): void
    {
        $table = new SortGateUnitTable(
            [SortGateUnitRow::LABEL => 'row_label', SortGateUnitRow::CHANNEL => 'row_channel'],
            ['channelThenLabel' => self::channelThenLabel()],
        );

        $table->getPage(new TableQueryDTO(sort: self::channelThenLabel()));

        $order = $table->received?->sort;
        self::assertNotNull($order);
        self::assertSame([SortGateUnitRow::CHANNEL, SortGateUnitRow::LABEL], array_map(
            static fn(TableSortDTO $component): string => $component->field,
            $order->components,
        ));
        self::assertSame(['row_channel', 'row_label'], array_map(
            static fn(TableSortDTO $component): ?string => $component->column,
            $order->components,
        ));
    }

    public function testAnOrderTheTableNeverOfferedIsRejectedWholeAndLogged(): void
    {
        $table = new SortGateUnitTable(
            [SortGateUnitRow::LABEL => 'row_label', SortGateUnitRow::CHANNEL => 'row_channel'],
            ['channelThenLabel' => self::channelThenLabel()],
        );

        ob_start();
        $table->getPage(new TableQueryDTO(sort: TableSortOrderDTO::of(
            new TableSortDTO(SortGateUnitRow::LABEL, TableConstants::ORDER_DESC),
            new TableSortDTO(SortGateUnitRow::CHANNEL, TableConstants::ORDER_DESC),
        )));
        $logged = (string) ob_get_clean();

        // Both fields are sortable on their own; the pair of them in this sequence is not
        // an order this table promised an index for, so the window keeps its default order.
        self::assertNotNull($table->received);
        self::assertNull($table->received->sort);
        self::assertStringContainsString('Table sort order rejected', $logged);
    }

    public function testAMixedDirectionOrderReachesTheQueryWithAColumnUnderEveryComponent(): void
    {
        $mixed = TableSortOrderDTO::of(
            new TableSortDTO(SortGateUnitRow::CHANNEL, TableConstants::ORDER_ASC),
            new TableSortDTO(SortGateUnitRow::LABEL, TableConstants::ORDER_DESC),
        );
        $table = new SortGateUnitTable(
            [SortGateUnitRow::LABEL => 'row_label', SortGateUnitRow::CHANNEL => 'row_channel'],
            ['mixed' => $mixed],
        );

        $table->getPage(new TableQueryDTO(sort: $mixed));

        // One column up and another down is an order the table may declare: the direction of
        // every index column is declared and audited, so the index under it is as visible as
        // the columns are.
        $order = $table->received?->sort;
        self::assertNotNull($order);
        self::assertSame([SortGateUnitRow::CHANNEL, SortGateUnitRow::LABEL], array_map(
            static fn(TableSortDTO $component): string => $component->field,
            $order->components,
        ));
        self::assertSame([TableConstants::ORDER_ASC, TableConstants::ORDER_DESC], array_map(
            static fn(TableSortDTO $component): string => $component->direction,
            $order->components,
        ));
        self::assertSame(['row_channel', 'row_label'], array_map(
            static fn(TableSortDTO $component): ?string => $component->column,
            $order->components,
        ));
    }

    /**
     * @return TableSortOrderDTO The one composite order the test tables declare
     */
    private static function channelThenLabel(): TableSortOrderDTO
    {
        return TableSortOrderDTO::of(
            new TableSortDTO(SortGateUnitRow::CHANNEL, TableConstants::ORDER_DESC),
            new TableSortDTO(SortGateUnitRow::LABEL, TableConstants::ORDER_DESC),
        );
    }
}

final class SortGateUnitTable extends TableDefinition
{
    /** Query the concrete table was handed, or null while getPage() has not run. */
    public ?TableQueryDTO $received = null;

    /** @var array<string, string> Sortable fields this table declares */
    private array $declaredSortableFields;

    /** @var array<string, TableSortOrderDTO> Composite orders this table declares */
    private array $declaredSortOrders;

    /**
     * @param array<string, string> $declaredSortableFields Sortable fields the table declares
     * @param array<string, TableSortOrderDTO> $declaredSortOrders Composite orders the table declares
     */
    public function __construct(array $declaredSortableFields = [], array $declaredSortOrders = [])
    {
        $this->declaredSortableFields = $declaredSortableFields;
        $this->declaredSortOrders = $declaredSortOrders;

        parent::__construct();
    }

    /**
     * Configures the row class so makeRows() can rebuild typed rows.
     */
    protected function init(): void
    {
        $this->setRowClass(SortGateUnitRow::class);
    }

    /**
     * @return array<string, string> Sortable fields injected by the test
     */
    protected function sortableFields(): array
    {
        return $this->declaredSortableFields;
    }

    /**
     * @return array<string, string> Searched fields, so the gate lets a term through to the query
     */
    protected function searchableFields(): array
    {
        return [SortGateUnitRow::LABEL => 'row_label'];
    }

    /**
     * @return array<string, TableSortOrderDTO> Composite orders injected by the test
     */
    protected function sortOrders(): array
    {
        return $this->declaredSortOrders;
    }

    /**
     * Records the query the gate produced and answers with one fixed row.
     *
     * @param TableQueryDTO $query Window query as the gate left it
     * @return TableSnapshotDTO One-row snapshot
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $this->received = $query;

        return new TableSnapshotDTO(
            rows: [[SortGateUnitRow::KEY => 'a', SortGateUnitRow::LABEL => 'Alpha', SortGateUnitRow::CHANNEL => 'email']],
            totalCount: 1,
            limit: $query->limit,
        );
    }
}

final class SortGateUnitRow extends AbstractTableRow
{
    /** Row field: the stable row key. */
    public const string KEY = 'key';

    /** Row field: the one field the test table declares sortable. */
    public const string LABEL = 'label';

    /** Row field: the second field a composite order is declared over. */
    public const string CHANNEL = 'channel';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $channel,
    ) {
    }

    /**
     * @return string Stable row key
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
        return self::KEY;
    }

    /**
     * @return array<string, mixed> Row fields
     */
    public function toArray(): array
    {
        return [
            self::KEY => $this->key,
            self::LABEL => $this->label,
            self::CHANNEL => $this->channel,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static Row instance
     */
    public static function fromArray(array $data): static
    {
        return new static(
            (string) $data[self::KEY],
            (string) $data[self::LABEL],
            (string) $data[self::CHANNEL],
        );
    }
}
