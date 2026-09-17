<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\TableAnchorDirection;
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
            sort: TableSortOrderDTO::of(new TableSortDTO('key', TableConstants::ORDER_DESC)),
            limit: 10,
            anchor: new TableAnchorDTO(['key' => 'theme.dark']),
            anchorDirection: TableAnchorDirection::Before,
        );

        $restored = WebSocketTableViewportSignalDTO::fromArray($dto->toArray());

        $this->assertSame('ak', $restored->acceptKey);
        $this->assertSame('hilos_settings', $restored->page);
        $this->assertSame('settings', $restored->tableKey);
        $this->assertSame(['search' => 'theme'], $restored->filter);
        $this->assertEquals(TableSortOrderDTO::of(new TableSortDTO('key', TableConstants::ORDER_DESC)), $restored->sort);
        $this->assertSame(10, $restored->limit);
        $this->assertSame(['key' => 'theme.dark'], $restored->anchor?->toArray());
        $this->assertSame(TableAnchorDirection::Before, $restored->anchorDirection);
        $this->assertNull($restored->pageIndex);
    }

    public function testAJumpTravelsAsAPageIndexAndNothingElse(): void
    {
        $array = new WebSocketTableViewportSignalDTO(acceptKey: 'ak', tableKey: 't', limit: 10, pageIndex: 7)->toArray();

        $this->assertSame(7, $array[WebSocketTableViewportSignalDTO::PAGE_INDEX]);
        $this->assertArrayNotHasKey(WebSocketTableViewportSignalDTO::ANCHOR, $array);
        $this->assertArrayNotHasKey(WebSocketTableViewportSignalDTO::ANCHOR_DIRECTION, $array);
        $this->assertSame(7, WebSocketTableViewportSignalDTO::fromArray($array)->pageIndex);
    }

    public function testAFrameAddressingTheWindowTwiceIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        WebSocketTableViewportSignalDTO::fromArray([
            WebSocketTableViewportSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableViewportSignalDTO::TABLE_KEY => 't',
            WebSocketTableViewportSignalDTO::LIMIT => 10,
            WebSocketTableViewportSignalDTO::PAGE_INDEX => 7,
            WebSocketTableViewportSignalDTO::ANCHOR => ['id' => 3],
        ]);
    }

    public function testASideTheAnchorHasNoMeaningOnIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        WebSocketTableViewportSignalDTO::fromArray([
            WebSocketTableViewportSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableViewportSignalDTO::TABLE_KEY => 't',
            WebSocketTableViewportSignalDTO::LIMIT => 10,
            WebSocketTableViewportSignalDTO::ANCHOR_DIRECTION => 'sideways',
        ]);
    }

    public function testOrderRidesAsAListOfNestedFieldDirections(): void
    {
        $array = new WebSocketTableViewportSignalDTO(
            acceptKey: 'ak',
            tableKey: 't',
            sort: TableSortOrderDTO::of(
                new TableSortDTO('channel', TableConstants::ORDER_ASC),
                new TableSortDTO('name', TableConstants::ORDER_ASC),
            ),
        )->toArray();

        $this->assertSame(
            [
                ['field' => 'channel', 'direction' => TableConstants::ORDER_ASC],
                ['field' => 'name', 'direction' => TableConstants::ORDER_ASC],
            ],
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
            WebSocketTableViewportSignalDTO::LIMIT => TableConstants::NO_LIMIT,
        ]);

        $this->assertSame('ak', $dto->acceptKey);
        $this->assertNull($dto->page);
        $this->assertSame('t', $dto->tableKey);
        $this->assertSame([], $dto->filter);
        $this->assertNull($dto->sort);
        $this->assertSame(TableConstants::NO_LIMIT, $dto->limit);
        $this->assertNull($dto->anchor);
        $this->assertSame(TableAnchorDirection::After, $dto->anchorDirection);
    }

    public function testFromArrayRefusesAFrameNamingNoTable(): void
    {
        // A viewport with no table key used to arrive as a window on the empty-string
        // table, which no table answers and nothing reports.
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(WebSocketTableViewportSignalDTO::TABLE_KEY);

        WebSocketTableViewportSignalDTO::fromArray([
            WebSocketTableViewportSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableViewportSignalDTO::LIMIT => 10,
        ]);
    }

    public function testFromArrayRefusesAWindowWithoutItsSize(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(WebSocketTableViewportSignalDTO::LIMIT);

        WebSocketTableViewportSignalDTO::fromArray([
            WebSocketTableViewportSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableViewportSignalDTO::TABLE_KEY => 't',
        ]);
    }

    public function testASortPayloadNamingNoFieldDecodesToNoSort(): void
    {
        $dto = WebSocketTableViewportSignalDTO::fromArray([
            WebSocketTableViewportSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableViewportSignalDTO::TABLE_KEY => 't',
            WebSocketTableViewportSignalDTO::LIMIT => 10,
            WebSocketTableViewportSignalDTO::SORT => [[TableSortDTO::DIRECTION => TableConstants::ORDER_DESC]],
        ]);

        $this->assertNull($dto->sort);
    }

    public function testAnEmptyOrderDecodesToNoOrderRatherThanToABrokenFrame(): void
    {
        // The SDK sends the list it has, and a window with nothing to order by has an
        // empty one; refusing the frame would close the connection over saying so.
        $dto = WebSocketTableViewportSignalDTO::fromArray([
            WebSocketTableViewportSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableViewportSignalDTO::TABLE_KEY => 't',
            WebSocketTableViewportSignalDTO::LIMIT => 10,
            WebSocketTableViewportSignalDTO::SORT => [],
        ]);

        $this->assertNull($dto->sort);
    }

    public function testAnOrderRidingAsASingleObjectDecodesToNoOrder(): void
    {
        // The frame before this leaf carried one `{field, direction}` object rather than a
        // list of them. A client still sending that asks for an order this side cannot read,
        // and the window it gets is the table's own — not a closed connection.
        $dto = WebSocketTableViewportSignalDTO::fromArray([
            WebSocketTableViewportSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableViewportSignalDTO::TABLE_KEY => 't',
            WebSocketTableViewportSignalDTO::LIMIT => 10,
            WebSocketTableViewportSignalDTO::SORT => [
                TableSortDTO::FIELD => 'name',
                TableSortDTO::DIRECTION => TableConstants::ORDER_DESC,
            ],
        ]);

        $this->assertNull($dto->sort);
    }

    public function testTheDrawnFieldsSurviveTheirWireForm(): void
    {
        $restored = WebSocketTableViewportSignalDTO::fromArray(
            new WebSocketTableViewportSignalDTO(acceptKey: 'ak', tableKey: 't', limit: 10, rendered: ['name', 'presence'])->toArray(),
        );

        $this->assertSame(['name', 'presence'], $restored->rendered);
    }

    public function testAFrameDeclaringNoDrawnFieldsCarriesNoKeyForThem(): void
    {
        $array = new WebSocketTableViewportSignalDTO(acceptKey: 'ak', tableKey: 't', limit: 10)->toArray();

        // Absent, not empty: absence is what asks the server to compare the rows whole (HIL-880).
        $this->assertArrayNotHasKey(WebSocketTableViewportSignalDTO::RENDERED, $array);
        $this->assertSame([], WebSocketTableViewportSignalDTO::fromArray($array)->rendered);
    }

    public function testADrawnFieldThatIsNotAStringRefusesTheFrame(): void
    {
        $this->expectException(InvalidFormatException::class);

        WebSocketTableViewportSignalDTO::fromArray([
            WebSocketTableViewportSignalDTO::ACCEPT_KEY => 'ak',
            WebSocketTableViewportSignalDTO::TABLE_KEY => 't',
            WebSocketTableViewportSignalDTO::LIMIT => 10,
            WebSocketTableViewportSignalDTO::RENDERED => ['name', 7],
        ]);
    }

    public function testGetAcceptKey(): void
    {
        $this->assertSame('ak', new WebSocketTableViewportSignalDTO(acceptKey: 'ak')->getAcceptKey());
    }
}
