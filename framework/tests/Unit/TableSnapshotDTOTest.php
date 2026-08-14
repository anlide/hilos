<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Row\GenericTableRow;
use Hilos\Core\Table\TableConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the table snapshot wire payload.
 */
final class TableSnapshotDTOTest extends TestCase
{
    public function testToArrayUsesSnapshotPayloadShape(): void
    {
        $snapshot = new TableSnapshotDTO(
            rows: [GenericTableRow::fromArray(['id' => 1, 'name' => 'Ada'])],
            totalCount: 1,
            offset: 0,
            limit: 0,
        );

        $this->assertSame([
            'rows' => [['id' => 1, 'name' => 'Ada']],
            'totalCount' => 1,
            'offset' => 0,
            'limit' => 0,
        ], $snapshot->toArray());
    }

    public function testFromArrayRebuildsGenericRows(): void
    {
        $snapshot = TableSnapshotDTO::fromArray([
            'rows' => [['id' => 1, 'name' => 'Ada']],
            'totalCount' => 1,
            'offset' => 0,
            'limit' => 0,
        ]);

        $this->assertCount(1, $snapshot->rows);
        $this->assertInstanceOf(GenericTableRow::class, $snapshot->rows[0]);
        $this->assertSame(['id' => 1, 'name' => 'Ada'], $snapshot->rows[0]->toArray());
    }

    public function testFromArrayKeepsTheWindowItWasSerializedWith(): void
    {
        $snapshot = TableSnapshotDTO::fromArray(
            new TableSnapshotDTO(rows: [], totalCount: 91, offset: 50, limit: 25)->toArray(),
        );

        $this->assertSame(91, $snapshot->totalCount);
        $this->assertSame(50, $snapshot->offset);
        $this->assertSame(25, $snapshot->limit);
    }

    public function testFromArrayRefusesAPayloadWithoutTheWindowSize(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(TableConstants::RESULT_KEY_LIMIT);

        TableSnapshotDTO::fromArray([
            TableConstants::RESULT_KEY_ROWS => [],
            TableConstants::RESULT_KEY_TOTAL_COUNT => 0,
            TableConstants::RESULT_KEY_OFFSET => 0,
        ]);
    }

    public function testFromArrayRefusesARowThatIsNotAnArrayInsteadOfEmptyingIt(): void
    {
        $this->expectException(InvalidFormatException::class);

        TableSnapshotDTO::fromArray([
            TableConstants::RESULT_KEY_ROWS => ['Ada'],
            TableConstants::RESULT_KEY_TOTAL_COUNT => 1,
            TableConstants::RESULT_KEY_OFFSET => 0,
            TableConstants::RESULT_KEY_LIMIT => TableConstants::NO_LIMIT,
        ]);
    }
}

