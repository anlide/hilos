<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Table;

use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the default {@see TableDefinition::anchorForRow()} (HIL-793).
 *
 * The default answers for the table windowed in memory: the place of a row is the values of
 * the fields that order the window, with the row key settling what those values leave tied.
 * The rest of the tests pin the other answer — the two shapes of "this table cannot say",
 * which are what keeps a viewport from remembering a place that was never computed from
 * anything and then reporting a shown row as having moved.
 */
final class TableDefinitionRowAnchorTest extends TestCase
{
    public function testTheAnchorCarriesTheOrderedFieldAndTheRowKey(): void
    {
        $table = new RowAnchorUnitTable();

        $anchor = $table->anchorForRow(
            new RowAnchorUnitRow('a', 'Alpha'),
            new TableQueryDTO(sort: self::byLabel(TableConstants::ORDER_ASC)),
        );

        self::assertNotNull($anchor);
        self::assertSame(
            [RowAnchorUnitRow::LABEL => 'Alpha', RowAnchorUnitRow::KEY => 'a'],
            $anchor->values,
        );
    }

    public function testTheAnchorNamesAPlaceAndNotADirection(): void
    {
        $table = new RowAnchorUnitTable();

        $anchor = $table->anchorForRow(
            new RowAnchorUnitRow('a', 'Alpha'),
            new TableQueryDTO(sort: self::byLabel(TableConstants::ORDER_DESC)),
        );

        // Which way the order runs is read when the anchor is compared against, not when it is
        // written: the same row in a descending window sits at the same values, and only
        // placeRowAgainst() turns them into a sign.
        self::assertNotNull($anchor);
        self::assertSame(
            [RowAnchorUnitRow::LABEL => 'Alpha', RowAnchorUnitRow::KEY => 'a'],
            $anchor->values,
        );
    }

    public function testAWindowThatAskedForNoOrderHasNoPlaceToName(): void
    {
        $table = new RowAnchorUnitTable();

        $anchor = $table->anchorForRow(new RowAnchorUnitRow('a', 'Alpha'), new TableQueryDTO());

        // Such a window is held in the row source's own sequence, and no values of the row
        // reproduce it. There is no place to name, not a place that was not found.
        self::assertNull($anchor);
    }

    public function testARowMissingAFieldOfTheOrderCannotBeAnchored(): void
    {
        $table = new RowAnchorUnitTable();

        $anchor = $table->anchorForRow(
            new RowAnchorUnitRow('a', 'Alpha'),
            new TableQueryDTO(sort: TableSortOrderDTO::of(
                new TableSortDTO(RowAnchorUnitRow::LABEL),
                new TableSortDTO('channel'),
            )),
        );

        // This is the table anchoring in its own columns: the order is settled by a field its
        // row payload does not carry. An anchor written without it would read that field as
        // null on every row and put them all in one place.
        self::assertNull($anchor);
    }

    /**
     * @param string $direction Direction the one-column order runs in
     * @return TableSortOrderDTO Order over the row label
     */
    private static function byLabel(string $direction): TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(RowAnchorUnitRow::LABEL, $direction));
    }
}

final class RowAnchorUnitTable extends TableDefinition
{
    /**
     * Configures the row class the default anchor reads its key field from.
     */
    protected function init(): void
    {
        $this->setRowClass(RowAnchorUnitRow::class);
    }

    /**
     * @param TableQueryDTO $query Window query
     * @return TableSnapshotDTO Empty snapshot: the anchor never reaches the row source
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return new TableSnapshotDTO(rows: [], totalCount: 0, limit: $query->limit);
    }
}

final class RowAnchorUnitRow extends AbstractTableRow
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
