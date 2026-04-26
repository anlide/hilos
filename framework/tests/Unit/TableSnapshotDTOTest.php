<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Row\GenericTableRow;
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
}

