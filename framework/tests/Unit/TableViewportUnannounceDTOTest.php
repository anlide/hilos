<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableViewportUnannounceDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the server-to-client table viewport unannounce payload.
 */
final class TableViewportUnannounceDTOTest extends TestCase
{
    public function testRoundTripPreservesTheAddressAndTheKey(): void
    {
        $restored = TableViewportUnannounceDTO::fromArray(
            new TableViewportUnannounceDTO('hilos_settings', 'settings', 'a')->toArray(),
        );

        $this->assertSame('hilos_settings', $restored->page);
        $this->assertSame('settings', $restored->tableKey);
        $this->assertSame('a', $restored->rowKey);
    }

    public function testTheWireCarriesTheKeyAndNoCount(): void
    {
        $this->assertSame(
            [
                TableViewportUnannounceDTO::page => 'hilos_settings',
                TableViewportUnannounceDTO::tableKey => 'settings',
                TableViewportUnannounceDTO::rowKey => 'a',
            ],
            new TableViewportUnannounceDTO('hilos_settings', 'settings', 'a')->toArray(),
        );
    }

    public function testFromArrayRefusesAPayloadWithoutTheRowKey(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(TableViewportUnannounceDTO::rowKey);

        TableViewportUnannounceDTO::fromArray([
            TableViewportUnannounceDTO::page => 'hilos_settings',
            TableViewportUnannounceDTO::tableKey => 'settings',
        ]);
    }
}
