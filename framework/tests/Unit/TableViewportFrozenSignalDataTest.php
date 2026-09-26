<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableViewportFrozenSignalData;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the server-to-client word that a table's window stopped receiving its live changes.
 */
final class TableViewportFrozenSignalDataTest extends TestCase
{
    public function testRoundTripPreservesTheFreeze(): void
    {
        $dto = new TableViewportFrozenSignalData(
            page: 'hilos_settings',
            tableKey: 'settings',
            since: 1_790_000_000_123,
        );

        $restored = TableViewportFrozenSignalData::fromArray($dto->toArray());

        $this->assertSame('hilos_settings', $restored->page);
        $this->assertSame('settings', $restored->tableKey);
        $this->assertSame(1_790_000_000_123, $restored->since);
    }

    public function testFromArrayRefusesAPayloadWithoutSinceAndNamesTheKey(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(TableViewportFrozenSignalData::since);

        TableViewportFrozenSignalData::fromArray([
            TableViewportFrozenSignalData::page => 'hilos_settings',
            TableViewportFrozenSignalData::tableKey => 'settings',
        ]);
    }

    public function testFromArrayRefusesASinceThatIsNotAnInteger(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(TableViewportFrozenSignalData::since);

        TableViewportFrozenSignalData::fromArray([
            TableViewportFrozenSignalData::page => 'hilos_settings',
            TableViewportFrozenSignalData::tableKey => 'settings',
            TableViewportFrozenSignalData::since => '1790000000123',
        ]);
    }
}
