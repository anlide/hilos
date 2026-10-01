<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableViewportOwnCreateDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the server-to-client placed own-create payload.
 */
final class TableViewportOwnCreateDTOTest extends TestCase
{
    public function testRoundTripCarriesReselectedWindowBoundaries(): void
    {
        $row = ['rowKey' => 'beta', 'slots' => ['keys' => ['key' => 'beta']]];
        $wire = new TableViewportOwnCreateDTO(
            'users',
            'keys',
            $row,
            1,
            3,
            true,
            1,
            new TableAnchorDTO(['key' => 'alpha']),
            new TableAnchorDTO(['key' => 'gamma']),
            'req-1',
        )->toArray();

        $restored = TableViewportOwnCreateDTO::fromArray($wire);

        $this->assertSame(['key' => 'alpha'], $restored->firstAnchor?->toArray());
        $this->assertSame(['key' => 'gamma'], $restored->lastAnchor?->toArray());
        $this->assertSame('req-1', $restored->requestId);
    }

    public function testNullBoundariesRemainPresentOnTheWire(): void
    {
        $wire = new TableViewportOwnCreateDTO('users', 'keys', ['rowKey' => 'a', 'slots' => []], 0, 1, true, 1, null, null)->toArray();

        $this->assertArrayHasKey(TableViewportOwnCreateDTO::firstAnchor, $wire);
        $this->assertArrayHasKey(TableViewportOwnCreateDTO::lastAnchor, $wire);
        $this->assertNull(TableViewportOwnCreateDTO::fromArray($wire)->firstAnchor);
        $this->assertNull(TableViewportOwnCreateDTO::fromArray($wire)->lastAnchor);
    }
}
