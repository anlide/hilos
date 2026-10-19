<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files\Upload;

use Hilos\Core\Exception\LogicException;
use Hilos\Files\Upload\UploadMemoryPool;
use Hilos\Files\Upload\UploadMemoryReservation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Accounting seams for cumulative browser windows before destination assignment. */
final class UploadMemoryPendingReceiptTest extends TestCase
{
    public function testOnePortionCanCrossSeveralWindowsWithoutChangingUsedBytes(): void
    {
        $pool = new UploadMemoryPool(3, 'node:boot');
        $pool->request('browser', 'file', 11);
        $first = $this->grant($pool);
        $pool->setLimitBytes(7);
        $second = $this->grant($pool);
        $pool->setLimitBytes(11);
        $third = $this->grant($pool);
        $this->assertSame(11, $pool->getSendUpToBytes('browser', 'file'));
        $this->assertSame(11, $pool->getAvailableBytes($first->requestId, 0));

        $portion = $pool->acceptPending($first->requestId, 0, 9);
        $this->assertNotNull($portion);
        $this->assertNull($portion->channel);
        $this->assertSame(9, $portion->byteCount);
        $this->assertSame([$first->id => 3, $second->id => 4, $third->id => 2], $portion->reservationBytes);
        $this->assertBudget($pool, 9, 2);
        $this->assertSame(0, $pool->getAvailableBytes($first->requestId, 0));
        $this->assertSame(2, $pool->getAvailableBytes($first->requestId, 9));
        $this->assertNull($pool->acceptPending($first->requestId, 0, 9));
        $this->assertBudget($pool, 9, 2);

        $bound = $pool->assign($portion->id, 'worker:boot');
        $this->assertNotNull($bound);
        $this->assertSame($portion->id, $bound->id);
        $this->assertSame($portion->reservationBytes, $bound->reservationBytes);
        $this->assertSame('worker:boot', $bound->channel);
        $this->assertNull($portion->channel);
        $this->assertBudget($pool, 9, 2);
        $pool->settle($bound->id, 'worker:boot');
        $this->assertBudget($pool, 2);
        $last = $pool->acceptPending($first->requestId, 9, 2);
        $this->assertNotNull($last);
        $this->assertSame([$third->id => 2], $last->reservationBytes);
        $this->assertSame(0, $pool->getAvailableBytes($first->requestId, 11));
        $this->assertNull($pool->acceptPending($first->requestId, 9, 2));
        $pool->discardPending($last->id);
        $this->assertBudget($pool);
    }

    public function testRejectedMultiWindowPortionDoesNotConsumeItsFirstWindow(): void
    {
        $pool = new UploadMemoryPool(3, 'master');
        $pool->request('browser', 'file', 10);
        $first = $this->grant($pool);
        $pool->setLimitBytes(7);
        $second = $this->grant($pool);
        foreach ([[0, 8], [1, 6], [PHP_INT_MAX, 1]] as [$offset, $bytes]) {
            try {
                $pool->acceptPending($first->requestId, $offset, $bytes);
                $this->fail('An overrun or gap must be refused before consuming any window');
            } catch (LogicException) {
                $this->assertBudget($pool, 3, 4);
                $this->assertSame(7, $pool->getAvailableBytes($first->requestId, 0));
            }
        }
        $portion = $pool->acceptPending($first->requestId, 0, 7);
        $this->assertNotNull($portion);
        $this->assertSame([$first->id => 3, $second->id => 4], $portion->reservationBytes);
        $this->assertBudget($pool, 7);
        $pool->discardPending($portion->id);
        $this->assertBudget($pool);
    }

    public function testPartialAcceptanceCanContinueAcrossTheNextWindowAndOldApi(): void
    {
        $pool = new UploadMemoryPool(3, 'master');
        $pool->request('browser', 'file', 8);
        $first = $this->grant($pool);
        $direct = $pool->accept($first->id, 0, 2, 'worker:one');
        $this->assertNotNull($direct);
        $this->assertSame([$first->id => 2], $direct->reservationBytes);
        $pool->setLimitBytes(8);
        $second = $this->grant($pool);
        $this->assertSame(6, $pool->getAvailableBytes($first->requestId, 2));
        $portion = $pool->acceptPending($first->requestId, 2, 4);
        $this->assertNotNull($portion);
        $this->assertSame([$first->id => 1, $second->id => 3], $portion->reservationBytes);
        $this->assertSame(2, $pool->getAvailableBytes($first->requestId, 6));
        $this->assertBudget($pool, 2, 4, 2);
        $pool->settle($direct->id, 'worker:one');
        $pool->discardPending($portion->id);
        $this->assertBudget($pool, 2);
        $last = $pool->accept($second->id, 6, 2, 'worker:two');
        $this->assertNotNull($last);
        $pool->settle($last->id, 'worker:two');
        $this->assertBudget($pool);
    }

