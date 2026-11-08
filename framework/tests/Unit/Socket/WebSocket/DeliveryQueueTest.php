<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\WebSocket;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\WebSocket\Delivery\DeliveryFrame;
use Hilos\Socket\WebSocket\Delivery\DeliveryCleanup;
use Hilos\Socket\WebSocket\Delivery\DeliveryQueue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SplQueue;
use WeakReference;

final class DeliveryQueueTest extends TestCase
{
    public function testPortionedPackingPublishesOneWholeOriginalBatchAndKeepsLaterHoldAndCadence(): void
    {
        $queue = new DeliveryQueue();
        $json = [];
        for ($index = 0; $index < 8192; $index++) {
            $json[] = '{"type":"event","data":{"n":' . $index . '}}';
            $queue->enqueue(DeliveryFrame::fromJson($json[$index]), 100);
        }
        $bytes = $queue->getPendingBytes();
        self::assertSame([], $queue->drainPortion(100, 0));
        self::assertNull($queue->getNextDueAt());
        self::assertSame([], $queue->drainPortion(100, 64));
        self::assertTrue($queue->hasPendingPacking());
        self::assertSame($bytes, $queue->getPendingBytes(), 'A partially built batch still owns all of its transport bytes');
        self::assertSame(1100, $queue->getNextDueAt());
        $queue->setWindowMs(250);
        $queue->beginSubscription('later', 200);
        $later = self::event(-1);
        $queue->enqueue($later, 200);
        $laterBytes = WebSocketClient::frameHeaderBytes(strlen($later->json)) + strlen($later->json);
        self::assertSame($bytes + $laterBytes, $queue->getPendingBytes());
        $packages = [];
        for ($portion = 0; $portion < 140; $portion++) {
            foreach ($queue->drainPortion(10_000, 64) as $package) {
                $packages[] = $package;
            }
        }
        self::assertSame(['{"type":"signal_batch","data":{"frames":[' . implode(',', $json) . ']}}'], $packages);
        self::assertFalse($queue->hasPendingPacking());
        self::assertSame(1100, $queue->getNextDueAt(), 'Later packing portions do not move the admitted deadline');
        self::assertSame($laterBytes, $queue->getPendingBytes());
        self::assertSame([], $queue->drain(10_000), 'The later incomplete subscription still holds its own packet');
        $queue->completeDelivery('later', 10_001);
        self::assertSame([$later->json], $queue->drain(10_001));
        self::assertSame(10_251, $queue->getNextDueAt());
        self::assertSame(0, $queue->getPendingBytes());
    }

    public function testSingleStepPackingKeepsOpaqueAndPrebuiltBatchBoundariesAndExactByteOwnership(): void
    {
        $queue = new DeliveryQueue();
        $first = DeliveryFrame::fromJson('{ "type":"first", "data":{"map":{},"list":[],"0":"value"} }');
        $second = self::event(2);
        $batch = '{"type":"signal_batch","data":{"frames":[{"type":"inside","data":null}]}}';
        foreach ([$first, $second, DeliveryFrame::fromJson('opaque'), DeliveryFrame::fromJson($batch), self::event(3)] as $frame) {
            $queue->enqueue($frame, 0);
        }
        $remaining = $queue->getPendingBytes();
        $packages = [];
        for ($step = 0; $step < 16; $step++) {
            $portion = $queue->drainPortion(0, 1);
            self::assertLessThanOrEqual(1, count($portion));
            foreach ($portion as $package) {
                $remaining -= WebSocketClient::frameHeaderBytes(strlen($package)) + strlen($package);
                $packages[] = $package;
            }
            self::assertSame($remaining, $queue->getPendingBytes(), 'Transport ownership transfers only with a whole package');
        }
        self::assertSame([
            '{"type":"signal_batch","data":{"frames":[' . $first->json . ',' . $second->json . ']}}',
            'opaque', $batch, self::event(3)->json,
        ], $packages);
        self::assertSame(0, $remaining);
        self::assertFalse($queue->hasFrames());
    }

