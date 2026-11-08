<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Daemon\SignalHoldIndex;
use Hilos\Core\Daemon\SignalHoldIndexNode;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Sparse cancellation and later membership changes cannot make one cursor step walk a hash roster. */
final class SignalHoldIndexTest extends TestCase
{
    public function testStableOrderKeysRemainSortedWhenAnOlderConnectionJoinsLater(): void
    {
        $index = new SignalHoldIndex();
        foreach ([30, 10, 40, 20] as $id) {
            $index->add($id);
        }
        self::assertSame([10, 20, 30, 40], iterator_to_array($index->candidates(), false));
        self::assertSame([10, 20, 30], iterator_to_array($index->candidates(30), false));
        self::assertSame(40, $index->lastIdentity());
        $index->add(20);
        $index->remove(40);
        $index->remove(99);
        self::assertSame(30, $index->lastIdentity());
        self::assertSame([10, 20, 30], iterator_to_array($index->candidates(), false));
    }

    public function testEmptyCaptureAndOriginalTailExcludeLaterAdmissions(): void
    {
        $index = new SignalHoldIndex();
        $empty = $index->candidates();
        $index->add(1);
        $index->add(2);
        $original = $index->candidates();
        $index->add(3);
        self::assertSame([], iterator_to_array($empty, false));
        self::assertSame([1, 2], iterator_to_array($original, false));
        self::assertSame([1, 2, 3], iterator_to_array($index->candidates(), false));
    }

    public function testSparseRemovalBetweenPortionsKeepsTheFiniteOriginalSuccessor(): void
    {
        $index = new SignalHoldIndex();
        for ($id = 1; $id <= 20000; $id++) {
            $index->add($id);
        }
        $cursor = $index->candidates();
        self::assertSame(1, $cursor->current());
        for ($id = 1; $id < 20000; $id++) {
            $index->remove($id);
        }
        $index->add(20001);
        $cursor->next();
        self::assertSame(20000, $cursor->current());
        $index->remove(20000);
        $cursor->next();
        self::assertFalse($cursor->valid());
        self::assertSame(20001, $index->lastIdentity());
        $index->remove(20001);
        self::assertNull($index->lastIdentity());
        self::assertTrue($index->isEmpty());
    }

    public function testAdversarialInsertionsAndRemovalsKeepLogarithmicDepthAndExactMembership(): void
    {
        $index = new SignalHoldIndex();
        for ($id = 8192; $id >= 1; $id--) {
            $index->add($id);
        }
        $root = new ReflectionProperty(SignalHoldIndex::class, 'root');
        self::assertLessThanOrEqual(19, $this->assertBalanced($root->getValue($index)));
        $remaining = [];
        for ($id = 1; $id <= 8192; $id++) {
            if ($id % 3 === 0) {
                $remaining[] = $id;
            } else {
                $index->remove($id);
            }
        }
        self::assertSame($remaining, iterator_to_array($index->candidates(), false));
        self::assertLessThanOrEqual(17, $this->assertBalanced($root->getValue($index)));
    }

    /**
     * @param ?SignalHoldIndexNode $node Scalar branch being audited
     * @return int Actual branch depth, after verifying every cached height and AVL balance
     */
    private function assertBalanced(?SignalHoldIndexNode $node): int
    {
        if ($node === null) {
            return 0;
        }
        $left = $this->assertBalanced($node->left);
        $right = $this->assertBalanced($node->right);
        self::assertLessThanOrEqual(1, abs($left - $right));
        self::assertSame(max($left, $right) + 1, $node->height);
        return $node->height;
    }
}