    public function testUnpublishedHoldNeverCountsAsBrowserPermission(): void
    {
        $pool = new UploadMemoryPool(2, 'master');
        $pool->request('browser', 'file', 5);
        $first = $this->grant($pool);
        $pool->setLimitBytes(5);
        $hold = $pool->reserveNext();
        $this->assertNotNull($hold);
        $this->assertSame(2, $pool->getAvailableBytes($first->requestId, 0));
        try {
            $pool->acceptPending($first->requestId, 0, 3);
            $this->fail('An unpublished hold must not authorize browser bytes');
        } catch (LogicException) {
            $this->assertBudget($pool, 2, 3);
        }
        $pool->publish($hold->id);
        $this->assertSame(5, $pool->getAvailableBytes($first->requestId, 0));
        $portion = $pool->acceptPending($first->requestId, 0, 5);
        $this->assertNotNull($portion);
        $pool->discardPending($portion->id);
        $this->assertBudget($pool);
    }

    public function testReleasedPublishedGapCannotBeCoveredByALaterWindow(): void
    {
        $pool = new UploadMemoryPool(3, 'master');
        $pool->request('browser', 'file', 7);
        $first = $this->grant($pool);
        $pool->setLimitBytes(7);
        $this->grant($pool);
        $pool->releaseUnused($first->id, 1);
        $this->assertSame(7, $pool->getSendUpToBytes('browser', 'file'));
        $this->assertSame(1, $pool->getAvailableBytes($first->requestId, 0));
        try {
            $pool->acceptPending($first->requestId, 0, 2);
            $this->fail('Cumulative permission must not spend returned capacity');
        } catch (LogicException) {
            $this->assertBudget($pool, 1, 4);
        }
        $portion = $pool->acceptPending($first->requestId, 0, 1);
        $this->assertNotNull($portion);
        $this->assertSame(0, $pool->getAvailableBytes($first->requestId, 1));
        $pool->closeConnection('browser');
        $this->assertBudget($pool, 1);
        $pool->discardPending($portion->id);
        $this->assertBudget($pool);
    }

    public function testPendingReceiptsSurviveCancelConnectionCloseAndSameIdRecreation(): void
    {
        $pool = new UploadMemoryPool(10, 'master');
        $pool->request('browser', 'file', 7);
        $old = $this->grant($pool);
        $pending = $pool->acceptPending($old->requestId, 0, 3);
        $this->assertNotNull($pending);
        $pool->closeConnection('browser');
        $this->assertBudget($pool, 3);
        $pool->request('browser', 'file', 7);
        $new = $this->grant($pool);
        $this->assertNotSame($old->requestId, $new->requestId);
        $this->assertSame(0, $pool->getAvailableBytes($old->requestId, 3));
        $this->assertNull($pool->acceptPending($old->requestId, 3, 1));
        $pool->discardChannel('worker:old');
        $pool->settle($pending->id, 'worker:old');
        $this->assertBudget($pool, 3, 7);
        $bound = $pool->assign($pending->id, 'worker:new');
        $this->assertNotNull($bound);
        $pool->discardPending($pending->id);
        $this->assertBudget($pool, 3, 7);
        $pool->settle($pending->id, 'worker:new');
        $this->assertBudget($pool, 7);
        $this->assertSame(7, $pool->getAvailableBytes($new->requestId, 0));
        $pool->cancel('browser', 'file');
        $this->assertBudget($pool);
    }

    public function testReceiptBindsOnceAndOnlyItsExactChannelCanReleaseIt(): void
    {
        $pool = new UploadMemoryPool(6, 'master');
        $pool->request('browser', 'file', 6);
        $window = $this->grant($pool);
        $pending = $pool->acceptPending($window->requestId, 0, 4);
        $this->assertNotNull($pending);
        $pool->settle($pending->id, 'worker:one');
        $pool->discardChannel('worker:one');
        $this->assertBudget($pool, 4, 2);
        $bound = $pool->assign($pending->id, 'worker:one');
        $this->assertNotNull($bound);
        $this->assertSame($bound, $pool->assign($pending->id, 'worker:one'));
        $this->assertNull($pool->assign($pending->id, 'worker:replacement'));
        $pool->settle($pending->id, 'worker:replacement');
        $pool->discardPending($pending->id);
        $this->assertBudget($pool, 4, 2);
        $pool->discardChannel('worker:one');
        $pool->settle($pending->id, 'worker:one');
        $pool->discardPending($pending->id);
        $this->assertNull($pool->assign($pending->id, 'worker:one'));
        $this->assertBudget($pool, 2);
    }

