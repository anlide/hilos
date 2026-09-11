<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableProgressDTO;
use Hilos\Core\Table\DTO\TableProgressSignalData;
use Hilos\Core\Table\TableProgressScope;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the progress bar a table declares and for the frame that addresses it.
 */
final class TableProgressDTOTest extends TestCase
{
    public function testARowBarKeepsItsRowItsNumbersAndItsDetailThroughTheWire(): void
    {
        $restored = TableProgressDTO::fromArray(
            new TableProgressDTO(
                TableProgressScope::Row,
                'backup-17',
                'a',
                3,
                11,
                false,
                ['caption' => 'packing'],
            )->toArray(),
        );

        $this->assertSame(TableProgressScope::Row, $restored->scope);
        $this->assertSame('backup-17', $restored->progressKey);
        $this->assertSame('a', $restored->rowKey);
        $this->assertSame(3, $restored->current);
        $this->assertSame(11, $restored->total);
        $this->assertFalse($restored->ended);
        $this->assertSame(['caption' => 'packing'], $restored->detail);
    }

    public function testATableBarTravelsWithoutARowAndSaysItEnded(): void
    {
        $restored = TableProgressDTO::fromArray(
            new TableProgressDTO(TableProgressScope::Table, 'nightly', null, 120, 120, true)->toArray(),
        );

        $this->assertSame(TableProgressScope::Table, $restored->scope);
        $this->assertNull($restored->rowKey);
        $this->assertTrue($restored->ended);
        $this->assertSame([], $restored->detail);
    }

    public function testABulkBarWithNoEstimateCarriesNoTotalAtAll(): void
    {
        $wire = new TableProgressDTO(TableProgressScope::Bulk, 'delete-40', null, 12, null)->toArray();

        $this->assertArrayNotHasKey(TableProgressDTO::total, $wire);
        $this->assertArrayNotHasKey(TableProgressDTO::rowKey, $wire);
        $this->assertArrayNotHasKey(TableProgressDTO::ended, $wire);
        $this->assertArrayNotHasKey(TableProgressDTO::detail, $wire);

        $restored = TableProgressDTO::fromArray($wire);

        $this->assertSame(TableProgressScope::Bulk, $restored->scope);
        $this->assertNull($restored->total);
        $this->assertFalse($restored->ended);
    }

    public function testARowBarWithoutItsRowIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(TableProgressDTO::rowKey);

        new TableProgressDTO(TableProgressScope::Row, 'backup-17', null, 3, 11);
    }

    public function testATableBarNamingARowIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(TableProgressDTO::rowKey);

        new TableProgressDTO(TableProgressScope::Table, 'nightly', 'a', 34, 120);
    }

    public function testFromArrayRefusesAPlaceNoBarStandsIn(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(TableProgressDTO::scope);

        TableProgressDTO::fromArray([
            TableProgressDTO::scope => 'footer',
            TableProgressDTO::progressKey => 'backup-17',
            TableProgressDTO::current => 3,
        ]);
    }

    public function testFromArrayRefusesAnEmptyPayloadInsteadOfABarAboutNothing(): void
    {
        $this->expectException(InvalidFormatException::class);

        TableProgressDTO::fromArray([]);
    }

    public function testTheAddressedFrameCarriesThePageTheTableAndTheBarFlat(): void
    {
        $wire = TableProgressSignalData::fromProgress(
            'hilos_backup',
            'backupHistory',
            new TableProgressDTO(TableProgressScope::Row, 'backup-17', 'a', 3, 11),
        )->toArray();

        $this->assertSame('hilos_backup', $wire[TableProgressSignalData::page]);
        $this->assertSame('backupHistory', $wire[TableProgressSignalData::tableKey]);
        $this->assertSame(TableProgressScope::Row->value, $wire[TableProgressDTO::scope]);
        $this->assertSame('a', $wire[TableProgressDTO::rowKey]);

        $restored = TableProgressSignalData::fromArray($wire);

        $this->assertSame('hilos_backup', $restored->page);
        $this->assertSame('backupHistory', $restored->tableKey);
        $this->assertSame('backup-17', $restored->progress->progressKey);
        $this->assertSame(11, $restored->progress->total);
    }
}
