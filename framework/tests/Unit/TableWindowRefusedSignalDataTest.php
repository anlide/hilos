<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableWindowRefusedSignalData;
use Hilos\Core\Table\TableWindowRefusalCode;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the server-to-client table window refusal signal payload.
 */
final class TableWindowRefusedSignalDataTest extends TestCase
{
    public function testRoundTripPreservesTheRefusal(): void
    {
        $dto = new TableWindowRefusedSignalData(
            page: 'hilos_settings',
            tableKey: 'settings',
            errorCode: TableWindowRefusalCode::INTERNAL_ERROR,
        );

        $restored = TableWindowRefusedSignalData::fromArray($dto->toArray());

        $this->assertSame('hilos_settings', $restored->page);
        $this->assertSame('settings', $restored->tableKey);
        $this->assertSame(TableWindowRefusalCode::INTERNAL_ERROR, $restored->errorCode);
    }

    public function testFromArrayRefusesAPayloadWithoutErrorCodeAndNamesTheKey(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(TableWindowRefusedSignalData::errorCode);

        TableWindowRefusedSignalData::fromArray([
            TableWindowRefusedSignalData::page => 'hilos_settings',
            TableWindowRefusedSignalData::tableKey => 'settings',
        ]);
    }
}