    public function testPendingDiscardIsIdempotentAndDoesNotFreeAnotherBrowser(): void
    {
        $pool = new UploadMemoryPool(9, 'master');
        $pool->request('browser:a', 'same', 5);
        $pool->request('browser:b', 'same', 4);
        $first = $this->grant($pool);
        $second = $this->grant($pool);
        $a = $pool->acceptPending($first->requestId, 0, 3);
        $b = $pool->acceptPending($second->requestId, 0, 2);
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $pool->discardPending('unknown');
        $pool->discardPending($a->id);
        $pool->discardPending($a->id);
        $this->assertBudget($pool, 2, 2, 2);
        $pool->closeConnection('browser:a');
        $this->assertBudget($pool, 2, 2);
        $this->assertSame(2, $pool->getAvailableBytes($second->requestId, 2));
        $pool->discardPending($b->id);
        $pool->closeConnection('browser:b');
        $this->assertBudget($pool);
    }

    public function testPriorMasterGenerationCannotSpendOrReleaseCurrentCredit(): void
    {
        $oldPool = new UploadMemoryPool(4, 'node:old');
        $oldPool->request('browser', 'file', 4);
        $old = $this->grant($oldPool);
        $oldPending = $oldPool->acceptPending($old->requestId, 0, 4);
        $this->assertNotNull($oldPending);
        $pool = new UploadMemoryPool(4, 'node:new');
        $pool->request('browser', 'file', 4);
        $current = $this->grant($pool);
        $this->assertSame(0, $pool->getAvailableBytes($old->requestId, 0));
        $this->assertNull($pool->acceptPending($old->requestId, 0, 4));
        $this->assertNull($pool->assign($oldPending->id, 'worker'));
        $pool->discardPending($oldPending->id);
        $pool->settle($oldPending->id, 'worker');
        $this->assertBudget($pool, 4);
        $this->assertSame(4, $pool->getAvailableBytes($current->requestId, 0));
        $this->assertBudget($oldPool, 4);
    }

    public function testShrinkingDoesNotRevokePendingBytesAndSettlementWakesTheExistingFifo(): void
    {
        $pool = new UploadMemoryPool(3, 'master');
        $pool->request('browser:a', 'large', 5);
        $first = $this->grant($pool);
        $pool->request('browser:b', 'small', 1);
        $pending = $pool->acceptPending($first->requestId, 0, 3);
        $this->assertNotNull($pending);
        $pool->setLimitBytes(1);
        $this->assertBudget($pool, 3);
        $this->assertTrue($pool->hasPending());
        $this->assertNull($pool->reserveNext());
        $bound = $pool->assign($pending->id, 'worker');
        $this->assertNotNull($bound);
        $pool->settle($bound->id, 'worker');
        $this->assertBudget($pool);
        $next = $this->grant($pool);
        $this->assertSame($first->requestId, $next->requestId);
        $this->assertSame(3, $next->offsetBytes);
        $this->assertSame(4, $next->sendUpToBytes);
        $pending = $pool->acceptPending($next->requestId, 3, 1);
        $this->assertNotNull($pending);
        $pool->discardPending($pending->id);
        $last = $this->grant($pool);
        $this->assertSame('browser:b', $last->acceptKey);
        $this->assertSame(1, $pool->getAvailableBytes($last->requestId, 0));
        $this->assertBudget($pool, 1);
    }

