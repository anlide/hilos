<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the window a tab reports on its page subscription (HIL-642).
 *
 * The descriptor is the body of a table_viewport frame without its address, and it is read
 * under the same rules — including the refusal of a window addressed two ways at once, which
 * is the one thing about it a server may not guess at.
 */
final class TableWindowDescriptorDTOTest extends TestCase
{
    public function testAnAnchoredWindowSurvivesItsOwnWireForm(): void
    {
        $descriptor = new TableWindowDescriptorDTO(
            filter: [TableConstants::FILTER_KEY_SEARCH => 'ada'],
            sort: TableSortOrderDTO::of(new TableSortDTO('key', TableConstants::ORDER_DESC)),
            limit: 25,
            anchor: new TableAnchorDTO(['key' => 'theme.dark']),
            anchorDirection: TableAnchorDirection::Before,
        );

        $restored = TableWindowDescriptorDTO::fromArray($descriptor->toArray());

        $this->assertSame([TableConstants::FILTER_KEY_SEARCH => 'ada'], $restored->filter);
        $this->assertSame('key', $restored->sort?->last()->field);
        $this->assertSame(TableConstants::ORDER_DESC, $restored->sort?->last()->direction);
        $this->assertSame(25, $restored->limit);
        $this->assertSame(['key' => 'theme.dark'], $restored->anchor?->toArray());
        $this->assertSame(TableAnchorDirection::Before, $restored->anchorDirection);
        $this->assertNull($restored->pageIndex);
    }

    public function testANumberedPageSurvivesItsOwnWireForm(): void
    {
        $restored = TableWindowDescriptorDTO::fromArray(
            new TableWindowDescriptorDTO(limit: 10, pageIndex: 3)->toArray(),
        );

        $this->assertSame(3, $restored->pageIndex);
        $this->assertNull($restored->anchor);
    }

    public function testAWindowAddressedBothWaysAtOnceIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        TableWindowDescriptorDTO::fromArray([
            TableWindowDescriptorDTO::LIMIT => 10,
            TableWindowDescriptorDTO::PAGE_INDEX => 2,
            TableWindowDescriptorDTO::ANCHOR => ['key' => 'a'],
        ]);
    }

    public function testADescriptorWithoutASizeIsNotADescriptor(): void
    {
        $this->expectException(InvalidFormatException::class);

        // A tab reports a window it is holding, and a window it is holding has a size; without
        // one there is nothing to tell the cold entry from a report of the whole set.
        TableWindowDescriptorDTO::fromArray([TableWindowDescriptorDTO::PAGE_INDEX => 0]);
    }

    public function testTheSubscribeFrameCarriesTheWindowsBothWays(): void
    {
        $frame = new WebSocketPageSubscribeSignalDTO(
            acceptKey: 'ak-1',
            page: 'settings',
            tableWindows: ['settings' => new TableWindowDescriptorDTO(limit: 10, pageIndex: 1)],
        );

        $restored = WebSocketPageSubscribeSignalDTO::fromArray($frame->toArray());

        $this->assertSame(10, $restored->tableWindows['settings']->limit);
        $this->assertSame(1, $restored->tableWindows['settings']->pageIndex);
    }

    public function testASubscribeFrameThatReportsNoWindowCarriesNoKeyForThem(): void
    {
        $frame = new WebSocketPageSubscribeSignalDTO(acceptKey: 'ak-1', page: 'settings');

        // An absent key and an empty map say the same thing — this tab is holding no window —
        // and the frame says it by leaving the key out, as it does for its params.
        $this->assertArrayNotHasKey(WebSocketPageSubscribeSignalDTO::TABLE_WINDOWS, $frame->toArray());
        $this->assertSame([], WebSocketPageSubscribeSignalDTO::fromArray($frame->toArray())->tableWindows);
    }
}
