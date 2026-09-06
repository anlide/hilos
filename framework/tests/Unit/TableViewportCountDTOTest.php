<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableViewportCountDTO;
use Hilos\Core\Table\TableConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the server-to-client table viewport count payload.
 */
final class TableViewportCountDTOTest extends TestCase
{
    public function testRoundTripPreservesTheCounts(): void
    {
        $restored = TableViewportCountDTO::fromArray(
            new TableViewportCountDTO('hilos_settings', 'settings', 42, true, 5)->toArray(),
        );

        $this->assertSame('hilos_settings', $restored->page);
        $this->assertSame('settings', $restored->tableKey);
        $this->assertSame(42, $restored->totalCount);
        $this->assertTrue($restored->totalExact);
        $this->assertSame(5, $restored->pageCount);
    }

    public function testACountStoppedAtItsCeilingTravelsWithNoPageCountKeyAtAll(): void
    {
        $wire = new TableViewportCountDTO(
            'hilos_notification_deliveries',
            'deliveries',
            TableConstants::COUNT_CEILING,
            false,
            null,
        )->toArray();

        $this->assertArrayNotHasKey(TableViewportCountDTO::pageCount, $wire);

        $restored = TableViewportCountDTO::fromArray($wire);

        $this->assertSame(TableConstants::COUNT_CEILING, $restored->totalCount);
        $this->assertFalse($restored->totalExact);
        $this->assertNull($restored->pageCount);
    }

    public function testFromArrayRefusesAnEmptyPayloadInsteadOfCountingZero(): void
    {
        $this->expectException(InvalidFormatException::class);

        TableViewportCountDTO::fromArray([]);
    }

    public function testFromArrayRefusesAPayloadWithoutTheWordOnItsCountAndNamesTheKey(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(TableViewportCountDTO::totalExact);

        TableViewportCountDTO::fromArray([
            TableViewportCountDTO::page => 'hilos_settings',
            TableViewportCountDTO::tableKey => 'settings',
            TableViewportCountDTO::totalCount => 42,
            TableViewportCountDTO::pageCount => 5,
        ]);
    }
}
