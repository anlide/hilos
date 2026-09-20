<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\TableConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the server-to-client table window signal payload.
 */
final class TableWindowSignalDataTest extends TestCase
{
    public function testRoundTripPreservesTheWindow(): void
    {
        $rows = [
            ['rowKey' => 'a', 'sources' => ['settings' => ['key' => 'a']]],
            ['rowKey' => 'b', 'sources' => ['settings' => ['key' => 'b']]],
        ];
        $dto = new TableWindowSignalData(
            page: 'hilos_settings',
            tableKey: 'settings',
            rows: $rows,
            totalCount: 42,
            totalExact: true,
            limit: 10,
            firstAnchor: new TableAnchorDTO(['key' => 'a']),
            lastAnchor: new TableAnchorDTO(['key' => 'b']),
        );

        $restored = TableWindowSignalData::fromArray($dto->toArray());

        $this->assertSame('hilos_settings', $restored->page);
        $this->assertSame('settings', $restored->tableKey);
        $this->assertSame($rows, $restored->rows);
        $this->assertSame(42, $restored->totalCount);
        $this->assertTrue($restored->totalExact);
        $this->assertSame(10, $restored->limit);
        $this->assertSame(['key' => 'a'], $restored->firstAnchor?->toArray());
        $this->assertSame(['key' => 'b'], $restored->lastAnchor?->toArray());
    }

    public function testAnEmptyWindowTravelsWithNeitherBoundary(): void
    {
        $dto = new TableWindowSignalData(
            page: 'hilos_settings',
            tableKey: 'settings',
            rows: [],
            totalCount: 0,
            totalExact: true,
            limit: 10,
        );

        $restored = TableWindowSignalData::fromArray($dto->toArray());

        $this->assertNull($restored->firstAnchor);
        $this->assertNull($restored->lastAnchor);
    }

    public function testACountStoppedAtItsCeilingTravelsSayingSo(): void
    {
        $dto = new TableWindowSignalData(
            page: 'hilos_notification_deliveries',
            tableKey: 'deliveries',
            rows: [],
            totalCount: TableConstants::COUNT_CEILING,
            totalExact: false,
            limit: 25,
        );

        $restored = TableWindowSignalData::fromArray($dto->toArray());

        $this->assertSame(TableConstants::COUNT_CEILING, $restored->totalCount);
        $this->assertFalse($restored->totalExact);
    }

    public function testTheWindowSaysHowManyRowsStandBeforeIt(): void
    {
        $dto = new TableWindowSignalData(
            page: 'hilos_settings',
            tableKey: 'settings',
            rows: [],
            totalCount: 21,
            totalExact: true,
            limit: 10,
            rowsBefore: 11,
        );

        $payload = $dto->toArray();

        $this->assertSame(11, $payload[TableWindowSignalData::rowsBefore]);
        $this->assertSame(11, TableWindowSignalData::fromArray($payload)->rowsBefore);
    }

    public function testAWindowOfASetCountedOnlyToItsCeilingCarriesNoPlaceAtAll(): void
    {
        $dto = new TableWindowSignalData(
            page: 'hilos_notification_deliveries',
            tableKey: 'deliveries',
            rows: [],
            totalCount: TableConstants::COUNT_CEILING,
            totalExact: false,
            limit: 25,
        );

        $payload = $dto->toArray();

        $this->assertArrayNotHasKey(TableWindowSignalData::rowsBefore, $payload);
        $this->assertNull(TableWindowSignalData::fromArray($payload)->rowsBefore);
    }

    public function testAWindowStandingAtTheStartOfTheSetSaysSoRatherThanSayingNothing(): void
    {
        $dto = new TableWindowSignalData(
            page: 'hilos_settings',
            tableKey: 'settings',
            rows: [],
            totalCount: 21,
            totalExact: true,
            limit: 10,
            rowsBefore: 0,
        );

        $payload = $dto->toArray();

        $this->assertSame(0, $payload[TableWindowSignalData::rowsBefore]);
    }

    public function testFromArrayRefusesAWindowWithoutTheWordOnItsCountAndNamesTheKey(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(TableWindowSignalData::totalExact);

        TableWindowSignalData::fromArray([
            TableWindowSignalData::page => 'hilos_settings',
            TableWindowSignalData::tableKey => 'settings',
            TableWindowSignalData::rows => [],
            TableWindowSignalData::totalCount => 42,
            TableWindowSignalData::limit => 10,
        ]);
    }

    public function testFromArrayRefusesAnEmptyPayloadInsteadOfAnEmptyWindow(): void
    {
        $this->expectException(InvalidFormatException::class);

        TableWindowSignalData::fromArray([]);
    }

    public function testFromArrayRefusesAWindowWithoutItsDescriptorAndNamesTheKey(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(TableWindowSignalData::limit);

        TableWindowSignalData::fromArray([
            TableWindowSignalData::page => 'hilos_settings',
            TableWindowSignalData::tableKey => 'settings',
            TableWindowSignalData::rows => [],
            TableWindowSignalData::totalCount => 42,
            TableWindowSignalData::totalExact => true,
        ]);
    }
}
