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

    public function testToArrayOmitsKeysIrrelevantToTheKind(): void
    {
        $removed = TableViewportDeltaDTO::rowRemoved('p', 't', 5, TableViewportDeltaDTO::REASON_DELETED)->toArray();
        $this->assertArrayNotHasKey(TableViewportDeltaDTO::row, $removed);

        $updated = TableViewportDeltaDTO::rowUpdated('p', 't', 5, ['rowKey' => 5])->toArray();
        $this->assertArrayNotHasKey(TableViewportDeltaDTO::reason, $updated);
    }

    public function testOwnRoundTrip(): void
    {
        $restored = TableViewportDeltaDTO::fromArray(
            TableViewportDeltaDTO::rowUpdated('p', 't', 'a', ['rowKey' => 'a'], own: true)->toArray(),
        );
        $this->assertTrue($restored->own);

        // Own is omitted from the wire when false and defaults back to false.
        $notOwn = TableViewportDeltaDTO::rowUpdated('p', 't', 'a', ['rowKey' => 'a'])->toArray();
        $this->assertArrayNotHasKey(TableViewportDeltaDTO::own, $notOwn);
        $this->assertFalse(TableViewportDeltaDTO::fromArray($notOwn)->own);
    }
}