    public function testReturnedHoldDoesNotLeaveAnExpiredWindowInTheRequestIndex(): void
    {
        $pool = new UploadMemoryPool(3, 'master');
        $pool->request('browser', 'file', 5);
        $hold = $pool->reserveNext();
        $this->assertNotNull($hold);
        $this->assertSame(0, $pool->getAvailableBytes($hold->requestId, 0));
        $pool->releaseUnused($hold->id, 0);
        $this->assertSame(0, $pool->getAvailableBytes($hold->requestId, 0));
        $retry = $this->grant($pool);
        $this->assertSame($hold->requestId, $retry->requestId);
        $this->assertNotSame($hold->id, $retry->id);
        $this->assertSame(3, $pool->getAvailableBytes($hold->requestId, 0));
        $pending = $pool->acceptPending($hold->requestId, 0, 3);
        $this->assertNotNull($pending);
        $this->assertSame([$retry->id => 3], $pending->reservationBytes);
        $pool->discardPending($pending->id);
        $this->assertBudget($pool);
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidPendingInputsPreserveAllObligations(string $operation): void
    {
        $pool = new UploadMemoryPool(4, 'master');
        $pool->request('browser', 'file', 4);
        $window = $this->grant($pool);
        $this->expectException(LogicException::class);
        try {
            match ($operation) {
                'negative-check-offset' => $pool->getAvailableBytes($window->requestId, -1),
                'negative-offset' => $pool->acceptPending($window->requestId, -1, 1),
                'zero-size' => $pool->acceptPending($window->requestId, 0, 0),
                'negative-size' => $pool->acceptPending($window->requestId, 0, -1),
                'empty-channel' => $pool->assign('unknown', ''),
            };
        } finally {
            $this->assertBudget($pool, 4);
            $this->assertSame(4, $pool->getAvailableBytes($window->requestId, 0));
        }
    }

    public static function invalidInputs(): iterable
    {
        foreach (['negative-check-offset', 'negative-offset', 'zero-size', 'negative-size', 'empty-channel'] as $operation) {
            yield $operation => [$operation];
        }
    }

    public function testPendingPartsCoalesceAfterCancelAndOldPartIdsCannotSettleAgain(): void
    {
        $pool = new UploadMemoryPool(3, 'node:boot');
        $pool->request('browser', 'file', 8);
        $first = $this->grant($pool);
        $pool->setLimitBytes(8);
        $second = $this->grant($pool);
        $head = $pool->acceptPending($first->requestId, 0, 2);
        $tail = $pool->acceptPending($first->requestId, 2, 6);
        $this->assertNotNull($head);
        $this->assertNotNull($tail);
        $pool->cancel('browser', 'file');
        $combined = $pool->coalescePending([$head->id, $tail->id]);
        $this->assertNotNull($combined);
        $this->assertSame($head->id, $combined->id);
        $this->assertSame(8, $combined->byteCount);
        $this->assertSame([$first->id => 3, $second->id => 5], $combined->reservationBytes);
        $this->assertNull($combined->channel);
        $this->assertBudget($pool, 8);
        $pool->discardPending($tail->id);
        $pool->settle($tail->id, 'worker:boot');
        $this->assertBudget($pool, 8);
        $this->assertNotNull($pool->assign($combined->id, 'worker:boot'));
        $pool->discardPending($combined->id);
        $this->assertBudget($pool, 8);
        $pool->settle($combined->id, 'worker:boot');
        $this->assertBudget($pool);
    }

    /** @return iterable<string, array{string}> Every invalid part must fail the whole coalescing */
    public static function invalidCoalescingCases(): iterable
    {
        foreach (['duplicate', 'unknown', 'assigned', 'old-generation'] as $kind) {
            yield $kind => [$kind];
        }
    }

    /** @param string $kind Why the second part is not a distinct pending receipt */
    #[DataProvider('invalidCoalescingCases')]
    public function testCoalescingFailureLeavesEveryPartAndObligationUntouched(string $kind): void
    {
        $pool = new UploadMemoryPool(8, 'node:boot');
        $pool->request('browser', 'file', 8);
        $grant = $this->grant($pool);
        $first = $pool->acceptPending($grant->requestId, 0, 2);
        $second = $pool->acceptPending($grant->requestId, 2, 3);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        if ($kind === 'assigned') {
            $pool->assign($second->id, 'worker:boot');
        }
        $invalidId = match ($kind) {
            'duplicate' => $first->id,
            'assigned' => $second->id,
            'unknown' => 'unknown',
            'old-generation' => 'node:prior:1',
        };
        try {
            $pool->coalescePending([$first->id, $invalidId]);
            $this->fail('The entire coalescing must fail before any mutation');
        } catch (LogicException) {
            $this->assertBudget($pool, 8);
            $this->assertSame(3, $pool->getAvailableBytes($grant->requestId, 5));
        }
        $this->assertNotNull($pool->assign($first->id, 'worker:boot'));
        $pool->settle($first->id, 'worker:boot');
        $this->assertBudget($pool, 6);
        $this->assertNotNull($pool->assign($second->id, 'worker:boot'));
        $pool->settle($second->id, 'worker:boot');
        $this->assertBudget($pool, 3);
        $pool->closeConnection('browser');
        $this->assertBudget($pool);
    }

    public function testCoalescingEmptyAndSinglePartsPreservesIdentityAndByteCount(): void
    {
        $pool = new UploadMemoryPool(3, 'master');
        $this->assertNull($pool->coalescePending([]));
        $pool->request('browser', 'file', 3);
        $grant = $this->grant($pool);
        $pending = $pool->acceptPending($grant->requestId, 0, 3);
        $this->assertNotNull($pending);
        $combined = $pool->coalescePending([$pending->id]);
        $this->assertEquals($pending, $combined);
        $this->assertBudget($pool, 3);
        $pool->discardPending($pending->id);
        $this->assertBudget($pool);
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