    public function testClosingAnUnfinishedPacketRetiresOriginalFramesWithoutRetainingTheQueue(): void
    {
        $pending = new SplQueue();
        $cleanup = new DeliveryCleanup(static function (Generator $retirement) use ($pending): void { $pending->enqueue($retirement); });
        $queue = new DeliveryQueue(cleanup: $cleanup);
        $references = [];
        for ($index = 0; $index < 8192; $index++) {
            $frame = self::event($index);
            $references[] = WeakReference::create($frame);
            $queue->enqueue($frame, 0);
        }
        unset($frame);
        self::assertSame([], $queue->drainPortion(0, 1));
        $queue->close();
        self::assertFalse($queue->hasFrames());
        self::assertFalse($queue->hasPendingPacking());
        self::assertSame(0, $queue->getPendingBytes());
        self::assertCount(1, $pending);
        $weakQueue = WeakReference::create($queue);
        unset($queue);
        self::assertNull($weakQueue->get(), 'A retired packet never keeps its socket queue owner alive');
        $retirement = $pending->dequeue();
        $retirement->rewind();
        self::assertNotNull($references[8191]->get());
        $retirement->next();
        self::assertNull($references[8191]->get());
        self::assertNotNull($references[8190]->get());
        while ($retirement->valid()) {
            $retirement->next();
        }
        foreach ($references as $reference) {
            self::assertNull($reference->get());
        }
    }

    public function testWorkDeadlineDistinguishesHeldEmptyUrgentGraceAndCapturedPacking(): void
    {
        $queue = new DeliveryQueue();
        self::assertNull($queue->nextWorkAt(0));
        $queue->enqueue(self::event(1), 5);
        self::assertSame(5, $queue->nextWorkAt(5));
        self::assertNull($queue->getNextDueAt(), 'Reading never captures the packet');
        $queue->drain(5);
        self::assertNull($queue->nextWorkAt(1005), 'An empty queue owes no work at its raw deadline');
        self::assertSame(1005, $queue->getNextDueAt());
        $queue->enqueue(self::event(2), 6);
        $queue->setWindowMs(2000);
        self::assertSame(1005, $queue->nextWorkAt(6));
        self::assertSame(1005, $queue->nextWorkAt(1005));
        self::assertSame(6, $queue->nextWorkAt(6, force: true));
        $queue->beginSubscription('held', 6);
        self::assertNull($queue->nextWorkAt(1005, force: true));
        $queue->completeDelivery('held', 1005);
        self::assertSame(1005, $queue->nextWorkAt(1005));
        $queue->drain(1005);
        $queue->enqueue(self::event(3), 1006);
        self::assertSame(1006, $queue->nextWorkAt(1006), 'Completion opens immediate reply grace');
        $queue->drain(2999);
        $queue->enqueue(self::event(4), 3005);
        self::assertSame(4999, $queue->nextWorkAt(3005), 'Grace is over at equality');
        $queue->acceptRequest(3006);
        self::assertSame(3006, $queue->nextWorkAt(3006));
        $queue->drainPortion(3006, 1);
        $queue->beginSubscription('later', 3007);
        self::assertSame(3007, $queue->nextWorkAt(3007), 'The admitted packet survives a later hold');
        $queue->drain(3007);
        self::assertNull($queue->nextWorkAt(3007));
        $queue->close();
        self::assertNull($queue->nextWorkAt(9000, force: true));
    }

    public function testQuietDeliveryWaitsOnlyForDrainAndNeverCreatesAnEmptyPackage(): void
    {
        $queue = new DeliveryQueue();
        self::assertSame([], $queue->drain(0));
        self::assertNull($queue->getNextDueAt());
        $first = DeliveryFrame::fromJson('{ "type":"event", "data":{"map":{},"list":[]} }');
        $second = DeliveryFrame::fromJson('{"type":"another","data":null,"requestId":"r","outcome":"success","time":7}');
        $queue->enqueue($first, 5);
        $queue->enqueue($second, 5);
        self::assertNull($queue->getNextDueAt());
        self::assertTrue($queue->hasFrames());
        self::assertSame([
            '{"type":"signal_batch","data":{"frames":[' . $first->json . ',' . $second->json . ']}}',
        ], $queue->drain(5));
        self::assertFalse($queue->hasFrames());
        self::assertSame([], $queue->drain(5));
        $queue->enqueue($first, 2000);
        self::assertSame([$first->json], $queue->drain(2000));
    }

