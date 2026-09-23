<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableWindowFrameDTO;
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
            totalExact: true,
            limit: 0,
            firstAnchor: new TableAnchorDTO(['id' => 1]),
            lastAnchor: new TableAnchorDTO(['id' => 1]),
            rowsBefore: 0,
        );

        $this->assertSame([
            'rows' => [['id' => 1, 'name' => 'Ada']],
            'totalCount' => 1,
            'totalExact' => true,
            'limit' => 0,
            'firstAnchor' => ['id' => 1],
            'lastAnchor' => ['id' => 1],
            'rowsBefore' => 0,
        ], $snapshot->toArray());
    }

    public function testTheFrameOfTheWindowNeverReachesTheWire(): void
    {
        $snapshot = new TableSnapshotDTO(
            rows: [GenericTableRow::fromArray(['id' => 2])],
            totalCount: 3,
            limit: 1,
            firstAnchor: new TableAnchorDTO(['id' => 2]),
            lastAnchor: new TableAnchorDTO(['id' => 2]),
            rowsBefore: 1,
            frame: new TableWindowFrameDTO(new TableAnchorDTO(['id' => 1]), new TableAnchorDTO(['id' => 3])),
        );

        $payload = $snapshot->toArray();

        $this->assertArrayNotHasKey(TableConstants::RESULT_KEY_FRAME, $payload);
        $this->assertNull(TableSnapshotDTO::fromArray($payload)->frame);
    }

    public function testTheWindowsPlaceSurvivesTheRoundTrip(): void
    {
        $snapshot = TableSnapshotDTO::fromArray(
            new TableSnapshotDTO(
                rows: [],
                totalCount: 21,
                totalExact: true,
                limit: 10,
                rowsBefore: 11,
            )->toArray(),
        );

        $this->assertSame(11, $snapshot->rowsBefore);
    }

    public function testASetCountedOnlyToItsCeilingReportsNoPlaceForItsWindow(): void
    {
        $snapshot = TableSnapshotDTO::fromArray(
            new TableSnapshotDTO(
                rows: [],
                totalCount: TableConstants::COUNT_CEILING,
                totalExact: false,
                limit: 10,
            )->toArray(),
        );

        $this->assertNull($snapshot->rowsBefore);
    }

    public function testFromArrayRebuildsGenericRows(): void
    {
        $snapshot = TableSnapshotDTO::fromArray([
            'rows' => [['id' => 1, 'name' => 'Ada']],
            'totalCount' => 1,
            'totalExact' => true,
            'limit' => 0,
        ]);

        $this->assertCount(1, $snapshot->rows);
        $this->assertInstanceOf(GenericTableRow::class, $snapshot->rows[0]);
        $this->assertSame(['id' => 1, 'name' => 'Ada'], $snapshot->rows[0]->toArray());
    }

    public function testFromArrayKeepsTheWindowItWasSerializedWith(): void
    {
        $snapshot = TableSnapshotDTO::fromArray(
            new TableSnapshotDTO(
                rows: [],
                totalCount: 91,
                totalExact: true,
                limit: 25,
                firstAnchor: new TableAnchorDTO(['name' => 'Ada', 'id' => 50]),
                lastAnchor: new TableAnchorDTO(['name' => 'Zoe', 'id' => 74]),
            )->toArray(),
        );

        $this->assertSame(91, $snapshot->totalCount);
        $this->assertSame(25, $snapshot->limit);
        $this->assertSame(['name' => 'Ada', 'id' => 50], $snapshot->firstAnchor?->toArray());
        $this->assertSame(['name' => 'Zoe', 'id' => 74], $snapshot->lastAnchor?->toArray());
    }

    public function testAnEmptyWindowSerializesBothBoundariesAsNothing(): void
    {
        $snapshot = TableSnapshotDTO::fromArray(
            new TableSnapshotDTO(rows: [], totalCount: 0, totalExact: true, limit: 25)->toArray(),
        );

        $this->assertNull($snapshot->firstAnchor);
        $this->assertNull($snapshot->lastAnchor);
    }

    public function testFromArrayRefusesAPayloadWithoutTheWindowSize(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(TableConstants::RESULT_KEY_LIMIT);

        TableSnapshotDTO::fromArray([
            TableConstants::RESULT_KEY_ROWS => [],
            TableConstants::RESULT_KEY_TOTAL_COUNT => 0,
            TableConstants::RESULT_KEY_TOTAL_EXACT => true,
        ]);
    }

    public function testFromArrayRefusesAPayloadWithoutTheWordOnItsCount(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(TableConstants::RESULT_KEY_TOTAL_EXACT);

        TableSnapshotDTO::fromArray([
            TableConstants::RESULT_KEY_ROWS => [],
            TableConstants::RESULT_KEY_TOTAL_COUNT => TableConstants::COUNT_CEILING,
            TableConstants::RESULT_KEY_LIMIT => 25,
        ]);
    }

    public function testACountStoppedAtItsCeilingSurvivesTheRoundTrip(): void
    {
        $snapshot = TableSnapshotDTO::fromArray(
            new TableSnapshotDTO(
                rows: [],
                totalCount: TableConstants::COUNT_CEILING,
                totalExact: false,
                limit: 25,
            )->toArray(),
        );

        $this->assertSame(TableConstants::COUNT_CEILING, $snapshot->totalCount);
        $this->assertFalse($snapshot->totalExact);
    }

    public function testFromArrayRefusesARowThatIsNotAnArrayInsteadOfEmptyingIt(): void
    {
        $this->expectException(InvalidFormatException::class);

        TableSnapshotDTO::fromArray([
            TableConstants::RESULT_KEY_ROWS => ['Ada'],
            TableConstants::RESULT_KEY_TOTAL_COUNT => 1,
            TableConstants::RESULT_KEY_TOTAL_EXACT => true,
            TableConstants::RESULT_KEY_LIMIT => TableConstants::NO_LIMIT,
        ]);
    }
}

