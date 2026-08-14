<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableWindowSignalData;
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
            offset: 10,
            limit: 10,
        );

        $restored = TableWindowSignalData::fromArray($dto->toArray());

        $this->assertSame('hilos_settings', $restored->page);
        $this->assertSame('settings', $restored->tableKey);
        $this->assertSame($rows, $restored->rows);
        $this->assertSame(42, $restored->totalCount);
        $this->assertSame(10, $restored->offset);
        $this->assertSame(10, $restored->limit);
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
            TableWindowSignalData::offset => 10,
        ]);
    }
}
