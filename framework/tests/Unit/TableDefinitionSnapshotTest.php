<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Row\GenericTableRow;
use Hilos\Core\Table\TableConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the table full snapshot API.
 */
final class TableDefinitionSnapshotTest extends TestCase
{
    public function testGetFullSnapshotReturnsTypedRowsAndMetadata(): void
    {
        $snapshot = $this->makeTable()->getFullSnapshot();

        $this->assertSame(1, $snapshot->totalCount);
        $this->assertSame(0, $snapshot->offset);
        $this->assertSame(TableConstants::NO_LIMIT, $snapshot->limit);
        $this->assertCount(1, $snapshot->rows);
        $this->assertInstanceOf(GenericTableRow::class, $snapshot->rows[0]);
        $this->assertSame(['id' => 1, 'name' => 'Ada'], $snapshot->rows[0]->toArray());
    }

    public function testGetPageRunsTheQueryAndReturnsTypedWindowRows(): void
    {
        $snapshot = $this->makeTable()->getPage(new TableQueryDTO(offset: 10, limit: 5));

        $this->assertSame(10, $snapshot->offset);
        $this->assertSame(5, $snapshot->limit);
        $this->assertSame(1, $snapshot->totalCount);
        $this->assertCount(1, $snapshot->rows);
        $this->assertInstanceOf(GenericTableRow::class, $snapshot->rows[0]);
        $this->assertSame(['id' => 1, 'name' => 'Ada'], $snapshot->rows[0]->toArray());
    }

    private function makeTable(): TableDefinition
    {
        return new class extends TableDefinition {
            /**
             * Returns a single-row table snapshot for tests.
             *
             * @param TableQueryDTO $query Query parameters created by getFullSnapshot()
             * @return TableSnapshotDTO Raw snapshot rows and metadata
             */
            protected function query(TableQueryDTO $query): TableSnapshotDTO
            {
                return new TableSnapshotDTO(
                    rows: [['id' => 1, 'name' => 'Ada']],
                    totalCount: 1,
                    offset: $query->offset,
                    limit: $query->limit,
                );
            }
        };
    }
}

