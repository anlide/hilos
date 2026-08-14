<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableViewportAppendDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the server-to-client table viewport append payload.
 */
final class TableViewportAppendDTOTest extends TestCase
{
    public function testRoundTripPreservesTheRowAndCounts(): void
    {
        $row = ['rowKey' => 'a', 'slots' => ['settings' => ['key' => 'a']]];
        $restored = TableViewportAppendDTO::fromArray(
            new TableViewportAppendDTO('hilos_settings', 'settings', $row, 91, 1)->toArray(),
        );

        $this->assertSame('hilos_settings', $restored->page);
        $this->assertSame('settings', $restored->tableKey);
        $this->assertSame($row, $restored->row);
        $this->assertSame(91, $restored->totalCount);
        $this->assertSame(1, $restored->pageCount);
    }

    public function testFromArrayRefusesAnEmptyPayloadInsteadOfAppendingAnEmptyRow(): void
    {
        $this->expectException(InvalidFormatException::class);

        TableViewportAppendDTO::fromArray([]);
    }

    public function testFromArrayRefusesAPayloadWithoutTheRowAndNamesTheKey(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(TableViewportAppendDTO::row);

        TableViewportAppendDTO::fromArray([
            TableViewportAppendDTO::page => 'hilos_settings',
            TableViewportAppendDTO::tableKey => 'settings',
            TableViewportAppendDTO::totalCount => 91,
            TableViewportAppendDTO::pageCount => 1,
        ]);
    }
}
