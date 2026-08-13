<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Socket\WebSocket\DTO\WebSocketTableViewportSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the client-to-server table viewport signal DTO.
 */
final class WebSocketTableViewportSignalDTOTest extends TestCase
{
    public function testRoundTripPreservesAllFields(): void
    {
        $dto = new WebSocketTableViewportSignalDTO(
            acceptKey: 'ak',
            page: 'hilos_settings',
            tableKey: 'settings',
            filter: ['search' => 'theme'],
            sort: new TableSortDTO('key', TableConstants::ORDER_DESC),
            offset: 10,
            limit: 10,
        );

        $restored = WebSocketTableViewportSignalDTO::fromArray($dto->toArray());

        $this->assertSame('ak', $restored->acceptKey);
        $this->assertSame('hilos_settings', $restored->page);
        $this->assertSame('settings', $restored->tableKey);
        $this->assertSame(['search' => 'theme'], $restored->filter);
        $this->assertEquals(new TableSortDTO('key', TableConstants::ORDER_DESC), $restored->sort);
        $this->assertSame(10, $restored->offset);
        $this->assertSame(10, $restored->limit);
    }

    public function testSortRidesAsNestedFieldDirection(): void
    {
        $array = new WebSocketTableViewportSignalDTO(
            acceptKey: 'ak',
            tableKey: 't',
            sort: new TableSortDTO('name', TableConstants::ORDER_ASC),
        )->toArray();

        $this->assertSame(
            ['field' => 'name', 'direction' => TableConstants::ORDER_ASC],
            $array[WebSocketTableViewportSignalDTO::SORT],
        );
    }

    public function testNoSortKeyWithoutASort(): void
    {
        $array = new WebSocketTableViewportSignalDTO(acceptKey: 'ak', tableKey: 't')->toArray();

        $this->assertArrayNotHasKey(WebSocketTableViewportSignalDTO::SORT, $array);
    }

    public function testFromArrayReadsAWindowWithoutAFilterOrASort(): void
    {
        // The SDK leaves an empty filter and an unset ordering out of the frame, so
        // those two are the only parts of the window allowed to be missing.
        $dto = WebSocketTableViewportSignalDTO::fromArray([
            WebSocketTableViewportSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableViewportSignalDTO::TABLE_KEY => 't',
            WebSocketTableViewportSignalDTO::OFFSET => 0,
            WebSocketTableViewportSignalDTO::LIMIT => TableConstants::NO_LIMIT,
        ]);

        $this->assertSame('ak', $dto->acceptKey);
        $this->assertNull($dto->page);
        $this->assertSame('t', $dto->tableKey);
        $this->assertSame([], $dto->filter);
        $this->assertNull($dto->sort);
        $this->assertSame(0, $dto->offset);
        $this->assertSame(TableConstants::NO_LIMIT, $dto->limit);
    }

    public function testFromArrayRefusesAFrameNamingNoTable(): void
    {
        // A viewport with no table key used to arrive as a window on the empty-string
        // table, which no table answers and nothing reports.
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(WebSocketTableViewportSignalDTO::TABLE_KEY);

        WebSocketTableViewportSignalDTO::fromArray([
            WebSocketTableViewportSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableViewportSignalDTO::OFFSET => 0,
            WebSocketTableViewportSignalDTO::LIMIT => 10,
        ]);
    }

    public function testFromArrayRefusesAWindowWithoutItsBounds(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(WebSocketTableViewportSignalDTO::OFFSET);

        WebSocketTableViewportSignalDTO::fromArray([
            WebSocketTableViewportSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableViewportSignalDTO::TABLE_KEY => 't',
            WebSocketTableViewportSignalDTO::LIMIT => 10,
        ]);
    }

    public function testASortPayloadNamingNoFieldDecodesToNoSort(): void
    {
        $dto = WebSocketTableViewportSignalDTO::fromArray([
            WebSocketTableViewportSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableViewportSignalDTO::TABLE_KEY => 't',
            WebSocketTableViewportSignalDTO::OFFSET => 0,
            WebSocketTableViewportSignalDTO::LIMIT => 10,
            WebSocketTableViewportSignalDTO::SORT => [TableSortDTO::DIRECTION => TableConstants::ORDER_DESC],
        ]);

        $this->assertNull($dto->sort);
    }

    public function testGetAcceptKey(): void
    {
        $this->assertSame('ak', new WebSocketTableViewportSignalDTO(acceptKey: 'ak')->getAcceptKey());
    }
}
