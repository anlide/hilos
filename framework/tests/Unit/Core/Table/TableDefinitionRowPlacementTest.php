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

    public function testNullPolicyOverrideControlsWindowsAnchorsAndRowPlacementTogether(): void
    {
        foreach ([new RowPlacementUnitTable(), new NullsLastRowPlacementUnitTable()] as $policy => $table) {
            foreach ([TableConstants::ORDER_ASC, TableConstants::ORDER_DESC] as $direction) {
                $query = new TableQueryDTO(sort: self::byLabel($direction));
                $keys = $direction === TableConstants::ORDER_ASC
                    ? ['a', 'b', 'c', 'd', 'e', 'f']
                    : ($policy === 1 ? ['d', 'c', 'b', 'a', 'f', 'e'] : ['f', 'e', 'd', 'c', 'b', 'a']);
                $whole = $table->getPage($query);
                self::assertSame($keys, array_map(static fn(AbstractTableRow $row): string|int => $row->getRowKey(), $whole->rows));
                $forward = [];
                $backward = [];
                $after = null;
                $before = null;
                for ($page = 0; $page < 3; $page++) {
                    $numbered = $table->getPage(new TableQueryDTO(sort: $query->sort, limit: 2, pageIndex: $page));
                    $next = $table->getPage(new TableQueryDTO(sort: $query->sort, limit: 2, anchor: $after));
                    $previous = $table->getPage(new TableQueryDTO(
                        sort: $query->sort, limit: 2, anchor: $before, anchorDirection: TableAnchorDirection::Before,
                    ));
                    $nextKeys = array_map(static fn(AbstractTableRow $row): string|int => $row->getRowKey(), $next->rows);
                    self::assertSame(array_slice($keys, $page * 2, 2), $nextKeys);
                    self::assertEquals($numbered->rows, $next->rows);
                    array_push($forward, ...$nextKeys);
                    $backward = [...array_map(static fn(AbstractTableRow $row): string|int => $row->getRowKey(), $previous->rows), ...$backward];
                    self::assertNotNull($numbered->frame);
                    foreach ($whole->rows as $index => $row) {
                        $anchor = $table->anchorForRow($row, $query);
                        self::assertNotNull($anchor);
                        self::assertSame(['label', 'key'], array_keys($anchor->values));
                        foreach ($whole->rows as $otherIndex => $other) {
                            self::assertSame($otherIndex <=> $index, $table->placeRowAgainst($other, $anchor, $query) <=> 0);
                        }
                        if ($numbered->frame->before !== null) {
                            self::assertSame(
                                $index <=> ($page * 2 - 1),
                                $table->placeRowAgainst($row, $numbered->frame->before, $query) <=> 0,
                            );
                        }
                        if ($numbered->frame->after !== null) {
                            self::assertSame(
                                $index <=> ($page * 2 + 2),
                                $table->placeRowAgainst($row, $numbered->frame->after, $query) <=> 0,
                            );
                        }
                    }
                    $after = $next->lastAnchor;
                    $before = $previous->firstAnchor;
                }
                self::assertSame($keys, $forward);
                self::assertSame($keys, $backward);
            }
        }
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

class RowPlacementUnitTable extends TableDefinition
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
     * @return TableSnapshotDTO Repeated values and nulls for window and placement comparisons
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory([
            ['key' => 'f', 'label' => null],
            ['key' => 'c', 'label' => '200000'],
            ['key' => 'b', 'label' => '100000'],
            ['key' => 'e', 'label' => null],
            ['key' => 'd', 'label' => '200000'],
            ['key' => 'a', 'label' => '100000'],
        ], $query);
    }
}

final class NullsLastRowPlacementUnitTable extends RowPlacementUnitTable
{
    /** @return bool Null values follow known values in both directions */
    protected function inMemoryNullsLast(): bool
    {
        return true;
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
        public readonly ?string $label,
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
        return new static((string) $data[self::KEY], $data[self::LABEL]);
    }
}
