<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\WebSocket\DTO\WebSocketTableFacetsSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the client-to-server frame naming the options a table's counts are asked for (HIL-240).
 */
final class WebSocketTableFacetsSignalDTOTest extends TestCase
{
    public function testRoundTripPreservesTheOptionsOfEveryFilter(): void
    {
        $restored = WebSocketTableFacetsSignalDTO::fromArray(new WebSocketTableFacetsSignalDTO(
            acceptKey: 'ak',
            page: 'hilos_notification_deliveries',
            tableKey: 'deliveries',
            facets: ['channel' => ['email', 'sms'], 'priority' => [1, 2]],
        )->toArray());

        $this->assertSame('ak', $restored->acceptKey);
        $this->assertSame('hilos_notification_deliveries', $restored->page);
        $this->assertSame('deliveries', $restored->tableKey);
        $this->assertSame(['channel' => ['email', 'sms'], 'priority' => [1, 2]], $restored->facets);
    }

    /**
     * An option that cannot be a filter value costs its own number, and a filter whose entry is not a
     * list costs its own counts - the frame itself still reads.
     */
    public function testAnOptionThatIsNotAScalarIsDroppedAndTheFrameStillReads(): void
    {
        $dto = WebSocketTableFacetsSignalDTO::fromArray([
            WebSocketTableFacetsSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableFacetsSignalDTO::TABLE_KEY => 'deliveries',
            WebSocketTableFacetsSignalDTO::FACETS => [
                'channel' => ['email', ['nested'], null, true, 3.5],
                'status' => 'failed',
            ],
        ]);

        $this->assertSame(['channel' => ['email', true, 3.5]], $dto->facets);
        $this->assertNull($dto->page);
    }

    /**
     * A view whose filters offer nothing to count still says so, and the list it replaces goes.
     */
    public function testAnEmptyMapOfOptionsIsAFrameThatAsksForNothing(): void
    {
        $dto = WebSocketTableFacetsSignalDTO::fromArray([
            WebSocketTableFacetsSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableFacetsSignalDTO::TABLE_KEY => 'deliveries',
            WebSocketTableFacetsSignalDTO::FACETS => [],
        ]);

        $this->assertSame([], $dto->facets);
    }

    public function testFromArrayRefusesAFrameWithoutItsMapOfOptions(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(WebSocketTableFacetsSignalDTO::FACETS);

        WebSocketTableFacetsSignalDTO::fromArray([
            WebSocketTableFacetsSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableFacetsSignalDTO::TABLE_KEY => 'deliveries',
        ]);
    }

    public function testFromArrayRefusesAFrameNamingNoTable(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(WebSocketTableFacetsSignalDTO::TABLE_KEY);

        WebSocketTableFacetsSignalDTO::fromArray([
            WebSocketTableFacetsSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableFacetsSignalDTO::FACETS => [],
        ]);
    }
}
