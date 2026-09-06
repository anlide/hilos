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
use Hilos\Core\Table\TableConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the default {@see TableDefinition::placeRowAgainst()} (HIL-791).
 *
 * The default answers for the table windowed in memory: the place of a row is read off the
 * same fields the window was sorted by, so the sign follows the direction the order runs in.
 * What the rest of the tests pin is the other answer — the two shapes of "this table cannot
 * say", which are what keeps a table anchoring in its own names from being placed against a
 * comparison that was never about its columns.
 */
final class TableDefinitionRowPlacementTest extends TestCase
{
    public function testARowSortedAboveTheAnchorPlacesAboveIt(): void
    {
        $table = new RowPlacementUnitTable();

        $placement = $table->placeRowAgainst(
            new RowPlacementUnitRow('a', 'Alpha'),
            new TableAnchorDTO([RowPlacementUnitRow::LABEL => 'Mike', RowPlacementUnitRow::KEY => 'm']),
            new TableQueryDTO(sort: self::byLabel(TableConstants::ORDER_ASC)),
        );

        self::assertNotNull($placement);
        self::assertLessThan(0, $placement);
    }

    public function testTheSameRowPlacesBelowTheAnchorWhenTheOrderRunsTheOtherWay(): void
    {
        $table = new RowPlacementUnitTable();

        $placement = $table->placeRowAgainst(
            new RowPlacementUnitRow('a', 'Alpha'),
            new TableAnchorDTO([RowPlacementUnitRow::LABEL => 'Mike', RowPlacementUnitRow::KEY => 'm']),
            new TableQueryDTO(sort: self::byLabel(TableConstants::ORDER_DESC)),
        );

        // The place is a place in the window's order, not in the alphabet: the same two values
        // named the same way answer opposite signs, and a caller reading the sign alone would
        // send a row to the top of a descending window that its reload puts at the bottom.
        self::assertNotNull($placement);
        self::assertGreaterThan(0, $placement);
    }

    public function testARowCarryingTheAnchorsOwnValuesPlacesAtIt(): void
    {
        $table = new RowPlacementUnitTable();

        $placement = $table->placeRowAgainst(
            new RowPlacementUnitRow('m', 'Mike'),
            new TableAnchorDTO([RowPlacementUnitRow::LABEL => 'Mike', RowPlacementUnitRow::KEY => 'm']),
            new TableQueryDTO(sort: self::byLabel(TableConstants::ORDER_ASC)),
        );

        self::assertSame(0, $placement);
    }

    public function testTheRowKeySettlesAPlaceTwoRowsWouldOtherwiseShare(): void
    {
        $table = new RowPlacementUnitTable();

        $placement = $table->placeRowAgainst(
            new RowPlacementUnitRow('z', 'Mike'),
            new TableAnchorDTO([RowPlacementUnitRow::LABEL => 'Mike', RowPlacementUnitRow::KEY => 'm']),
            new TableQueryDTO(sort: self::byLabel(TableConstants::ORDER_ASC)),
        );

        // The order is total (HIL-786), so two rows sharing the sorted value still sit one
        // after the other, and the row key is what says which of the two comes first.
        self::assertNotNull($placement);
        self::assertGreaterThan(0, $placement);
    }

    public function testAWindowThatAskedForNoOrderCannotSayWhereARowBelongs(): void
    {
        $table = new RowPlacementUnitTable();

        $placement = $table->placeRowAgainst(
            new RowPlacementUnitRow('a', 'Alpha'),
            new TableAnchorDTO([RowPlacementUnitRow::KEY => 'm']),
            new TableQueryDTO(),
        );

        // Such a window is held in the row source's own sequence, and no comparison of field
        // values reproduces it. There is no place to answer, not a place that was not found.
        self::assertNull($placement);
    }

    public function testAnAnchorWrittenInTheTablesOwnColumnsCannotBePlacedAgainst(): void
    {
        $table = new RowPlacementUnitTable();

        $placement = $table->placeRowAgainst(
            new RowPlacementUnitRow('a', 'Alpha'),
            new TableAnchorDTO(['row_label' => 'Mike', 'id' => 'm']),
            new TableQueryDTO(sort: self::byLabel(TableConstants::ORDER_ASC)),
        );

        // This is the delivery-logs table's shape: its boundaries are its SQL columns, and its
        // row payload names the same values differently. Placing across the two would read
        // every missing value as null and answer with a sign computed from nothing.
        self::assertNull($placement);
    }

    public function testAnAnchorTakenWithoutTheOrderItIsAskedAboutCannotBePlacedAgainst(): void
    {
        $table = new RowPlacementUnitTable();

        $placement = $table->placeRowAgainst(
            new RowPlacementUnitRow('a', 'Alpha'),
            new TableAnchorDTO([RowPlacementUnitRow::KEY => 'm']),
            new TableQueryDTO(sort: self::byLabel(TableConstants::ORDER_ASC)),
        );

        // A window served without this order carries only the row key at its boundaries. The
        // order asking about it was refused somewhere the boundary cannot see - the sort gate,
        // or a table that never offered the field - and the anchor is not of this order at all.
        self::assertNull($placement);
    }

    public function testARowMissingAFieldOfTheOrderCannotBePlaced(): void
    {
        $table = new RowPlacementUnitTable();

        $placement = $table->placeRowAgainst(
            new RowPlacementUnitRow('a', 'Alpha'),
            new TableAnchorDTO([
                RowPlacementUnitRow::LABEL => 'Mike',
                'channel' => 'email',
                RowPlacementUnitRow::KEY => 'm',
            ]),
            new TableQueryDTO(sort: TableSortOrderDTO::of(
                new TableSortDTO(RowPlacementUnitRow::LABEL),
                new TableSortDTO('channel'),
            )),
        );

        self::assertNull($placement);
    }

    /**
     * @param string $direction Direction the one-column order runs in
     * @return TableSortOrderDTO Order over the row label
     */
    private static function byLabel(string $direction): TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(RowPlacementUnitRow::LABEL, $direction));
    }
}

final class RowPlacementUnitTable extends TableDefinition
{
    /**
     * Configures the row class the default placement reads its key field from.
     */
    protected function init(): void
    {
        $this->setRowClass(RowPlacementUnitRow::class);
    }

    /**
     * @param TableQueryDTO $query Window query
     * @return TableSnapshotDTO Empty snapshot: the placement never reaches the row source
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return new TableSnapshotDTO(rows: [], totalCount: 0, limit: $query->limit);
    }
}

final class RowPlacementUnitRow extends AbstractTableRow
{
    /** Row field: the stable row key. */
    public const string KEY = 'key';

    /** Row field: the one field the windows in these tests are ordered by. */
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
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static Row instance
     */
    public static function fromArray(array $data): static
    {
        return new static((string) $data[self::KEY], (string) $data[self::LABEL]);
    }
}
