<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Generator;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Daemon\ParkedAgentSignal;
use Hilos\Core\Daemon\SignalDispatch;
use Hilos\Core\Daemon\SignalDispatchPhase;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Files\Upload\UploadMemoryMaster;
use Hilos\Files\Upload\UploadMemoryPool;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Socket\WebSocket\Delivery\DeliveryCleanup;
use Hilos\Socket\WebSocket\Delivery\DeliveryFrame;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use SplQueue;
use Throwable;
use WeakReference;

/** A portion never spends the next portion's step, and settlement releases actual payloads. */
final class SignalDispatchTest extends TestCase
{
    public function testPortionsKeepFifoWithoutAnExtraEffectAtTheBoundary(): void
    {
        $sent = [];
        $work = (static function () use (&$sent): Generator {
            yield;
            for ($index = 0; $index < 131; $index++) {
                $sent[] = $index;
                yield;
            }
        })();
        $dispatch = new SignalDispatch($this->signal(), static fn() => $work);
        $dispatch->runPortion(0);
        self::assertSame([], $sent);
        $dispatch->runPortion(64);
        self::assertSame(range(0, 63), $sent);
        self::assertTrue($dispatch->hasWork());
        $dispatch->runPortion(64);
        self::assertSame(range(0, 127), $sent);
        $dispatch->runPortion(64);
        self::assertSame(range(0, 130), $sent);
        self::assertFalse($dispatch->hasWork());
        self::assertSame(SignalDispatchPhase::Complete, $dispatch->phase);
        self::assertNull($dispatch->getSignal());
        $dispatch->runPortion(64);
        self::assertSame(range(0, 130), $sent);
    }

    public function testCancellationBetweenPortionsNeverExecutesAnotherStep(): void
    {
        $sent = 0;
        $work = (static function () use (&$sent): Generator {
            yield;
            while (true) {
                $sent++;
                yield;
            }
        })();
        $dispatch = new SignalDispatch($this->signal(), static fn() => $work);
        $dispatch->runPortion(2);
        $dispatch->cancel();
        $dispatch->runPortion(64);
        self::assertSame(2, $sent);
        self::assertFalse($dispatch->hasWork());
        self::assertSame(SignalDispatchPhase::Canceled, $dispatch->phase);
    }

    public function testCancellationFromTheCurrentEffectCannotResumeTheNextEffect(): void
    {
        $dispatch = null;
        $sent = 0;
        $work = (static function () use (&$dispatch, &$sent): Generator {
            yield;
            $sent++;
            $dispatch->cancel();
            yield;
            $sent++;
        })();
        $dispatch = new SignalDispatch($this->signal(), static fn() => $work);
        $dispatch->runPortion(64);
        self::assertSame(1, $sent);
        self::assertSame(SignalDispatchPhase::Canceled, $dispatch->phase);
        self::assertFalse($dispatch->hasWork());
    }

    /** @return iterable<string, array{SignalDispatchPhase}> Every settlement path */
    public static function settlements(): iterable
    {
        yield 'completed' => [SignalDispatchPhase::Complete];
        yield 'canceled' => [SignalDispatchPhase::Canceled];
        yield 'failed' => [SignalDispatchPhase::Failed];
    }

    /** @param SignalDispatchPhase $settlement Expected final lifetime */
    #[DataProvider('settlements')]
    public function testActualPendingReceiptLivesOnlyUntilTheCursorSettles(SignalDispatchPhase $settlement): void
    {
        $memory = new UploadMemoryMaster('dispatch-test');
        $pool = new ReflectionProperty(UploadMemoryMaster::class, 'pool')->getValue($memory);
        self::assertInstanceOf(UploadMemoryPool::class, $pool);
        $pool->setLimitBytes(3);
        $pool->request('socket', 'file', 3);
        $reservation = $pool->reserveNext();
        self::assertNotNull($reservation);
        $pool->publish($reservation->id);
        $receipt = $pool->acceptPending($reservation->requestId, 0, 3);
        self::assertNotNull($receipt);
        $payload = new WebSocketFrameBinarySignalDTO('socket', 'abc');
        $memory->onUploadFrameQueued($payload, $receipt);
        $reference = WeakReference::create($payload);
        $signal = new SignalDTO(new SignalSource(SignalSource::WEBSOCKET), new SignalType(SignalTypeConstants::FRAME_BINARY),
            new SignalName('bytes'), $payload);
        $failure = new RuntimeException('terminal failed');
        $dispatch = new SignalDispatch($signal, fn() => $this->receiptWork($signal, $settlement, $failure),
            new ParkedAgentSignal($signal, 'uploads', 0.0, false));
        unset($payload, $signal);

        $dispatch->runPortion(1);
        $memory->afterDispatch();
        self::assertNotNull($reference->get());
        self::assertSame(3, $pool->getUsedBytes());
        if ($settlement === SignalDispatchPhase::Canceled) {
            $dispatch->cancel();
        } else {
            try {
                $dispatch->runPortion(64);
                self::assertSame(SignalDispatchPhase::Complete, $settlement);
            } catch (RuntimeException $caught) {
                self::assertSame($failure, $caught);
                self::assertSame(SignalDispatchPhase::Failed, $settlement);
            }
        }
        self::assertSame($settlement, $dispatch->phase);
        self::assertNull($dispatch->release);
        self::assertNull($reference->get(), 'The completed carrier must not pin a raw payload until the next portion');
        $memory->afterDispatch();
        self::assertSame(0, $pool->getUsedBytes());
    }

