<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\DTO\TableViewportAnnounceDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableRowPlacement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the server-to-client table viewport announce payload.
 */
final class TableViewportAnnounceDTOTest extends TestCase
{
    public function testRoundTripPreservesTheKeyThePlaceAndTheCounts(): void
    {
        $restored = TableViewportAnnounceDTO::fromArray(
            new TableViewportAnnounceDTO(
                'hilos_settings',
                'settings',
                'a',
                TableRowPlacement::Above,
                91,
                true,
                4,
            )->toArray(),
        );

        $this->assertSame('hilos_settings', $restored->page);
        $this->assertSame('settings', $restored->tableKey);
        $this->assertSame('a', $restored->rowKey);
        $this->assertSame(TableRowPlacement::Above, $restored->placement);
        $this->assertSame(91, $restored->totalCount);
        $this->assertTrue($restored->totalExact);
        $this->assertSame(4, $restored->pageCount);
    }

    public function testThePageCountStaysBehindWhenTheCountStoppedAtItsCeiling(): void
    {
        $wire = new TableViewportAnnounceDTO(
            'hilos_settings',
            'settings',
            'a',
            TableRowPlacement::Inside,
            TableConstants::COUNT_CEILING,
            false,
            null,
        )->toArray();

        $this->assertArrayNotHasKey(TableViewportAnnounceDTO::pageCount, $wire);

        $restored = TableViewportAnnounceDTO::fromArray($wire);

        $this->assertSame(TableRowPlacement::Inside, $restored->placement);
        $this->assertFalse($restored->totalExact);
        $this->assertNull($restored->pageCount);
    }

    public function testFromArrayRefusesAPlacementTheWindowCanShow(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(TableViewportAnnounceDTO::placement);

        TableViewportAnnounceDTO::fromArray([
            TableViewportAnnounceDTO::page => 'hilos_settings',
            TableViewportAnnounceDTO::tableKey => 'settings',
            TableViewportAnnounceDTO::rowKey => 'a',
            TableViewportAnnounceDTO::placement => TableRowPlacement::Tail->value,
            TableViewportAnnounceDTO::totalCount => 91,
            TableViewportAnnounceDTO::totalExact => true,
            TableViewportAnnounceDTO::pageCount => 4,
        ]);
    }

    public function testFromArrayRefusesAnEmptyPayloadInsteadOfAnnouncingNothing(): void
    {
        $this->expectException(InvalidFormatException::class);

        TableViewportAnnounceDTO::fromArray([]);
    }
}
