<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the server-to-client table viewport delta payload.
 */
final class TableViewportDeltaDTOTest extends TestCase
{
    public function testRowUpdatedRoundTrip(): void
    {
        $row = ['rowKey' => 'a', 'sources' => ['settings' => ['key' => 'a']]];
        $restored = TableViewportDeltaDTO::fromArray(
            TableViewportDeltaDTO::rowUpdated('hilos_settings', 'settings', 'a', $row)->toArray(),
        );

        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $restored->kind);
        $this->assertSame('a', $restored->rowKey);
        $this->assertSame($row, $restored->row);
        $this->assertNull($restored->reason);
        $this->assertNull($restored->totalCount);
    }

    public function testRowRemovedRoundTrip(): void
    {
        $restored = TableViewportDeltaDTO::fromArray(
            TableViewportDeltaDTO::rowRemoved('p', 't', 5, TableViewportDeltaDTO::REASON_LEFT_SET)->toArray(),
        );

        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $restored->kind);
        $this->assertSame(5, $restored->rowKey);
        $this->assertSame(TableViewportDeltaDTO::REASON_LEFT_SET, $restored->reason);
        $this->assertNull($restored->row);
    }

    public function testSetChangedRoundTrip(): void
    {
        $restored = TableViewportDeltaDTO::fromArray(
            TableViewportDeltaDTO::setChanged('p', 't', 42, 5)->toArray(),
        );

        $this->assertSame(TableViewportDeltaDTO::KIND_SET_CHANGED, $restored->kind);
        $this->assertSame(42, $restored->totalCount);
        $this->assertSame(5, $restored->pageCount);
        $this->assertNull($restored->rowKey);
    }

    public function testToArrayOmitsKeysIrrelevantToTheKind(): void
    {
        $array = TableViewportDeltaDTO::setChanged('p', 't', 1, 1)->toArray();

        $this->assertArrayNotHasKey(TableViewportDeltaDTO::rowKey, $array);
        $this->assertArrayNotHasKey(TableViewportDeltaDTO::row, $array);
        $this->assertArrayNotHasKey(TableViewportDeltaDTO::reason, $array);
    }
}
