<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Table;

use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\TableConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the sort DTO's resolved column staying off the wire (HIL-561).
 *
 * The column is the one thing on this DTO the client never says and never hears: it is
 * put there by a gate, out of a map written in PHP. A column that could be read from a
 * payload would make the gate decorative, and one that shipped back would tell the browser
 * a table's SQL for no reason at all.
 */
final class TableSortDTOTest extends TestCase
{
    public function testTheWireFormCarriesNoColumn(): void
    {
        $sort = new TableSortDTO('promptPiece', TableConstants::ORDER_DESC, 'prompt_piece');

        self::assertSame(
            [TableSortDTO::FIELD => 'promptPiece', TableSortDTO::DIRECTION => TableConstants::ORDER_DESC],
            $sort->toArray(),
        );
    }

    public function testAPayloadCannotNameAColumn(): void
    {
        $sort = TableSortDTO::fromWire([
            TableSortDTO::FIELD => 'promptPiece',
            TableSortDTO::DIRECTION => TableConstants::ORDER_DESC,
            'column' => 'prompt_piece` DESC, (SELECT 1)',
        ]);

        self::assertNotNull($sort);
        self::assertNull($sort->column);
    }

    public function testResolvingAColumnLeavesTheRequestItself(): void
    {
        $requested = new TableSortDTO('promptPiece', TableConstants::ORDER_DESC);

        $resolved = $requested->withColumn('prompt_piece');

        self::assertSame('promptPiece', $resolved->field);
        self::assertSame(TableConstants::ORDER_DESC, $resolved->direction);
        self::assertSame('prompt_piece', $resolved->column);
        self::assertNull($requested->column);
    }
}