    public function testBusyCadenceIsFixedFromLastDeliveryAndNotExtendedByNewFrames(): void
    {
        $queue = new DeliveryQueue();
        $frame = self::event(0);
        $queue->enqueue($frame, 0);
        self::assertSame([$frame->json], $queue->drain(0));
        for ($now = 100; $now < 1000; $now += 100) {
            $queue->enqueue(self::event($now), $now);
            self::assertSame([], $queue->drain($now));
            self::assertSame(1000, $queue->getNextDueAt());
        }
        $packages = $queue->drain(1000);
        self::assertCount(1, $packages);
        $batch = json_decode($packages[0]);
        self::assertSame('signal_batch', $batch->type);
        self::assertSame(range(100, 900, 100), array_map(static fn ($frame): int => $frame->data->n, $batch->data->frames));
        self::assertSame(2000, $queue->getNextDueAt());
        $queue->enqueue($frame, 1001);
        self::assertSame([], $queue->drain(1999));
        self::assertSame([$frame->json], $queue->drain(2000));
    }

    public function testAcceptedRequestFlushesOnlyItsSocketAndOpensImmediateGrace(): void
    {
        $a = new DeliveryQueue();
        $b = new DeliveryQueue();
        $frame = self::event(1);
        foreach ([$a, $b] as $queue) {
            $queue->enqueue($frame, 0);
            $queue->drain(0);
            $queue->enqueue($frame, 100);
        }
        $a->acceptRequest(200);
        self::assertSame([$frame->json], $a->drain(200));
        self::assertSame([], $b->drain(200));
        $a->enqueue($frame, 1199);
        self::assertSame([$frame->json], $a->drain(1199));
        $a->enqueue($frame, 1200);
        self::assertSame([], $a->drain(1200));
        self::assertSame([$frame->json], $b->drain(1200));
    }

    public function testDelayedUpdateCompletionStartsFreshGraceFromTheReply(): void
    {
        $queue = new DeliveryQueue();
        $queue->beginUpdate('u1', 0);
        $queue->beginUpdate('u2', 1);
        $frame = self::event(1);
        $queue->enqueue($frame, 2500);
        $queue->drain(2500);
        $queue->enqueue($frame, 2600);
        self::assertSame([], $queue->drain(2600));
        $queue->completeDelivery('u1', 2700);
        self::assertSame([$frame->json], $queue->drain(2700));
        $queue->enqueue($frame, 3699);
        self::assertSame([$frame->json], $queue->drain(3699));
        $queue->enqueue($frame, 3700);
        $queue->completeDelivery('u1', 3700);
        self::assertSame([], $queue->drain(3700), 'Duplicate completion cannot reopen grace');
        $queue->completeDelivery('u2', 3800);
        self::assertSame([$frame->json], $queue->drain(3800));
    }

    /** @return iterable<string, array{DeliveryFrame}> Reply forms that bypass the busy deadline */
    public static function urgentReplies(): iterable
    {
        yield 'success' => [DeliveryFrame::fromJson('{"type":"action_success","data":{},"requestId":"r","outcome":"success"}')];
        yield 'error' => [DeliveryFrame::fromJson('{"type":"action_error","data":{"requestId":"r"},"outcome":"fail"}')];
        yield 'window' => [DeliveryFrame::fromJson('{"type":"table_window","data":{}}')];
        yield 'refusal' => [DeliveryFrame::fromJson('{"type":"table_window_refused","data":{}}')];
        yield 'sender correlated' => [DeliveryFrame::fromJson('{"type":"project_reply","data":{}}', urgent: true)];
    }

    /** @param DeliveryFrame $reply Prepared correlated reply */
    #[DataProvider('urgentReplies')]
    public function testUrgentReplyFlushesPriorWorkAndGrantsGraceAtArrival(DeliveryFrame $reply): void
    {
        $queue = new DeliveryQueue();
        $frame = self::event(1);
        $queue->enqueue($frame, 5000);
        $queue->drain(5000);
        $queue->enqueue($frame, 5100);
        $queue->enqueue($reply, 5200);
        $batch = json_decode($queue->drain(5200)[0]);
        self::assertSame('event', $batch->data->frames[0]->type);
        self::assertEquals($reply->getEnvelope(), $batch->data->frames[1]);
        $queue->enqueue($frame, 6199);
        self::assertSame([$frame->json], $queue->drain(6199));
        $queue->enqueue($frame, 6200);
        self::assertSame([], $queue->drain(6200));
    }

