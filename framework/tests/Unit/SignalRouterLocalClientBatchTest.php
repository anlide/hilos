<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Generator;
use Hilos\Core\Daemon\BrowserDeliveryTarget;
use Hilos\Core\Daemon\SignalDispatchIntake;
use Hilos\Core\Router\LocalClientSignalBatch;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use PHPUnit\Framework\TestCase;

/** Local cohort construction and intake capture must not consume its physical candidates. */
final class SignalRouterLocalClientBatchTest extends TestCase
{
    public function testMasterIntakeKeepsOneFiniteEntryWithoutAdvancingItsCandidates(): void
    {
        $router = new SignalRouter();
        $visits = 0;
        $targets = static function () use (&$visits): Generator {
            for ($index = 0; $index < 260; $index++) {
                $visits++;
                yield null;
            }
        };
        $wakes = 0;
        $router->setQueueWake(static function () use (&$wakes): void { $wakes++; });
        $batch = new LocalClientSignalBatch(new SignalName('marker'), new SignalData(), $targets(),
            static fn(int $slot): ?BrowserDeliveryTarget => null);
        $router->queueLocalClientSignals($batch);
        $intake = new SignalDispatchIntake(1, $router);
        $intake->retire();
        $router->queueSignal(new SignalSource(SignalSource::DAEMON), new SignalType('marker'), new SignalName('later'), new SignalData());
        self::assertSame(2, $wakes);
        self::assertSame(1, $intake->getWatermark());
        self::assertTrue($intake->hasQueuedSignals());
        self::assertSame(0, $visits);
        self::assertSame($batch, $intake->consumeSignal());
        self::assertSame(0, $visits);
        self::assertNull($intake->consumeSignal());
        self::assertSame('later', $router->getNextQueuedSignal()->signalName->getName());
        self::assertNull($batch->consumeTarget());
        self::assertSame(1, $visits);
        self::assertFalse($batch->isComplete());
    }

    public function testConsumingCompatibilityFinishesFilteredSlotsBeforeTheNextEnvelope(): void
    {
        $router = new SignalRouter();
        $visits = 0;
        $targets = static function () use (&$visits): Generator {
            for ($index = 0; $index < 260; $index++) {
                $visits++;
                yield null;
            }
        };
        $batch = new LocalClientSignalBatch(new SignalName('marker'), new SignalData(), $targets(),
            static fn(int $slot): ?BrowserDeliveryTarget => null);
        $router->queueLocalClientSignals($batch);
        $router->queueSignal(new SignalSource(SignalSource::DAEMON), new SignalType('marker'), new SignalName('later'), new SignalData());
        self::assertSame('later', $router->getNextQueuedSignal()->signalName->getName());
        self::assertSame(260, $visits);
        self::assertTrue($batch->isComplete());
        self::assertFalse($router->hasQueuedSignals());
        self::assertNull($router->consumeDispatchEntry());
    }

    public function testPreparationStaysInTheOriginalFifoEntryAndDoesNotVisitTargetsEarly(): void
    {
        $router = new SignalRouter();
        $prepared = 0;
        $visits = 0;
        $preparation = static function () use (&$prepared): Generator {
            yield;
            for ($index = 0; $index < 260; $index++) {
                $prepared++;
                yield;
            }
        };
        $targets = static function () use (&$visits): Generator {
            $visits++;
            yield null;
        };
        $batch = new LocalClientSignalBatch(new SignalName('marker'), new SignalData(), $targets(),
            static fn(int $slot): ?BrowserDeliveryTarget => null, $preparation());
        $router->queueLocalClientSignals($batch);
        self::assertSame(1, $router->getQueuedSignalCount());
        self::assertSame($batch, $router->consumeDispatchEntry());
        for ($step = 0; $step < 260; $step++) {
            self::assertNull($batch->consumeTarget());
            self::assertSame($step + 1, $prepared);
            self::assertSame(0, $visits);
            self::assertFalse($batch->isComplete());
        }
        while (!$batch->isComplete()) {
            self::assertNull($batch->consumeTarget());
        }
        self::assertSame(260, $prepared);
        self::assertSame(1, $visits);
    }

    public function testConsumingCompatibilityCompletesPreparationBeforeSkippingTheFinishedBatch(): void
    {
        $router = new SignalRouter();
        $prepared = 0;
        $preparation = static function () use (&$prepared): Generator {
            yield;
            $prepared++;
            yield;
        };
        $targets = static function (): Generator {
            yield null;
        };
        $batch = new LocalClientSignalBatch(new SignalName('marker'), new SignalData(), $targets(),
            static fn(int $slot): ?BrowserDeliveryTarget => null, $preparation());
        $router->queueLocalClientSignals($batch);
        $router->queueSignal(new SignalSource(SignalSource::DAEMON), new SignalType('marker'), new SignalName('later'), new SignalData());
        self::assertSame('later', $router->getNextQueuedSignal()->signalName->getName());
        self::assertSame(1, $prepared);
        self::assertTrue($batch->isComplete());
    }
}
