<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\WebSocket\DTO\WebSocketTableRenderedSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the client-to-server frame naming the fields of one table's rows its columns draw (HIL-880).
 */
final class WebSocketTableRenderedSignalDTOTest extends TestCase
{
    public function testRoundTripPreservesTheDrawnFieldsInTheirOrder(): void
    {
        $restored = WebSocketTableRenderedSignalDTO::fromArray(new WebSocketTableRenderedSignalDTO(
            acceptKey: 'ak',
            page: 'hilos_users',
            tableKey: 'users',
            rendered: ['name', 'id', 'presence'],
        )->toArray());

        $this->assertSame('ak', $restored->acceptKey);
        $this->assertSame('hilos_users', $restored->page);
        $this->assertSame('users', $restored->tableKey);
        $this->assertSame(['name', 'id', 'presence'], $restored->rendered);
    }

    /**
     * A table whose columns name no field of the row still reads: its rows are compared whole.
     */
    public function testAnEmptyListIsAFrameThatNamesNothing(): void
    {
        $dto = WebSocketTableRenderedSignalDTO::fromArray([
            WebSocketTableRenderedSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableRenderedSignalDTO::TABLE_KEY => 'users',
            WebSocketTableRenderedSignalDTO::RENDERED => [],
        ]);

        $this->assertSame([], $dto->rendered);
        $this->assertNull($dto->page);
    }

    public function testFromArrayRefusesAFrameWithoutItsList(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('drawn fields');

        WebSocketTableRenderedSignalDTO::fromArray([
            WebSocketTableRenderedSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableRenderedSignalDTO::TABLE_KEY => 'users',
        ]);
    }

    /**
     * A field dropped from the list would be a field whose changes the server keeps from the screen.
     */
    public function testFromArrayRefusesAListHoldingAnythingButStrings(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(WebSocketTableRenderedSignalDTO::RENDERED);

        WebSocketTableRenderedSignalDTO::fromArray([
            WebSocketTableRenderedSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableRenderedSignalDTO::TABLE_KEY => 'users',
            WebSocketTableRenderedSignalDTO::RENDERED => ['name', 7],
        ]);
    }

    public function testFromArrayRefusesAFrameNamingNoTable(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(WebSocketTableRenderedSignalDTO::TABLE_KEY);

        WebSocketTableRenderedSignalDTO::fromArray([
            WebSocketTableRenderedSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableRenderedSignalDTO::RENDERED => ['name'],
        ]);
    }
}
