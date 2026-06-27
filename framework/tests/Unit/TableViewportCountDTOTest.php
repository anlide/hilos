<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Table\DTO\TableViewportCountDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the server-to-client table viewport count payload.
 */
final class TableViewportCountDTOTest extends TestCase
{
    public function testRoundTripPreservesTheCounts(): void
    {
        $restored = TableViewportCountDTO::fromArray(
            (new TableViewportCountDTO('hilos_settings', 'settings', 42, 5))->toArray(),
        );

        $this->assertSame('hilos_settings', $restored->page);
        $this->assertSame('settings', $restored->tableKey);
        $this->assertSame(42, $restored->totalCount);
        $this->assertSame(5, $restored->pageCount);
    }

    public function testFromArrayDefaultsToZeroCounts(): void
    {
        $dto = TableViewportCountDTO::fromArray([]);

        $this->assertSame('', $dto->page);
        $this->assertSame('', $dto->tableKey);
        $this->assertSame(0, $dto->totalCount);
        $this->assertSame(0, $dto->pageCount);
    }
}