    public function testSettingsChangeAndResetPreserveAnAlreadyOpenedDeadline(): void
    {
        $queue = new DeliveryQueue();
        self::assertSame(1000, $queue->getWindowMs());
        $frame = self::event(1);
        $queue->enqueue($frame, 0);
        $queue->drain(0);
        $queue->setWindowMs(100);
        $queue->enqueue($frame, 100);
        self::assertSame([], $queue->drain(999));
        self::assertSame([$frame->json], $queue->drain(1000));
        self::assertSame(1100, $queue->getNextDueAt());
        $queue->setWindowMs(3000);
        $queue->enqueue($frame, 1001);
        self::assertSame([$frame->json], $queue->drain(1100));
        self::assertSame(4100, $queue->getNextDueAt());
        $queue->setWindowMs(1000);
        $queue->enqueue($frame, 1101);
        self::assertSame([], $queue->drain(4099));
        self::assertSame([$frame->json], $queue->drain(4100));
        self::assertSame(5100, $queue->getNextDueAt());
    }

    public function testExistingGraceKeepsItsDeadlineAndNextReplyUsesCurrentSetting(): void
    {
        $queue = new DeliveryQueue();
        $frame = self::event(1);
        $queue->acceptRequest(0);
        $queue->setWindowMs(100);
        $queue->enqueue($frame, 900);
        self::assertSame([$frame->json], $queue->drain(900));
        $queue->enqueue($frame, 999);
        self::assertSame([$frame->json], $queue->drain(999));
        $queue->enqueue($frame, 1000);
        self::assertSame([], $queue->drain(1000));
        $queue->acceptRequest(1000);
        self::assertSame([$frame->json], $queue->drain(1000));
        $queue->enqueue($frame, 1099);
        self::assertSame([$frame->json], $queue->drain(1099));
        $queue->enqueue($frame, 1100);
        self::assertSame([], $queue->drain(1100));
    }

    public function testInvalidWindowDoesNotReplaceTheLastValidSetting(): void
    {
        $queue = new DeliveryQueue(700);
        try {
            $queue->setWindowMs(0);
            self::fail('Nonpositive setting must be refused');
        } catch (InvalidFormatException) {
            self::assertSame(700, $queue->getWindowMs());
        }
        $this->expectException(InvalidFormatException::class);
        new DeliveryQueue(-1);
    }

    public function testSubscribeHoldBlocksQuietTimerUrgencyAndForcedShutdownUntilMatchingCompletion(): void
    {
        $queue = new DeliveryQueue();
        $queue->beginSubscription('sub', 0);
        $toast = self::event(1);
        $response = DeliveryFrame::fromJson('{"type":"page_response","data":{"page":"p","payload":{"data":{"x":1}}}}');
        $queue->enqueue($toast, 1);
        $queue->enqueue($response, 2);
        $queue->enqueue($response, 3);
        $queue->enqueue(DeliveryFrame::fromJson('{"type":"table_window","data":{}}'), 4);
        $queue->acceptRequest(10);
        $queue->beginUpdate('update', 11);
        $queue->completeDelivery('update', 2000);
        self::assertTrue($queue->isHeld());
        self::assertSame([], $queue->drain(2000));
        self::assertSame([], $queue->drain(100000, force: true));
        $queue->completeDelivery('wrong', 100001);
        self::assertTrue($queue->isHeld());
        $queue->completeDelivery('sub', 100002);
        self::assertFalse($queue->isHeld());
        $batch = json_decode($queue->drain(100002)[0]);
        self::assertSame(['event', 'page_response', 'page_response', 'table_window'], array_map(
            static fn ($frame): string => $frame->type,
            $batch->data->frames,
        ));
        $queue->enqueue($toast, 101001);
        self::assertSame([$toast->json], $queue->drain(101001));
    }

    public function testNewHoldReplacesIncompletePacketAndLateOldCompletionCannotReleaseIt(): void
    {
        $queue = new DeliveryQueue();
        $old = self::event(1);
        $new = self::event(2);
        $queue->beginSubscription('old', 0);
        $queue->enqueue($old, 1);
        $queue->beginSubscription('new', 2);
        $queue->enqueue($new, 3);
        $queue->cancelDelivery('old');
        $queue->completeDelivery('old', 10000);
        self::assertSame([], $queue->drain(10000, force: true));
        $queue->completeDelivery('new', 10001);
        self::assertSame([$new->json], $queue->drain(10001));
    }

