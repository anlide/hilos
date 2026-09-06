<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Table\DTO\TableAnchorDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the place a table window is anchored at.
 */
final class TableAnchorDTOTest extends TestCase
{
    public function testFromWireKeepsTheAnchoredValues(): void
    {
        $anchor = TableAnchorDTO::fromWire(['name' => 'Ada', 'id' => 7]);

        $this->assertNotNull($anchor);
        $this->assertSame(['name' => 'Ada', 'id' => 7], $anchor->toArray());
    }

    public function testFromWireReadsAMissingAnchorAsTheEdgeOfTheSet(): void
    {
        $this->assertNull(TableAnchorDTO::fromWire(null));
        $this->assertNull(TableAnchorDTO::fromWire([]));
    }

    public function testFromWireDropsValuesNoOrderingCouldCompare(): void
    {
        $anchor = TableAnchorDTO::fromWire(['id' => 7, 'nested' => ['deep' => 1]]);

        $this->assertNotNull($anchor);
        $this->assertSame(['id' => 7], $anchor->toArray());
    }

    public function testFromWireKeepsANullValueOfAKeyColumn(): void
    {
        $anchor = TableAnchorDTO::fromWire(['title' => null, 'id' => 7]);

        $this->assertNotNull($anchor);
        $this->assertSame(['title' => null, 'id' => 7], $anchor->toArray());
    }
}
