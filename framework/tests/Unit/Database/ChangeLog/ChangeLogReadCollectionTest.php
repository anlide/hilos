<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\ChangeLog;

use Hilos\Database\ChangeLog\Section\ChangeLogFieldChange;
use Hilos\Database\ChangeLog\Section\ChangeLogReadCollection;
use PHPUnit\Framework\TestCase;

final class ChangeLogReadCollectionTest extends TestCase
{
    /** Dense windows and id maps retain source order even when ids are not consecutive or ascending. */
    public function testPreservesDenseWindowsAndIdMapOrder(): void
    {
        $first = new ChangeLogFieldChange('first', 'inline', true, true, null, '', false);
        $last = new ChangeLogFieldChange('last', 'fact', false, true, null, null, false);
        foreach ([[0 => $first, 1 => $last], [91 => $first, 7 => $last]] as $source) {
            $snapshot = ChangeLogReadCollection::fromArray($source);
            $this->assertFalse($snapshot->isEmpty());
            $this->assertCount(2, $snapshot);
            $this->assertSame($source, $snapshot->toArray());
            $this->assertSame($source, iterator_to_array($snapshot));
            $this->assertSame($first, $snapshot->first());
            $this->assertSame($last, $snapshot->last());
            foreach ($source as $key => $value) {
                $this->assertSame($value, $snapshot->get($key));
            }
            $this->assertNull($snapshot->get(999));
        }
    }

    /** Empty snapshots have no synthetic first, last or missing value. */
    public function testEmptySnapshot(): void
    {
        $empty = ChangeLogReadCollection::fromArray([]);
        $this->assertTrue($empty->isEmpty());
        $this->assertCount(0, $empty);
        $this->assertNull($empty->get(0));
        $this->assertNull($empty->first());
        $this->assertNull($empty->last());
        $this->assertSame([], $empty->toArray());
        $this->assertSame([], iterator_to_array($empty));
    }

    /** Repeated and nested walks each start at the first original key. */
    public function testWalksHaveIndependentPositions(): void
    {
        $first = new ChangeLogFieldChange('first', 'inline', true, true, 'old', 'new', false);
        $last = new ChangeLogFieldChange('last', 'fact', false, true, null, null, false);
        $snapshot = ChangeLogReadCollection::fromArray([91 => $first, 7 => $last]);
        $pairs = [];
        foreach ($snapshot as $outerKey => $outer) {
            foreach ($snapshot as $innerKey => $inner) {
                $pairs[] = [$outerKey, $innerKey, $outer->field, $inner->field];
            }
        }
        $this->assertSame([
            [91, 91, 'first', 'first'], [91, 7, 'first', 'last'],
            [7, 91, 'last', 'first'], [7, 7, 'last', 'last'],
        ], $pairs);
        $this->assertSame([91 => $first, 7 => $last], iterator_to_array($snapshot));
        $this->assertSame([91 => $first, 7 => $last], iterator_to_array($snapshot));
    }

    /** Changing either array boundary cannot replace, remove or append snapshot membership. */
    public function testMembershipIsIndependentOfInputAndOutputArrays(): void
    {
        $first = new ChangeLogFieldChange('first', 'inline', true, true, 'old', 'new', false);
        $last = new ChangeLogFieldChange('last', 'fact', false, true, null, null, false);
        $source = [91 => $first, 7 => $last];
        $snapshot = ChangeLogReadCollection::fromArray($source);
        $source[91] = $last;
        unset($source[7]);
        $source[25] = $first;
        $exported = $snapshot->toArray();
        unset($exported[91]);
        $exported[7] = $first;
        $exported[] = $last;
        $this->assertSame([91 => $first, 7 => $last], $snapshot->toArray());
        $this->assertSame([91 => $first, 7 => $last], iterator_to_array($snapshot));
    }
}