    public function testCancelDiscardsIncompleteHoldAndUpdateCancellationDoesNotHoldTheQueue(): void
    {
        $queue = new DeliveryQueue();
        $frame = self::event(1);
        $queue->beginSubscription('sub', 0);
        $queue->enqueue($frame, 1);
        $queue->cancelDelivery('sub');
        self::assertFalse($queue->isHeld());
        self::assertFalse($queue->hasFrames());
        self::assertSame([], $queue->drain(5000, force: true));
        $queue->beginUpdate('u', 5000);
        $queue->cancelDelivery('u');
        $queue->enqueue($frame, 6000);
        self::assertSame([$frame->json], $queue->drain(6000));
        $queue->enqueue($frame, 6001);
        $queue->completeDelivery('u', 6001);
        self::assertSame([], $queue->drain(6001));
    }

    public function testCloseClearsFramesClocksAndTokens(): void
    {
        $queue = new DeliveryQueue();
        $frame = self::event(1);
        $queue->enqueue($frame, 0);
        $queue->drain(0);
        $queue->beginSubscription('s', 1);
        $queue->beginUpdate('u', 2);
        $queue->enqueue($frame, 3);
        $queue->close();
        self::assertFalse($queue->isHeld());
        self::assertFalse($queue->hasFrames());
        self::assertNull($queue->getNextDueAt());
        self::assertSame([], $queue->drain(4, force: true));
        $queue->completeDelivery('s', 5);
        $queue->completeDelivery('u', 5);
        $queue->enqueue($frame, 6);
        self::assertSame([$frame->json], $queue->drain(6));
        $queue->enqueue($frame, 7);
        self::assertSame([], $queue->drain(7));
    }

    public function testOpaqueTextAndPrebuiltBatchesKeepOrderWithoutNestedWrappers(): void
    {
        $queue = new DeliveryQueue();
        $a = self::event(1);
        $b = self::event(2);
        $opaque = DeliveryFrame::fromJson('legacy text');
        $batch = DeliveryFrame::fromJson('{"type":"signal_batch","data":{"frames":[{"type":"event","data":1}]}}');
        foreach ([$a, $b, $opaque, $a, $batch, $b] as $frame) {
            $queue->enqueue($frame, 0);
        }
        self::assertSame([
            '{"type":"signal_batch","data":{"frames":[' . $a->json . ',' . $b->json . ']}}',
            'legacy text', $a->json, $batch->json, $b->json,
        ], $queue->drain(0));
    }

    public function testCoalescingPrecedesPackingAndSharedFrameStaysIndependent(): void
    {
        $a = new DeliveryQueue();
        $b = new DeliveryQueue();
        for ($n = 1; $n <= 10; $n++) {
            $frame = DeliveryFrame::fromEnvelope(['type' => 'page_response', 'data' => ['page' => 'p', 'payload' => [
                'entities' => ['thing' => ['id' => 42, 'some_value' => $n]],
            ]]]);
            $a->enqueue($frame, $n);
            if ($n === 1) {
                $b->enqueue($frame, $n);
            }
        }
        $packages = $a->drain(10);
        self::assertCount(1, $packages);
        self::assertSame('page_response', json_decode($packages[0])->type);
        self::assertSame(10, json_decode($packages[0])->data->payload->entities->thing->some_value);
        self::assertSame(1, json_decode($b->drain(10)[0])->data->payload->entities->thing->some_value);
    }

    public function testStructuralBoundaryDoesNotAdvancePhysicalDeadline(): void
    {
        $queue = new DeliveryQueue();
        $queue->enqueue(self::event(0), 0);
        $queue->drain(0);
        $a = DeliveryFrame::fromJson('{"type":"page_response","data":{"page":"p","payload":{"tables":{"t":{"rows":['
            . '{"rowKey":"r","slots":{"left":1}}]}}}}}');
        $b = DeliveryFrame::fromJson('{"type":"page_response","data":{"page":"p","payload":{"tables":{"t":{"rows":['
            . '{"rowKey":"r","slots":{"right":2}}]}}}}}');
        $queue->enqueue($a, 10);
        $queue->enqueue($b, 20);
        self::assertSame([], $queue->drain(20));
        self::assertSame(
            '{"type":"signal_batch","data":{"frames":[' . $a->json . ',' . $b->json . ']}}',
            $queue->drain(1000)[0],
        );
    }

