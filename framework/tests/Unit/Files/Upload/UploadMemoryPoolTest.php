<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files\Upload;

use Hilos\Core\Exception\LogicException;
use Hilos\Files\Upload\UploadMemoryPool;
use Hilos\Files\Upload\UploadMemoryReservation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure accounting sequences, with independent expected outstanding raw-byte obligations.
 * Two pools below demonstrate primitives only; they do not prove multi-node transport progress.
 */
final class UploadMemoryPoolTest extends TestCase
{
    public function testFullWindowIncludesUnusedCreditAndAcceptedWorkerBytesUntilConsumed(): void
    {
        $pool = new UploadMemoryPool(12, 'master-1');
        $pool->request('browser-a', 'file', 8);
        $hold = $pool->reserveNext();
        $this->assertNotNull($hold);
        $this->assertNull($hold->sendUpToBytes);
        $this->assertSame(8, $hold->remainingBytes);
        $this->assertBudget($pool, 8);
        $window = $pool->publish($hold->id);
        $this->assertNotNull($window);
        $this->assertSame(8, $window->sendUpToBytes);
        $this->assertSame($window, $pool->publish($hold->id));
        $this->assertFalse($pool->hasPending());
        $this->assertBudget($pool, 8);

        $first = $pool->accept($window->id, 0, 3, 'worker-1:boot-a');
        $second = $pool->accept($window->id, 3, 2, 'worker-2:boot-a');
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first->id, $second->id);
        // Destination queue, partial socket write and parking keep both receipts live.
        $this->assertBudget($pool, 3, 2, 3);
        $pool->settle($first->id, $first->channel);
        $this->assertBudget($pool, 2, 3);
        $pool->settle($second->id, $second->channel);
        $this->assertBudget($pool, 3);
        $last = $pool->accept($window->id, 5, 3, 'worker-1:boot-a');
        $this->assertNotNull($last);
        $this->assertBudget($pool, 3);
        $pool->settle($last->id, $last->channel);
        $this->assertBudget($pool);
        $pool->closeConnection('browser-a');
        $this->assertNull($pool->reserveNext());
    }

    public function testConnectionsAndWorkersShareOneBudgetAndPartialWindowCanBeOneByte(): void
    {
        $pool = new UploadMemoryPool(5, 'master');
        $pool->request('browser-a', 'same-id', 4);
        $pool->request('browser-b', 'same-id', 3);
        $first = $this->grant($pool);
        $second = $this->grant($pool);
        $this->assertSame('browser-a', $first->acceptKey);
        $this->assertSame('browser-b', $second->acceptKey);
        $this->assertSame(4, $first->sendUpToBytes);
        $this->assertSame(1, $second->sendUpToBytes);
        $this->assertBudget($pool, 4, 1);
        $this->assertNull($pool->reserveNext());
        $receipt = $pool->accept($first->id, 0, 4, 'worker-1');
        $this->assertNotNull($receipt);
        $this->assertBudget($pool, 4, 1);
        $pool->settle($receipt->id, $receipt->channel);
        $this->assertBudget($pool, 1);
        $next = $this->grant($pool);
        $this->assertSame('browser-b', $next->acceptKey);
        $this->assertSame(1, $next->offsetBytes);
        $this->assertSame(3, $next->sendUpToBytes);
        $this->assertBudget($pool, 1, 2);
    }

    public function testFileLargerThanPoolAdvancesInWindowsWithoutWholeFileReservation(): void
    {
        $pool = new UploadMemoryPool(3, 'master');
        $pool->request('browser', 'large', 11);
        $offset = 0;
        foreach ([3, 3, 3, 2] as $portionBytes) {
            $window = $this->grant($pool);
            $this->assertSame($offset, $window->offsetBytes);
            $this->assertSame($offset + $portionBytes, $window->sendUpToBytes);
            $this->assertBudget($pool, $portionBytes);
            $receipt = $pool->accept($window->id, $offset, $portionBytes, 'worker');
            $this->assertNotNull($receipt);
            $this->assertBudget($pool, $portionBytes);
            $pool->settle($receipt->id, 'worker');
            $this->assertBudget($pool);
            $offset += $portionBytes;
        }
        $this->assertSame(11, $offset);
        $this->assertFalse($pool->hasPending());
        $this->assertNull($pool->reserveNext());
    }

    public function testZeroSizeNeverCreatesAReservationOrWaiter(): void
    {
        $pool = new UploadMemoryPool(0, 'master');
        $pool->request('browser', 'empty', 0);
        $pool->request('browser', 'empty', 0);
        $this->assertFalse($pool->hasPending());
        $this->assertNull($pool->reserveNext());
        $pool->setLimitBytes(1);
        $this->assertNull($pool->reserveNext());
        $this->assertBudget($pool);
    }

    public function testDuplicateDemandKeepsQueuePositionAndPartialGrantsRejoinTheTail(): void
    {
        $pool = new UploadMemoryPool(2, 'master');
        $pool->request('browser', 'a', 4);
        $pool->request('browser', 'b', 1);
        $pool->request('browser', 'c', 1);
        $pool->request('browser', 'a', 4);
        $first = $this->grant($pool);
        $this->assertSame('a', $first->clientUploadId);
        $pool->request('browser', 'a', 4);
        $pool->setLimitBytes(3);
        $second = $this->grant($pool);
        $this->assertSame('b', $second->clientUploadId);
        $this->assertBudget($pool, 2, 1);
        $receipt = $pool->accept($first->id, 0, 2, 'worker');
        $this->assertNotNull($receipt);
        $pool->settle($receipt->id, 'worker');
        $third = $this->grant($pool);
        $fourth = $this->grant($pool);
        $this->assertSame('c', $third->clientUploadId);
        $this->assertSame('a', $fourth->clientUploadId);
        $this->assertSame(3, $fourth->sendUpToBytes);
        $this->assertBudget($pool, 1, 1, 1);
        $pool->closeConnection('browser');
        $this->assertFalse($pool->hasPending());
        $this->assertBudget($pool);
    }

    public function testDuplicateRequestWhileHoldingDoesNotCreateAnotherHold(): void
    {
        $pool = new UploadMemoryPool(10, 'master');
        $pool->request('browser', 'file', 6);
        $hold = $pool->reserveNext();
        $this->assertNotNull($hold);
        $this->assertSame(0, $pool->getSendUpToBytes('browser', 'file'));
        $pool->request('browser', 'file', 6);
        $this->assertNull($pool->reserveNext());
        $this->assertBudget($pool, 6);
        $pool->publish($hold->id);
        $pool->request('browser', 'file', 6);
        $this->assertSame(6, $pool->getSendUpToBytes('browser', 'file'));
        $this->assertNull($pool->reserveNext());
        $this->assertBudget($pool, 6);
    }

    public function testShrinkKeepsOldWindowsAndIncreaseMakesCapacityAvailableToWaiters(): void
    {
        $pool = new UploadMemoryPool(5, 'master');
        $pool->request('browser', 'a', 7);
        $first = $this->grant($pool);
        $pool->request('browser', 'b', 3);
        $pool->setLimitBytes(1);
        $this->assertSame(1, $pool->getLimitBytes());
        $this->assertBudget($pool, 5);
        $this->assertNull($pool->reserveNext());
        $receipt = $pool->accept($first->id, 0, 3, 'worker');
        $this->assertNotNull($receipt);
        $this->assertBudget($pool, 3, 2);
        $pool->settle($receipt->id, 'worker');
        $this->assertBudget($pool, 2);
        $this->assertNull($pool->reserveNext());
        $pool->setLimitBytes(4);
        $second = $this->grant($pool);
        $this->assertSame('a', $second->clientUploadId);
        $this->assertSame(7, $second->sendUpToBytes);
        $this->assertBudget($pool, 2, 2);
        $pool->setLimitBytes(0);
        $this->assertNull($pool->reserveNext());
        $pool->setLimitBytes(8);
        $third = $this->grant($pool);
        $this->assertSame('b', $third->clientUploadId);
        $this->assertSame(3, $third->sendUpToBytes);
        $this->assertBudget($pool, 2, 2, 3);
    }

    public function testReceiptsAreReleasedExactlyOnceAndOnlyForTheirOwnChannelGeneration(): void
    {
        $pool = new UploadMemoryPool(8, 'master');
        $pool->request('browser', 'file', 8);
        $window = $this->grant($pool);
        $receipt = $pool->accept($window->id, 0, 3, 'worker:old');
        $this->assertNotNull($receipt);
        $pool->settle('unknown', 'worker:old');
        $pool->settle($receipt->id, 'worker:new');
        $this->assertBudget($pool, 3, 5);
        $this->assertNull($pool->accept($window->id, 0, 3, 'worker:old'));
        $this->assertBudget($pool, 3, 5);
        $pool->settle($receipt->id, 'worker:old');
        $this->assertBudget($pool, 5);
        $pool->settle($receipt->id, 'worker:old');
        $this->assertNull($pool->accept($window->id, 0, 3, 'worker:old'));
        $this->assertBudget($pool, 5);
        $last = $pool->accept($window->id, 3, 5, 'worker:new');
        $this->assertNotNull($last);
        $this->assertNull($pool->publish($window->id));
        $this->assertNull($pool->accept($window->id, 3, 5, 'worker:new'));
        $pool->settle($receipt->id, 'worker:old');
        $this->assertBudget($pool, 5);
        $pool->settle($last->id, 'worker:new');
        $this->assertBudget($pool);
    }

    public function testCancelDropsUnusedCreditButKeepsAcceptedBytesAcrossRecreation(): void
    {
        $pool = new UploadMemoryPool(8, 'master');
        $pool->request('browser', 'file', 8);
        $old = $this->grant($pool);
        $receipt = $pool->accept($old->id, 0, 3, 'worker:old');
        $this->assertNotNull($receipt);
        $pool->cancel('browser', 'file');
        $pool->cancel('browser', 'file');
        $this->assertFalse($pool->hasPending());
        $this->assertNull($pool->getSendUpToBytes('browser', 'file'));
        $this->assertBudget($pool, 3);
        $pool->request('browser', 'file', 4);
        $new = $this->grant($pool);
        $this->assertNotSame($old->id, $new->id);
        $this->assertNotSame($old->requestId, $new->requestId);
        $this->assertSame(4, $new->sendUpToBytes);
        $this->assertNull($pool->publish($old->id));
        $this->assertNull($pool->accept($old->id, 3, 1, 'worker:new'));
        $pool->releaseUnused($old->id, 0);
        $this->assertBudget($pool, 3, 4);
        $pool->settle($receipt->id, 'worker:old');
        $this->assertBudget($pool, 4);
        $pool->settle($receipt->id, 'worker:old');
        $this->assertBudget($pool, 4);
    }

    public function testConnectionAndFinalChannelCleanupKeepOtherDestinationsIntact(): void
    {
        $pool = new UploadMemoryPool(10, 'master');
        $pool->request('a', 'one', 4);
        $pool->request('a', 'two', 3);
        $pool->request('b', 'one', 3);
        $first = $this->grant($pool);
        $held = $pool->reserveNext();
        $this->assertNotNull($held);
        $third = $this->grant($pool);
        $queued = $pool->accept($first->id, 0, 2, 'worker:old');
        $parked = $pool->accept($third->id, 0, 1, 'worker:new');
        $this->assertNotNull($queued);
        $this->assertNotNull($parked);
        $pool->closeConnection('a');
        $this->assertNull($pool->publish($held->id));
        $this->assertBudget($pool, 2, 1, 2);
        $pool->discardChannel('worker:old');
        $pool->discardChannel('worker:old');
        $this->assertBudget($pool, 1, 2);
        $pool->closeConnection('b');
        $this->assertBudget($pool, 1);
        $pool->discardChannel('worker:new');
        $this->assertBudget($pool);
        $this->assertNull($pool->reserveNext());
        $this->assertFalse($pool->hasPending());
    }

    public function testAbsoluteUnusedReleaseIsMonotoneAndCannotFreeAcceptedReceipts(): void
    {
        $pool = new UploadMemoryPool(10, 'master');
        $pool->request('browser', 'file', 10);
        $window = $this->grant($pool);
        $first = $pool->accept($window->id, 0, 3, 'worker');
        $this->assertNotNull($first);
        $pool->releaseUnused($window->id, 4);
        $this->assertBudget($pool, 3, 4);
        $pool->releaseUnused($window->id, 4);
        $pool->releaseUnused($window->id, 7);
        $this->assertBudget($pool, 3, 4);
        $second = $pool->accept($window->id, 3, 2, 'worker');
        $this->assertNotNull($second);
        $pool->releaseUnused($window->id, 4);
        $this->assertBudget($pool, 3, 2, 2);
        $pool->releaseUnused($window->id, 0);
        $pool->releaseUnused($window->id, 0);
        $this->assertBudget($pool, 3, 2);
        $this->assertSame(10, $pool->getSendUpToBytes('browser', 'file'));
        $this->assertNull($pool->accept($window->id, 5, 1, 'worker'));
        $pool->settle($first->id, 'worker');
        $this->assertBudget($pool, 2);
        $pool->settle($second->id, 'worker');
        $this->assertBudget($pool);
    }

    public function testReleasedHoldCanBeRetriedWithoutAdvancingThePublishedBoundary(): void
    {
        $pool = new UploadMemoryPool(4, 'master');
        $pool->request('browser', 'a', 6);
        $pool->request('browser', 'b', 1);
        $hold = $pool->reserveNext();
        $this->assertNotNull($hold);
        $pool->releaseUnused($hold->id, 0);
        $pool->releaseUnused($hold->id, 0);
        $this->assertBudget($pool);
        $this->assertSame('b', $this->grant($pool)->clientUploadId);
        $retry = $this->grant($pool);
        $this->assertSame('a', $retry->clientUploadId);
        $this->assertSame(0, $retry->offsetBytes);
        $this->assertSame(3, $retry->sendUpToBytes);
        $this->assertNotSame($hold->id, $retry->id);
        $this->assertNull($pool->publish($hold->id));
        $this->assertBudget($pool, 1, 3);
    }

    public function testPoolGenerationsRefuseOldWindowsAndReceipts(): void
    {
        $oldPool = new UploadMemoryPool(3, 'node:old-boot');
        $oldPool->request('browser', 'file', 3);
        $oldWindow = $this->grant($oldPool);
        $oldReceipt = $oldPool->accept($oldWindow->id, 0, 3, 'worker');
        $this->assertNotNull($oldReceipt);
        $pool = new UploadMemoryPool(3, 'node:new-boot');
        $pool->request('browser', 'file', 3);
        $window = $this->grant($pool);
        $receipt = $pool->accept($window->id, 0, 3, 'worker');
        $this->assertNotNull($receipt);
        $pool->settle($oldReceipt->id, 'worker');
        $pool->releaseUnused($oldWindow->id, 0);
        $this->assertNull($pool->publish($oldWindow->id));
        $this->assertNull($pool->accept($oldWindow->id, 0, 3, 'worker'));
        $this->assertBudget($pool, 3);
        $pool->settle($receipt->id, 'worker');
        $this->assertBudget($pool);
        $this->assertBudget($oldPool, 3);
    }

    public function testTwoOrderedPoolHoldsCanTrimExcessAndTransferBytesIndependently(): void
    {
        // Test-local acquisition is low-node first. Live peer ordering/progress belongs to slice 8.
        $low = new UploadMemoryPool(5, 'node-a:boot');
        $high = new UploadMemoryPool(2, 'node-b:boot');
        $low->request('browser', 'file', 7);
        $high->request('browser', 'file', 7);
        $lowHold = $low->reserveNext();
        $highHold = $high->reserveNext();
        $this->assertNotNull($lowHold);
        $this->assertNotNull($highHold);
        $this->assertBudget($low, 5);
        $this->assertBudget($high, 2);
        $low->releaseUnused($lowHold->id, $highHold->remainingBytes);
        $lowWindow = $low->publish($lowHold->id);
        $highWindow = $high->publish($highHold->id);
        $this->assertNotNull($lowWindow);
        $this->assertNotNull($highWindow);
        $this->assertSame(2, $lowWindow->sendUpToBytes);
        $this->assertSame(2, $highWindow->sendUpToBytes);
        $this->assertBudget($low, 2);
        $this->assertBudget($high, 2);
        $sourceReceipt = $low->accept($lowWindow->id, 0, 2, 'peer-b:link-1');
        $this->assertNotNull($sourceReceipt);
        $this->assertBudget($low, 2);
        // Recipient accepts into its own reservation before source discards its retained copy.
        $destinationReceipt = $high->accept($highWindow->id, 0, 2, 'worker-b:boot');
        $this->assertNotNull($destinationReceipt);
        $this->assertBudget($low, 2);
        $this->assertBudget($high, 2);
        $low->settle($sourceReceipt->id, 'peer-b:link-1');
        $this->assertBudget($low);
        $this->assertBudget($high, 2);
        // Capacity is unavailable at high: release the lower retry hold instead of waiting with it.
        $retry = $low->reserveNext();
        $this->assertNotNull($retry);
        $this->assertNull($high->reserveNext());
        $low->releaseUnused($retry->id, 0);
        $this->assertBudget($low);
        $high->settle($destinationReceipt->id, 'worker-b:boot');
        $this->assertBudget($high);
        $this->assertSame(2, $this->grant($low)->offsetBytes);
        $this->assertSame(2, $this->grant($high)->offsetBytes);
    }

    public function testReservationSnapshotsCannotAlterPoolAccounting(): void
    {
        $pool = new UploadMemoryPool(4, 'master');
        $pool->request('browser', 'file', 4);
        $hold = $pool->reserveNext();
        $this->assertNotNull($hold);
        $detached = $hold->withAccounting(0, 4, 4);
        $this->assertSame(0, $detached->remainingBytes);
        $window = $pool->publish($hold->id);
        $this->assertNotNull($window);
        $this->assertSame(4, $window->remainingBytes);
        $this->assertSame(0, $window->nextOffsetBytes);
        $this->assertNull($hold->sendUpToBytes);
        $this->assertBudget($pool, 4);
    }

    #[DataProvider('invalidInputs')]
    public function testContradictoryInternalInputRaisesTheExactInvariantFamily(string $operation): void
    {
        $pool = new UploadMemoryPool(6, 'master');
        $pool->request('browser', 'file', 8);
        $hold = $pool->reserveNext();
        $this->assertNotNull($hold);
        if ($operation !== 'unpublished') {
            $pool->publish($hold->id);
        }
        $this->expectException(LogicException::class);
        try {
            match ($operation) {
                'negative-limit' => $pool->setLimitBytes(-1),
                'negative-constructor-limit' => new UploadMemoryPool(-1, 'master'),
                'empty-generation' => new UploadMemoryPool(1, ''),
                'empty-connection' => $pool->request('', 'file', 1),
                'empty-upload' => $pool->request('browser', '', 1),
                'negative-desired' => $pool->request('browser', 'other', -1),
                'changed-desired' => $pool->request('browser', 'file', 9),
                'unpublished' => $pool->accept($hold->id, 0, 1, 'worker'),
                'negative-offset' => $pool->accept($hold->id, -1, 1, 'worker'),
                'zero-portion' => $pool->accept($hold->id, 0, 0, 'worker'),
                'negative-portion' => $pool->accept($hold->id, 0, -1, 'worker'),
                'empty-channel' => $pool->accept($hold->id, 0, 1, ''),
                'offset-gap' => $pool->accept($hold->id, 1, 1, 'worker'),
                'oversize-portion' => $pool->accept($hold->id, 0, 7, 'worker'),
                'negative-unused' => $pool->releaseUnused($hold->id, -1),
                'excess-unused' => $pool->releaseUnused($hold->id, 7),
            };
        } finally {
            $this->assertBudget($pool, 6);
        }
    }

    public static function invalidInputs(): iterable
    {
        foreach ([
            'negative-limit', 'negative-constructor-limit', 'empty-generation', 'empty-connection',
            'empty-upload', 'negative-desired', 'changed-desired', 'unpublished', 'negative-offset',
            'zero-portion', 'negative-portion', 'empty-channel', 'offset-gap', 'oversize-portion',
            'negative-unused', 'excess-unused',
        ] as $operation) {
            yield $operation => [$operation];
        }
    }

    private function grant(UploadMemoryPool $pool): UploadMemoryReservation
    {
        $hold = $pool->reserveNext();
        $this->assertNotNull($hold);
        $window = $pool->publish($hold->id);
        $this->assertNotNull($window);

        return $window;
    }

    private function assertBudget(UploadMemoryPool $pool, int ...$obligations): void
    {
        $expected = array_sum($obligations);
        $this->assertSame($expected, $pool->getUsedBytes());
        $this->assertGreaterThanOrEqual(0, $pool->getUsedBytes());
        $this->assertSame(max(0, $pool->getLimitBytes() - $expected), $pool->getFreeBytes());
    }
}