    /** @param SignalDispatchPhase $settlement Original terminal path whose metadata must retire in portions */
    #[DataProvider('settlements')]
    public function testRetiredMetadataCannotRetainTheActualRawReceiptOrMutateALaterLifetime(SignalDispatchPhase $settlement): void
    {
        $pending = new SplQueue();
        $cleanup = new DeliveryCleanup(static function (Generator $retirement) use ($pending): void { $pending->enqueue($retirement); });
        $memory = new UploadMemoryMaster('metadata-retirement');
        $memory->setTestLimit(3);
        $dispatch = null;
        $raw = null;
        $failure = new RuntimeException('metadata producer failed');
        $memory->enqueueTest('local', 'probe', null, 3,
            function ($delivery, SignalDTO $signal) use (&$dispatch, &$raw, $cleanup, $settlement, $failure): void {
                $raw = WeakReference::create($signal->data);
                $dispatch = new SignalDispatch($signal, fn(): Generator => $this->receiptWork($signal, $settlement, $failure),
                    cleanup: $cleanup);
            });
        $destinations = [];
        for ($index = 0; $index < 8192; $index++) {
            $destination = new AgentDestination('agent', (string)$index);
            $destinations[] = WeakReference::create($destination);
            $dispatch->agentsDelivered[] = $destination;
            $dispatch->predecessorsChecked['agent:' . $index] = true;
        }
        unset($destination);
        $dispatch->runPortion(1);
        if ($settlement === SignalDispatchPhase::Canceled) {
            $dispatch->cancel();
        } else {
            try {
                $dispatch->runPortion(64);
                self::assertSame(SignalDispatchPhase::Complete, $settlement);
            } catch (RuntimeException $caught) {
                self::assertSame($failure, $caught);
                self::assertSame(SignalDispatchPhase::Failed, $settlement);
            }
        }
        self::assertSame($settlement, $dispatch->phase);
        self::assertFalse($dispatch->hasWork());
        self::assertSame([], $dispatch->agentsDelivered);
        self::assertSame([], $dispatch->predecessorsChecked);
        self::assertCount(2, $pending, 'Both original maps move without visiting their growing contents');
        self::assertNotNull($destinations[8191]->get());
        self::assertNull($raw->get(), 'Retired destination metadata cannot pin an actual raw carrier');
        $memory->afterDispatch();
        self::assertSame(0, $memory->inspect()['usedBytes']);
        $later = new AgentDestination('agent', '8191');
        $dispatch->agentsDelivered[] = $later;
        $dispatch->predecessorsChecked['agent:8191'] = true;
        $retirement = $pending->dequeue();
        $retirement->rewind();
        self::assertNotNull($destinations[8191]->get(), 'Admission has no cleanup effect');
        $retirement->next();
        self::assertNull($destinations[8191]->get());
        self::assertNotNull($destinations[8190]->get(), 'One step releases only one original destination');
        $steps = 1;
        while ($retirement->valid()) {
            $retirement->next();
            $steps++;
        }
        self::assertGreaterThanOrEqual(8192, $steps);
        foreach ($destinations as $reference) {
            self::assertNull($reference->get());
        }
        foreach ($pending->dequeue() as $_) {
        }
        self::assertSame([$later], $dispatch->agentsDelivered);
        self::assertSame(['agent:8191' => true], $dispatch->predecessorsChecked);
    }

