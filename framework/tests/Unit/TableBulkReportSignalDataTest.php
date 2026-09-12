<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableBulkAcceptedReplyDTO;
use Hilos\Core\Table\DTO\TableBulkReportSignalData;
use Hilos\Core\Table\DTO\TableBulkUntouchedDTO;
use Hilos\Core\Table\TableConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for what a bulk action answers with: the acceptance at once, and the report at the end.
 */
final class TableBulkReportSignalDataTest extends TestCase
{
    public function testTheReportNamesEveryUntouchedRowWithItsReason(): void
    {
        $restored = TableBulkReportSignalData::fromArray(
            new TableBulkReportSignalData(
                'hilos_users',
                'users',
                'bulk-7',
                39,
                [new TableBulkUntouchedDTO('a', TableConstants::BULK_REASON_ROW_GONE)],
            )->toArray(),
        );

        $this->assertSame('hilos_users', $restored->page);
        $this->assertSame('users', $restored->tableKey);
        $this->assertSame('bulk-7', $restored->progressKey);
        $this->assertSame(39, $restored->touched);
        $this->assertCount(1, $restored->untouched);
        $this->assertSame('a', $restored->untouched[0]->rowKey);
        $this->assertSame(TableConstants::BULK_REASON_ROW_GONE, $restored->untouched[0]->reason);
        $this->assertNull($restored->untouchedOmitted);
    }

    public function testAReportThatOmittedNoNamesCarriesNoCountOfThem(): void
    {
        $wire = new TableBulkReportSignalData('hilos_users', 'users', 'bulk-7', 40, [])->toArray();

        $this->assertArrayNotHasKey(TableBulkReportSignalData::untouchedOmitted, $wire);
        $this->assertSame([], $wire[TableBulkReportSignalData::untouched]);
    }

    public function testAReportPastTheNameCeilingSaysHowManyItLeftOut(): void
    {
        $wire = new TableBulkReportSignalData(
            'hilos_users',
            'users',
            'bulk-7',
            0,
            [new TableBulkUntouchedDTO('a', TableConstants::BULK_REASON_NO_VERDICT)],
            12,
        )->toArray();

        $this->assertSame(12, $wire[TableBulkReportSignalData::untouchedOmitted]);
        $this->assertSame(12, TableBulkReportSignalData::fromArray($wire)->untouchedOmitted);
    }

    public function testAReportWithoutItsListIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        TableBulkReportSignalData::fromArray([
            TableBulkReportSignalData::page => 'hilos_users',
            TableBulkReportSignalData::tableKey => 'users',
            TableBulkReportSignalData::progressKey => 'bulk-7',
            TableBulkReportSignalData::touched => 1,
        ]);
    }

    public function testALineOfTheListWithoutItsReasonIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        TableBulkReportSignalData::fromArray([
            TableBulkReportSignalData::page => 'hilos_users',
            TableBulkReportSignalData::tableKey => 'users',
            TableBulkReportSignalData::progressKey => 'bulk-7',
            TableBulkReportSignalData::touched => 1,
            TableBulkReportSignalData::untouched => [[TableBulkUntouchedDTO::rowKey => 'a']],
        ]);
    }

    public function testTheAcceptanceCarriesTheRunAndItsHonestTotal(): void
    {
        $restored = TableBulkAcceptedReplyDTO::fromArray(
            new TableBulkAcceptedReplyDTO('bulk-7', 40)->toArray(),
        );

        $this->assertSame('bulk-7', $restored->progressKey);
        $this->assertSame(40, $restored->total);
    }

    public function testAnAcceptanceWithNoHonestTotalCarriesNoTotalAtAll(): void
    {
        $wire = new TableBulkAcceptedReplyDTO('bulk-7', null)->toArray();

        $this->assertArrayNotHasKey(TableBulkAcceptedReplyDTO::total, $wire);
        $this->assertNull(TableBulkAcceptedReplyDTO::fromArray($wire)->total);
    }
}
