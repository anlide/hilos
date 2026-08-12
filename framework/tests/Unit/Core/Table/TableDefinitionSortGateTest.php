<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Table;

use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the sort gate {@see TableDefinition::getPage()} runs (HIL-561).
 *
 * The gate is placed where every table's row source is reached from, so what the tests
 * inspect is the query the concrete table is handed: a declared field arrives with the
 * column it may order by, a field the table does not sort by does not arrive at all, and
 * everything else about the window is passed on untouched.
 */
final class TableDefinitionSortGateTest extends TestCase
{
    public function testADeclaredFieldReachesTheQueryCarryingItsColumn(): void
    {
        $table = new SortGateUnitTable([SortGateUnitRow::LABEL => 'row_label']);

        $table->getPage(new TableQueryDTO(sort: new TableSortDTO(SortGateUnitRow::LABEL, TableConstants::ORDER_DESC)));

        $sort = $table->received?->sort;
        self::assertNotNull($sort);
        self::assertSame(SortGateUnitRow::LABEL, $sort->field);
        self::assertSame(TableConstants::ORDER_DESC, $sort->direction);
        self::assertSame('row_label', $sort->column);
    }

    public function testAFieldTheTableDoesNotSortByLeavesTheWindowInItsDefaultOrder(): void
    {
        $table = new SortGateUnitTable([SortGateUnitRow::LABEL => 'row_label']);

        ob_start();
        $table->getPage(new TableQueryDTO(sort: new TableSortDTO('label` DESC, (SELECT 1)')));
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
            sort: new TableSortDTO(SortGateUnitRow::LABEL),
            offset: 20,
            limit: 10,
            filter: ['channel' => 'email'],
        ));

        self::assertNotNull($table->received);
        self::assertSame('alpha', $table->received->search);
        self::assertSame(20, $table->received->offset);
        self::assertSame(10, $table->received->limit);
        self::assertSame(['channel' => 'email'], $table->received->filter);
    }

    public function testATableThatDeclaresNoSortableFieldsSortsAsItAlwaysHas(): void
    {
        $table = new SortGateUnitTable();
        $sort = new TableSortDTO(SortGateUnitRow::LABEL);

        $table->getPage(new TableQueryDTO(sort: $sort));

        // Its rows are ordered in PHP, where the field is an array key and no identifier
        // is built from it, so the gate has nothing to protect and does not interfere.
        self::assertSame($sort, $table->received?->sort);
    }
}

final class SortGateUnitTable extends TableDefinition
{
    /** Query the concrete table was handed, or null while getPage() has not run. */
    public ?TableQueryDTO $received = null;

    /** @var array<string, string> Sortable fields this table declares */
    private array $declaredSortableFields;

    /**
     * @param array<string, string> $declaredSortableFields Sortable fields the table declares
     */
    public function __construct(array $declaredSortableFields = [])
    {
        $this->declaredSortableFields = $declaredSortableFields;

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
     * Records the query the gate produced and answers with one fixed row.
     *
     * @param TableQueryDTO $query Window query as the gate left it
     * @return TableSnapshotDTO One-row snapshot
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $this->received = $query;

        return new TableSnapshotDTO(
            rows: [[SortGateUnitRow::KEY => 'a', SortGateUnitRow::LABEL => 'Alpha']],
            totalCount: 1,
            offset: $query->offset,
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

    public function __construct(
        public readonly string $key,
        public readonly string $label,
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
     * @return array<string, mixed> Row fields
     */
    public function toArray(): array
    {
        return [
            self::KEY => $this->key,
            self::LABEL => $this->label,
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
        );
    }
}
