<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use PHPUnit\Framework\TestCase;

/** Local queue readiness never resolves routes or changes the worker's consuming FIFO. */
final class SignalRouterQueueWakeTest extends TestCase
{
    public function testAnUnboundWorkerRouterKeepsItsConsumingFifo(): void
    {
        $router = new SignalRouter();
        $this->assertFalse($router->hasQueuedSignals());
        $this->assertSame(0, $router->getQueuedSignalCount());

        $first = new SignalData(['position' => 1]);
        $second = new SignalData(['position' => 2]);
        $this->enqueue($router, 'first', $first);
        $this->enqueue($router, 'second', $second);

        for ($read = 0; $read < 3; $read++) {
            $this->assertTrue($router->hasQueuedSignals());
            $this->assertSame(2, $router->getQueuedSignalCount());
        }
        $this->assertSame($first, $router->getNextQueuedSignal()?->data);
        $this->assertSame(1, $router->getQueuedSignalCount());
        $this->assertSame($second, $router->getNextQueuedSignal()?->data);
        $this->assertFalse($router->hasQueuedSignals());
        $this->assertSame(0, $router->getQueuedSignalCount());
        $this->assertNull($router->getNextQueuedSignal());
    }

    public function testEnqueueCuesAfterTheWholeEnvelopeIsAvailable(): void
    {
        $router = new SignalRouter();
        $observedCounts = [];
        $router->setQueueWake(static function () use ($router, &$observedCounts): void {
            $observedCounts[] = $router->getQueuedSignalCount();
        });
        $this->assertSame([], $observedCounts, 'Binding an empty FIFO creates no work.');

        $payload = new SignalData(['body' => 'kept']);
        $this->enqueue($router, 'first', $payload);
        $this->enqueue($router, 'second', new SignalData([]));

        $this->assertSame([1, 2], $observedCounts);
        $signal = $router->getNextQueuedSignal();
        $this->assertSame($payload, $signal?->data);
        $this->assertSame('first', $signal?->signalName->getName());
        $this->assertSame(SignalSource::DAEMON, $signal?->signalSource->getSource());
        $this->assertSame(SignalTypeConstants::SYSTEM, $signal?->signalType->getType());
        $this->assertSame('second', $router->getNextQueuedSignal()?->signalName->getName());
        $this->assertSame([1, 2], $observedCounts, 'Consumption never requests another enqueue wake.');
    }

    public function testBindingAnnouncesInputQueuedBeforeTheLoopWasAttached(): void
    {
        $router = new SignalRouter();
        $payload = new SignalData(['body' => 'before binding']);
        $this->enqueue($router, 'before_binding', $payload);
        $wakeCount = 0;

        $router->setQueueWake(static function () use (&$wakeCount): void {
            $wakeCount++;
        });

        $this->assertSame(1, $wakeCount);
        $this->assertSame(1, $router->getQueuedSignalCount());
        $this->assertSame($payload, $router->getNextQueuedSignal()?->data);
        $this->assertSame(1, $wakeCount);
    }

    public function testDetachAndRebindPreserveInputAndReplaceTheOldCue(): void
    {
        $router = new SignalRouter();
        $oldWakes = 0;
        $newWakes = 0;
        $router->setQueueWake(static function () use (&$oldWakes): void {
            $oldWakes++;
        });
        $this->enqueue($router, 'old_binding', new SignalData([]));
        $router->setQueueWake(null);
        $this->enqueue($router, 'detached', new SignalData([]));

        $router->setQueueWake(static function () use (&$newWakes): void {
            $newWakes++;
        });
        $this->enqueue($router, 'new_binding', new SignalData([]));

        $this->assertSame(1, $oldWakes);
        $this->assertSame(2, $newWakes);
        $this->assertSame(3, $router->getQueuedSignalCount());
        $this->assertSame('old_binding', $router->getNextQueuedSignal()?->signalName->getName());
        $this->assertSame('detached', $router->getNextQueuedSignal()?->signalName->getName());
        $this->assertSame('new_binding', $router->getNextQueuedSignal()?->signalName->getName());
        $this->assertFalse($router->hasQueuedSignals());
    }

    public function testAStaleOwnerCannotRemoveTheReplacementQueueCue(): void
    {
        $router = new SignalRouter();
        $oldWakes = 0;
        $newWakes = 0;
        $old = static function () use (&$oldWakes): void {
            $oldWakes++;
        };
        $new = static function () use (&$newWakes): void {
            $newWakes++;
        };
        $router->setQueueWake($old);
        $this->enqueue($router, 'original', new SignalData());
        $router->setQueueWake($new);
        $router->removeQueueWake($old);
        $this->enqueue($router, 'replacement', new SignalData());
        self::assertSame(1, $oldWakes);
        self::assertSame(2, $newWakes);
        $router->removeQueueWake($new);
        $router->removeQueueWake($old);
        $this->enqueue($router, 'detached', new SignalData());
        self::assertSame(2, $newWakes);
        self::assertSame('original', $router->getNextQueuedSignal()?->signalName->getName());
        self::assertSame('replacement', $router->getNextQueuedSignal()?->signalName->getName());
        self::assertSame('detached', $router->getNextQueuedSignal()?->signalName->getName());
        self::assertFalse($router->hasQueuedSignals());
    }

    public function testAFinitePrefixReadDoesNotGrowWithNewEnqueues(): void
    {
        $router = new SignalRouter();
        $this->enqueue($router, 'first', new SignalData([]));
        $this->enqueue($router, 'second', new SignalData([]));
        $prefixCount = $router->getQueuedSignalCount();
        $this->enqueue($router, 'later', new SignalData([]));
        $prefixNames = [];

        for ($remaining = $prefixCount; $remaining > 0; $remaining--) {
            $prefixNames[] = $router->getNextQueuedSignal()?->signalName->getName();
        }

        $this->assertSame(['first', 'second'], $prefixNames);
        $this->assertSame(1, $router->getQueuedSignalCount());
        $this->assertSame('later', $router->getNextQueuedSignal()?->signalName->getName());
    }

    public function testNativeReadyWorkCoalescesCuesWithoutDispatchingInsideEnqueue(): void
    {
        $loop = new EventLoop();
        $router = new SignalRouter();
        $portions = 0;
        $work = $loop->createReadyWork(static function () use (&$portions): void {
            $portions++;
        });
        try {
            $router->setQueueWake($work->markReady(...));
            $this->enqueue($router, 'first', new SignalData([]));
            $this->enqueue($router, 'second', new SignalData([]));
            $this->assertSame(0, $portions);
            $this->assertTrue($loop->hasReadyWork());

            $loop->tick();
            $this->assertSame(1, $portions);
            $this->assertFalse($loop->hasReadyWork());
            $this->assertSame(2, $router->getQueuedSignalCount());

            $router->setQueueWake(null);
            $work->close();
            $this->enqueue($router, 'after_detach', new SignalData([]));
            $loop->tick();
            $this->assertSame(1, $portions);
            $this->assertFalse($loop->hasReadyWork());
            $this->assertSame(3, $router->getQueuedSignalCount());
        } finally {
            $router->setQueueWake(null);
            $loop->close();
        }
    }

    /**
     * @param SignalRouter $router Local FIFO to receive the envelope
     * @param string $name Signal name distinguishing its place in the FIFO
     * @param SignalData $payload Original payload whose lifetime belongs to the queue
     */
    private function enqueue(SignalRouter $router, string $name, SignalData $payload): void
    {
        $router->queueSignal(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::SYSTEM),
            new SignalName($name),
            $payload,
        );
    }
}