    /** @return iterable<string, array{string}> Cancellation, admission failure and an existing primary dispatch failure */
    public static function disposalFailures(): iterable
    {
        yield 'cancel' => ['cancel'];
        yield 'fail' => ['fail'];
        yield 'primary failure' => ['primary'];
    }

    /** @param string $ending Settlement whose independent generator finalizers throw */
    #[DataProvider('disposalFailures')]
    public function testThrowingFinalizersReleaseActualRawReceiptsAndPreserveTheFirstFailure(string $ending): void
    {
        $memory = new UploadMemoryMaster('throwing-disposal');
        $memory->setTestLimit(3);
        $primary = new RuntimeException('primary delivery failure');
        $workFailure = new RuntimeException('work disposal failure');
        $destinationFailure = new RuntimeException('destination disposal failure');
        $physicalFailure = new RuntimeException('physical disposal failure');
        $dispatch = null;
        $reference = null;
        $memory->enqueueTest('local', 'probe', null, 3,
            function ($delivery, SignalDTO $signal) use (&$dispatch, &$reference, $ending, $primary,
                $workFailure, $destinationFailure, $physicalFailure): void {
                $reference = WeakReference::create($signal->data);
                $dispatch = new SignalDispatch($signal,
                    fn(): Generator => $this->throwingWork($signal, $ending === 'primary' ? $primary : null,
                        $ending === 'primary' ? null : $workFailure),
                    new ParkedAgentSignal($signal, 'probe', 0.0, false));
                $dispatch->destinations = $this->throwingWork($signal, null, $destinationFailure);
                $dispatch->physical = $this->throwingWork($signal, null, $physicalFailure);
                $dispatch->destinations->valid();
                $dispatch->physical->valid();
            },
        );
        $dispatch->frame = DeliveryFrame::fromJson('{"type":"event","data":{}}');
        $frame = WeakReference::create($dispatch->frame);
        $dispatch->runPortion(1);
        $caught = null;
        try {
            match ($ending) {
                'cancel' => $dispatch->cancel(),
                'fail' => $dispatch->fail(),
                'primary' => $dispatch->runPortion(64),
            };
        } catch (Throwable $failure) {
            $caught = $failure;
        }
        try {
            self::assertSame($ending === 'primary' ? $primary : $workFailure, $caught);
            self::assertFalse($dispatch->hasWork());
            self::assertNull($dispatch->getSignal());
            self::assertNull($dispatch->release);
            self::assertNull($dispatch->destinations);
            self::assertNull($dispatch->physical);
            self::assertNull($frame->get());
            self::assertNull($reference->get(), 'A throwing finalizer cannot pin the actual raw object until GC');
            $memory->afterDispatch();
            self::assertSame(0, $memory->inspect()['usedBytes']);
        } finally {
            // A red assertion must not turn into an unrelated destructor error during PHPUnit cleanup.
            foreach (['destinations', 'physical'] as $field) {
                try {
                    $dispatch->$field = null;
                } catch (Throwable) {
                }
            }
        }
    }

    /**
     * @param SignalDTO $signal Original raw carrier held until this continuation closes
     * @param ?Throwable $primary Delivery failure after the second portion
     * @param ?Throwable $onDispose Independent finalizer failure
     * @return Generator<int, void> Suspended continuation with an optional throwing finalizer
     */
    private function throwingWork(SignalDTO $signal, ?Throwable $primary, ?Throwable $onDispose): Generator
    {
        try {
            yield;
            yield;
            if ($primary !== null) {
                throw $primary;
            }
        } finally {
            if ($onDispose !== null) {
                throw $onDispose;
            }
        }
    }

    /**
     * @param SignalDTO $signal Payload kept by the real generator frame
     * @param SignalDispatchPhase $settlement Terminal outcome to exercise
     * @param RuntimeException $failure Exact failure that must reach the existing manager guard
     * @return Generator<int, void> Two steps, with an optional terminal failure
     */
    private function receiptWork(SignalDTO $signal, SignalDispatchPhase $settlement, RuntimeException $failure): Generator
    {
        yield;
        self::assertInstanceOf(WebSocketFrameBinarySignalDTO::class, $signal->data);
        yield;
        if ($settlement === SignalDispatchPhase::Failed) {
            throw $failure;
        }
    }

    /** @return SignalDTO Ordinary process-local envelope */
    private function signal(): SignalDTO
    {
        return new SignalDTO(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName('marker'), new SignalData());
    }
}