    public function testForcedShutdownReleasesCompletedWorkAndEmptyRequestsLeaveNoPermanentUrgency(): void
    {
        $queue = new DeliveryQueue();
        $frame = self::event(1);
        $queue->acceptRequest(0);
        $queue->enqueue($frame, 2000);
        self::assertSame([$frame->json], $queue->drain(2000));
        $queue->enqueue($frame, 2001);
        self::assertSame([], $queue->drain(2001));
        self::assertSame([$frame->json], $queue->drain(2001, force: true));
        self::assertSame([], $queue->drain(2001, force: true));
    }

    public function testPendingBytesAreWhatTheDrainHandsTheSocketAfterEveryChange(): void
    {
        $frames = [
            self::viewportCount(100),
            self::page('x', '1'),
            self::viewportCount(2),
            self::page('y', str_repeat('y', 200)),
            DeliveryFrame::fromJson('legacy text'),
            self::event(1),
            DeliveryFrame::fromJson('{"type":"signal_batch","data":{"frames":[{"type":"event","data":1}]}}'),
            self::viewportCount(3),
            DeliveryFrame::fromJson(str_repeat('z', 70000)),
            self::event(2),
            self::page('big', str_repeat('b', 70000)),
            self::page('big', 'small again'),
        ];
        for ($length = 0; $length <= count($frames); $length++) {
            $queue = new DeliveryQueue();
            foreach (array_slice($frames, 0, $length) as $frame) {
                $queue->enqueue($frame, 0);
            }
            $pending = $queue->getPendingBytes();
            self::assertSame(self::framedBytes($queue->drain(0, force: true)), $pending, "after {$length} frames");
            self::assertSame(0, $queue->getPendingBytes());
        }
    }

    public function testAHeldSubscriptionCountsUntilItsPacketIsReleasedOrDiscarded(): void
    {
        $queue = new DeliveryQueue();
        $queue->beginSubscription('s', 0);
        foreach ([self::event(1), DeliveryFrame::fromJson('legacy text'), self::page('x', '1')] as $frame) {
            $queue->enqueue($frame, 0);
        }
        $pending = $queue->getPendingBytes();
        self::assertSame([], $queue->drain(0, force: true));
        self::assertSame($pending, $queue->getPendingBytes());
        $queue->completeDelivery('s', 0);
        self::assertSame($pending, self::framedBytes($queue->drain(0)));

        $queue->beginSubscription('old', 0);
        $queue->enqueue(self::event(2), 0);
        $queue->beginSubscription('new', 0);
        self::assertSame(0, $queue->getPendingBytes());
        $queue->enqueue(DeliveryFrame::fromJson('legacy text'), 0);
        $queue->cancelDelivery('new');
        self::assertSame(0, $queue->getPendingBytes());
        $queue->enqueue(DeliveryFrame::fromJson('legacy text'), 0);
        $queue->enqueue(self::event(3), 0);
        $queue->close();
        self::assertSame(0, $queue->getPendingBytes());

        $queue->enqueue(self::event(4), 0);
        $queue->enqueue(DeliveryFrame::fromJson('legacy text'), 0);
        $pending = $queue->getPendingBytes();
        self::assertSame(self::framedBytes($queue->drain(0)), $pending);
    }

    /**
     * @param list<string> $packages Packages one drain released
     * @return int Bytes they take on the socket, each with the header the client frames it with
     */
    private static function framedBytes(array $packages): int
    {
        return array_sum(array_map(
            static fn (string $package): int => WebSocketClient::frameHeaderBytes(strlen($package)) + strlen($package),
            $packages,
        ));
    }

    /**
     * @param int $total Fixture row count
     * @return DeliveryFrame Count frame the next count of the same table replaces
     */
    private static function viewportCount(int $total): DeliveryFrame
    {
        return DeliveryFrame::fromEnvelope(['type' => 'table_viewport_count', 'data' => ['page' => 'p', 'tableKey' => 't', 'total' => $total]]);
    }

    /**
     * @param string $source Data source the patch writes
     * @param string $value Its value
     * @return DeliveryFrame Page patch the next patch of the same page merges with
     */
    private static function page(string $source, string $value): DeliveryFrame
    {
        return DeliveryFrame::fromEnvelope(['type' => 'page_response', 'data' => ['page' => 'p', 'payload' => ['data' => [$source => $value]]]]);
    }

    /**
     * @param int $n Fixture event number
     * @return DeliveryFrame Independent fixture event
     */
    private static function event(int $n): DeliveryFrame
    {
        return DeliveryFrame::fromEnvelope(['type' => 'event', 'data' => ['n' => $n]]);
    }
}
