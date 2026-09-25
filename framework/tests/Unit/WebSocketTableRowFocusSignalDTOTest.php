<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\WebSocket\DTO\WebSocketTableRowFocusSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the client-to-server frame naming the row of one table a tab holds in focus for an open dialog (HIL-1050).
 */
final class WebSocketTableRowFocusSignalDTOTest extends TestCase
{
    public function testRoundTripPreservesTheRowTheTabHolds(): void
    {
        $restored = WebSocketTableRowFocusSignalDTO::fromArray(new WebSocketTableRowFocusSignalDTO(
            acceptKey: 'ak',
            page: 'hilos_settings',
            tableKey: 'settings',
            rowKey: 'site.title',
        )->toArray());

        $this->assertSame('ak', $restored->acceptKey);
        $this->assertSame('hilos_settings', $restored->page);
        $this->assertSame('settings', $restored->tableKey);
        $this->assertSame('site.title', $restored->rowKey);
    }

    /**
     * An empty key is the release: the frame is whole, it just names no row to hold any more.
     */
    public function testAnEmptyRowKeyIsTheRelease(): void
    {
        $dto = WebSocketTableRowFocusSignalDTO::fromArray([
            WebSocketTableRowFocusSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableRowFocusSignalDTO::TABLE_KEY => 'settings',
            WebSocketTableRowFocusSignalDTO::ROW_KEY => '',
        ]);

        $this->assertSame('', $dto->rowKey);
        $this->assertNull($dto->page);
    }

    /**
     * A frame without the key names no row to hold and none to let go of; it is not a release.
     */
    public function testFromArrayRefusesAFrameWithoutItsRowKey(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(WebSocketTableRowFocusSignalDTO::ROW_KEY);

        WebSocketTableRowFocusSignalDTO::fromArray([
            WebSocketTableRowFocusSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableRowFocusSignalDTO::TABLE_KEY => 'settings',
        ]);
    }

    /**
     * A number cast to a string would hold a row the tab never named.
     */
    public function testFromArrayRefusesARowKeyOfAnotherType(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(WebSocketTableRowFocusSignalDTO::ROW_KEY);

        WebSocketTableRowFocusSignalDTO::fromArray([
            WebSocketTableRowFocusSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableRowFocusSignalDTO::TABLE_KEY => 'settings',
            WebSocketTableRowFocusSignalDTO::ROW_KEY => 7,
        ]);
    }

    public function testFromArrayRefusesAFrameNamingNoTable(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(WebSocketTableRowFocusSignalDTO::TABLE_KEY);

        WebSocketTableRowFocusSignalDTO::fromArray([
            WebSocketTableRowFocusSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableRowFocusSignalDTO::ROW_KEY => 'site.title',
        ]);
    }
}
