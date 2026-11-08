<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Generator;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\CommandSignalCancellation;
use Hilos\Core\Daemon\DaemonDeparture;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\ParkedAgentSignal;
use Hilos\Core\Daemon\ParkedSignal;
use Hilos\Core\Daemon\SignalDispatch;
use Hilos\Core\Daemon\SignalDispatchIntake;
use Hilos\Core\Daemon\SignalDispatchPhase;
use Hilos\Core\Daemon\SignalDispatchPrefix;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Environment\EnvAccessor;
use Hilos\Files\Upload\UploadMemoryMaster;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use WeakReference;

/** Forced departure releases actual pending payloads even while the manager remains referenced. */
final class DaemonManagerDeliveryDepartureTest extends TestCase
{
    /** @return iterable<string, array{bool}> A canceled prefix member and an unrelated later lifetime canceled inside prefix work */
    public static function metadataCancellationOrigins(): iterable
    {
        yield 'captured source outside dispatch' => [true];
        yield 'later source inside captured dispatch' => [false];
    }

    /** @param bool $included Whether the original canceled source belongs to the finite browser prefix */
    #[DataProvider('metadataCancellationOrigins')]
    public function testCanceledMetadataInheritsItsOriginalPrefixWithoutBorrowingTheCallersContext(bool $included): void
    {
        $previousEnv = Hilos::$env;
        $previousRouter = Hilos::$sr;
        Hilos::$env = new EnvAccessor();
        $manager = new DeliveryDepartureTestManager();
        $loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($manager, $loop);
        new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($manager);
        $state = new ReflectionMethod(DaemonManager::class, 'signalDispatchState')->invoke($manager);
        $prefixFactory = new ReflectionMethod(DaemonManager::class, 'newSignalDispatchPrefix');
        if (!$included) {
            $state->browsers = $prefixFactory->invoke($manager);
        }
        $memory = new UploadMemoryMaster('canceled-metadata-origin');
        $memory->setTestLimit(1);
        $raw = null;
        $memory->enqueueTest('local', 'probe', null, 1,
            static function ($delivery, SignalDTO $signal) use ($manager, &$raw): void {
                $raw = WeakReference::create($signal->data);
                new ReflectionMethod(DaemonManager::class, 'queueSignalEffect')->invoke($manager, static function () use ($signal): Generator {
                    yield;
                    for ($step = 0; $step < 66; $step++) {
                        self::assertNotNull($signal->data);
                        yield;
                    }
                });
            });
        $original = $state->effects[key($state->effects)];
        for ($index = 0; $index < 512; $index++) {
            $original->agentsDelivered[] = new AgentDestination('agent', (string)$index);
            $original->predecessorsChecked['agent:' . $index] = true;
        }
        if ($included) {
            $state->browsers = $prefixFactory->invoke($manager);
        } else {
            $caller = new SignalDispatch(null, static function (): Generator { yield; });
            $caller->browserPrefixId = $state->browsers->id;
            new ReflectionProperty(DaemonManager::class, 'currentSignalDispatch')->setValue($manager, $caller);
        }
        try {
            $original->cancel();
            self::assertSame(SignalDispatchPhase::Canceled, $original->phase);
            self::assertNull($raw->get());
            $memory->afterDispatch();
            self::assertSame(0, $memory->inspect()['usedBytes']);
            self::assertCount(3, $state->effects);
            foreach ($state->effects as $effect) {
                if ($effect !== $original) {
                    self::assertSame($included ? $state->browsers->id : null, $effect->browserPrefixId,
                        'Metadata debt belongs to the canceled source, even when the caller has another dispatch context');
                }
            }
            unset($state->effects[$original->effectId]);
            $weakOriginal = WeakReference::create($original);
            unset($original);
            self::assertNull($weakOriginal->get(), 'Queued cleanup keeps scalar prefix debt, never the canceled lifetime');
        } finally {
            new ReflectionProperty(DaemonManager::class, 'currentSignalDispatch')->setValue($manager, null);
            new ReflectionMethod(DaemonManager::class, 'discardSignalDispatch')->invoke($manager);
            $loop->close();
            Hilos::$env = $previousEnv;
            Hilos::$sr = $previousRouter;
        }
    }

    public function testNativeMetadataRetirementKeepsItsFinitePrefixAfterTheRawReceiptHasSettled(): void
    {
        $previousEnv = Hilos::$env;
        $previousRouter = Hilos::$sr;
        Hilos::$env = new EnvAccessor();
        $manager = new DeliveryDepartureTestManager();
        $loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($manager, $loop);
        new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($manager);
        $state = new ReflectionMethod(DaemonManager::class, 'signalDispatchState')->invoke($manager);
        $memory = new UploadMemoryMaster('native-metadata-retirement');
        $memory->setTestLimit(1);
        new ReflectionProperty(DaemonManager::class, 'uploadMemory')->setValue($manager, $memory);
        $raw = null;
        $memory->enqueueTest('local', 'probe', null, 1,
            static function ($delivery, SignalDTO $signal) use ($manager, &$raw): void {
                $raw = WeakReference::create($signal->data);
                new ReflectionMethod(DaemonManager::class, 'queueSignalEffect')->invoke($manager, static function () use ($signal): Generator {
                    yield;
                    for ($step = 0; $step < 66; $step++) {
                        self::assertNotNull($signal->data);
                        yield;
                    }
                });
            });
        $original = $state->effects[key($state->effects)];
        $destinations = [];
        for ($index = 0; $index < 8192; $index++) {
            $destination = new AgentDestination('agent', (string)$index);
            $destinations[] = WeakReference::create($destination);
            $original->agentsDelivered[] = $destination;
            $original->predecessorsChecked['agent:' . $index] = true;
        }
        unset($destination);
        new ReflectionProperty(DaemonManager::class, 'shutdownStartTime')->setValue($manager, microtime(true));
        $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
        $ready = 0;
        $due = $loop->createReadyWork(static function () use (&$ready): void { $ready++; });
        try {
            $dispatch->invoke($manager);
            self::assertNull($state->active);
            self::assertSame(SignalDispatchPhase::Complete, $original->phase);
            self::assertNull($raw->get(), 'Actual raw ownership ends before the metadata cleanup portions');
            self::assertSame(0, $memory->inspect()['usedBytes']);
            self::assertNotNull($destinations[0]->get(), 'The first portion cannot destroy the whole destination map');
            self::assertNotNull($state->browsers);
            self::assertFalse($state->browsers->complete);
            $holds = new ReflectionMethod(DaemonManager::class, 'agentSignalHolds')->invoke($manager);
            for ($step = 0; $step < 4; $step++) {
                $state->browsers->advanceValidation($holds);
            }
            self::assertFalse($state->browsers->isReadyForAccounting(), 'Both cleanup effects owe the prefix from admission');
            for ($portion = 0; $portion < 300; $portion++) {
                $due->markReady();
                $loop->tick();
                $dispatch->invoke($manager);
            }
            self::assertSame(300, $ready);
            self::assertTrue($state->browsers->complete);
            self::assertSame([], $state->effects);
            foreach ($destinations as $reference) {
                self::assertNull($reference->get());
            }
            self::assertFalse($loop->hasReadyWork());
        } finally {
            $due->close();
            new ReflectionMethod(DaemonManager::class, 'discardSignalDispatch')->invoke($manager);
            $loop->close();
            Hilos::$env = $previousEnv;
            Hilos::$sr = $previousRouter;
        }
    }

    public function testFinalDepartureDiscardsEveryRetainedLifetimeAndCallerDebtWithoutDrainingAnotherOwner(): void
    {
        $previousEnv = Hilos::$env;
        $previousRouter = Hilos::$sr;
        Hilos::$env = new EnvAccessor();
        $manager = new DeliveryDepartureTestManager();
        $state = new ReflectionMethod(DaemonManager::class, 'signalDispatchState')->invoke($manager);
        $holds = new ReflectionMethod(DaemonManager::class, 'agentSignalHolds')->invoke($manager);
        $memory = new UploadMemoryMaster('departure');
        $memory->setTestLimit(10);
        $references = [];
        $lifetimes = [];
        $slots = ['active', 'released', 'peer', 'effect', 'identity', 'parked', 'held', 'executing', 'scratch', 'retired'];
        $boundary = new SignalDispatchPrefix(1, $state->intake, [], 0, 0, 0);
        $state->browsers = $boundary;
        try {
            foreach ($slots as $slot) {
                $memory->enqueueTest('local', 'probe', null, 1,
                    function ($delivery, SignalDTO $signal) use ($slot, $manager, $state, $holds, &$references, &$lifetimes): void {
                        $references[] = WeakReference::create($signal->data);
                        if ($slot === 'identity') {
                            $state->identities[1] = $signal;
                            return;
                        }
                        if ($slot === 'parked') {
                            new ReflectionProperty(DaemonManager::class, 'parkedSignals')->setValue(
                                $manager, [1 => new ParkedSignal($signal, 0.0)],
                            );
                            return;
                        }
                        if ($slot === 'retired') {
                            $router = new SignalRouter();
                            $router->queueSignal($signal->signalSource, $signal->signalType, $signal->signalName, $signal->data);
                            $state->retired[2] = new SignalDispatchIntake(2, $router);
                            $state->retired[2]->retire();
                            $state->retiredRemaining = 1;
                            return;
                        }
                        if ($slot === 'held') {
                            $holds->add(new ParkedAgentSignal($signal, 'probe', 0.0, false));
                            return;
                        }
                        $dispatch = new SignalDispatch($signal,
                            fn(SignalDispatch $dispatch): Generator => $this->retainedWork($signal, $dispatch));
                        $lifetimes[] = $dispatch;
                        if ($slot === 'executing') {
                            $hold = $holds->add(new ParkedAgentSignal($signal, 'probe', 0.0, false));
                            $holds->release($hold->id);
                            $holds->begin($hold->id, $dispatch);
                            $state->suspendedReleases[] = $hold->id;
                        } elseif ($slot === 'scratch') {
                            new ReflectionProperty(DaemonManager::class, 'currentSignalDispatch')->setValue($manager, $dispatch);
                        } elseif ($slot === 'peer') {
                            $state->peers[1] = $dispatch;
                        } elseif ($slot === 'effect') {
                            $state->effects[1] = $dispatch;
                        } else {
                            $state->$slot = $dispatch;
                        }
                    },
                );
            }
            self::assertCount(10, $references);
            self::assertSame(10, $memory->inspect()['usedBytes']);
            $cancellation = new CommandSignalCancellation(1, 'abandoned', 10);
            $state->commandCancellations[1] = $cancellation;
            $state->abandonedCommands['abandoned'] = $cancellation;
            $router = Hilos::$sr;
            $router->queueSignal(new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::SYSTEM),
                new SignalName('independent'), new SignalData());
            $manager->leaveWithoutAnotherIteration();
            self::assertSame(DaemonDeparture::Stopped, $manager->run());
            foreach ($lifetimes as $dispatch) {
                self::assertFalse($dispatch->hasWork());
                self::assertSame(SignalDispatchPhase::Canceled, $dispatch->phase);
                self::assertNull($dispatch->getSignal());
            }
            foreach ($references as $reference) {
                self::assertNull($reference->get(), 'Discard must release the actual raw object without a GC pass');
            }
            $memory->afterDispatch();
            self::assertSame(0, $memory->inspect()['usedBytes']);
            self::assertSame(0, $holds->getParkedCount());
            self::assertSame([], $holds->pendingAgentIds());
            self::assertFalse($holds->hasReleased());
            self::assertSame([], iterator_to_array($holds->candidates()));
            self::assertSame([], $state->identities);
            self::assertSame([], $state->effects);
            self::assertSame([], $state->peers);
            self::assertSame([], $state->retired);
            self::assertSame(0, $state->retiredRemaining);
            self::assertSame([], $state->suspendedReleases);
            self::assertSame([], $state->commandCancellations);
            self::assertSame([], $state->abandonedCommands);
            self::assertTrue($boundary->failed);
            self::assertFalse($boundary->complete, 'Final discard cannot acknowledge an unfinished boundary');
            self::assertNull(new ReflectionProperty(DaemonManager::class, 'signalDispatchState')->getValue($manager));
            self::assertNull(new ReflectionProperty(DaemonManager::class, 'currentSignalDispatch')->getValue($manager));
            self::assertSame([], new ReflectionProperty(DaemonManager::class, 'parkedSignals')->getValue($manager));
            self::assertSame($router, Hilos::$sr);
            self::assertSame('independent', $router->getNextQueuedSignal()?->signalName->getName());
            self::assertFalse($router->hasQueuedSignals());
        } finally {
            Hilos::$env = $previousEnv;
            Hilos::$sr = $previousRouter;
        }
    }

    public function testThrowingFinalDiscardReleasesIndependentRawOwnersAndKeepsReplacementWakeAndFifo(): void
    {
        $previousEnv = Hilos::$env;
        $previousRouter = Hilos::$sr;
        Hilos::$env = new EnvAccessor();
        $manager = new DeliveryDepartureTestManager();
        $router = Hilos::$sr;
        $state = new ReflectionMethod(DaemonManager::class, 'signalDispatchState')->invoke($manager);
        $holds = new ReflectionMethod(DaemonManager::class, 'agentSignalHolds')->invoke($manager);
        $boundary = new SignalDispatchPrefix(1, $state->intake, [], 0, 0, 0);
        $state->browsers = $boundary;
        $memory = new UploadMemoryMaster('throwing-departure');
        $memory->setTestLimit(5);
        $references = [];
        $lifetimes = [];
        $first = new RuntimeException('first disposal failure');
        foreach (['scratch', 'active', 'peer', 'effect', 'held'] as $slot) {
            $failure = $slot === 'scratch' ? $first : new RuntimeException($slot . ' disposal failure');
            $memory->enqueueTest('local', 'probe', null, 1,
                function ($delivery, SignalDTO $signal) use ($slot, $manager, $state, $holds, $failure, &$references, &$lifetimes): void {
                    $references[] = WeakReference::create($signal->data);
                    $dispatch = new SignalDispatch($signal, fn(): Generator => $this->throwingDepartureWork($signal, $failure));
                    $dispatch->runPortion(1);
                    $lifetimes[] = $dispatch;
                    if ($slot === 'scratch') {
                        new ReflectionProperty(DaemonManager::class, 'currentSignalDispatch')->setValue($manager, $dispatch);
                    } elseif ($slot === 'active') {
                        $state->active = $dispatch;
                    } elseif ($slot === 'peer') {
                        $state->peers[1] = $dispatch;
                    } elseif ($slot === 'effect') {
                        $state->effects[1] = $dispatch;
                    } else {
                        $hold = $holds->add(new ParkedAgentSignal($signal, 'probe', 0.0, false));
                        $holds->release($hold->id);
                        $holds->begin($hold->id, $dispatch);
                    }
                },
            );
        }
        $oldLoop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($manager, $oldLoop);
        new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($manager);
        $replacement = new DeliveryDepartureTestManager();
        Hilos::$sr = $router;
        $loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($replacement, $loop);
        new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($replacement);
        $router->queueSignal(new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::SYSTEM),
            new SignalName('original-fifo'), new SignalData());
        $caught = null;
        try {
            try {
                new ReflectionMethod(DaemonManager::class, 'discardSignalDispatch')->invoke($manager);
            } catch (Throwable $failure) {
                $caught = $failure;
            }
            self::assertSame($first, $caught);
            foreach ($lifetimes as $dispatch) {
                self::assertFalse($dispatch->hasWork());
                self::assertNull($dispatch->getSignal());
            }
            foreach ($references as $reference) {
                self::assertNull($reference->get(), 'A failed finalizer cannot skip another retained raw owner');
            }
            $memory->afterDispatch();
            self::assertSame(0, $memory->inspect()['usedBytes']);
            self::assertSame(0, $holds->getParkedCount());
            self::assertFalse($holds->hasReleased());
            self::assertTrue($boundary->failed);
            self::assertFalse($boundary->complete);
            self::assertNull(new ReflectionProperty(DaemonManager::class, 'signalDispatchState')->getValue($manager));
            self::assertNull(new ReflectionProperty(DaemonManager::class, 'currentSignalDispatch')->getValue($manager));
            $router->queueSignal(new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::SYSTEM),
                new SignalName('replacement-fifo'), new SignalData());
            self::assertTrue($loop->hasReadyWork());
            self::assertSame('original-fifo', $router->getNextQueuedSignal()->signalName->getName());
            self::assertSame('replacement-fifo', $router->getNextQueuedSignal()->signalName->getName());
            self::assertFalse($router->hasQueuedSignals());
        } finally {
            foreach ($lifetimes as $dispatch) {
                try {
                    $dispatch->cancel();
                } catch (Throwable) {
                }
            }
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($replacement);
            $loop->close();
            $oldLoop->close();
            Hilos::$env = $previousEnv;
            Hilos::$sr = $previousRouter;
        }
    }

    public function testFinalRunReleasesNativeWorkAfterAThrowingRetainedFinalizer(): void
    {
        $previousEnv = Hilos::$env;
        $previousRouter = Hilos::$sr;
        Hilos::$env = new EnvAccessor();
        $manager = new DeliveryDepartureTestManager();
        $state = new ReflectionMethod(DaemonManager::class, 'signalDispatchState')->invoke($manager);
        $failure = new RuntimeException('run disposal failure');
        $signal = new SignalDTO(new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::SYSTEM),
            new SignalName('unfinished'), new SignalData());
        $state->active = new SignalDispatch($signal, fn(): Generator => $this->throwingDepartureWork($signal, $failure));
        $state->active->runPortion(1);
        $manager->afterBinding = static function () use ($manager): void {
            $loop = new ReflectionProperty(DaemonManager::class, 'eventLoop')->getValue($manager);
            $loop->createReadyWork(static function (): void {
                self::fail('Final native cleanup must not run business work');
            })->markReady();
        };
        try {
            $manager->leaveWithoutAnotherIteration();
            $caught = null;
            try {
                $manager->run();
            } catch (Throwable $problem) {
                $caught = $problem;
            }
            self::assertSame($failure, $caught);
            $loop = new ReflectionProperty(DaemonManager::class, 'eventLoop')->getValue($manager);
            self::assertFalse($loop->hasReadyWork());
            self::assertNull(new ReflectionProperty(DaemonManager::class, 'readinessWork')->getValue($manager));
        } finally {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->getValue($manager)->cleanup();
            Hilos::$env = $previousEnv;
            Hilos::$sr = $previousRouter;
        }
    }

    public function testPrimaryFailureSurvivesDisposalAndFailsOnlyItsOwningPrefixBeforeReceiptAccounting(): void
    {
        $previousEnv = Hilos::$env;
        $previousRouter = Hilos::$sr;
        Hilos::$env = new EnvAccessor();
        $manager = new DeliveryDepartureTestManager();
        $state = new ReflectionMethod(DaemonManager::class, 'signalDispatchState')->invoke($manager);
        $owned = new SignalDispatchPrefix(1, $state->intake, [], 0, 0, 0);
        $independent = new SignalDispatchPrefix(2, $state->intake, [], 0, 0, 0);
        $state->stop = $owned;
        $state->browsers = $independent;
        $memory = new UploadMemoryMaster('primary-disposal');
        $memory->setTestLimit(1);
        $primary = new RuntimeException('original dispatch failure');
        $secondary = new RuntimeException('independent disposal failure');
        $dispatch = null;
        $raw = null;
        $memory->enqueueTest('local', 'probe', null, 1,
            function ($delivery, SignalDTO $signal) use (&$dispatch, &$raw, $primary, $secondary): void {
                $raw = WeakReference::create($signal->data);
                $dispatch = new SignalDispatch($signal, static function () use ($signal, $primary): Generator {
                    yield;
                    yield;
                    throw $primary;
                });
                $dispatch->stopPrefixId = 1;
                $dispatch->physical = $this->throwingDepartureWork($signal, $secondary);
                $dispatch->physical->valid();
            },
        );
        try {
            $caught = null;
            try {
                new ReflectionMethod(DaemonManager::class, 'runSignalPortion')->invoke($manager, $dispatch, 64);
            } catch (Throwable $problem) {
                $caught = $problem;
            }
            self::assertSame($primary, $caught);
            self::assertTrue($owned->failed);
            self::assertFalse($owned->complete);
            self::assertFalse($independent->failed);
            self::assertNull($raw->get());
            $memory->afterDispatch();
            self::assertSame(0, $memory->inspect()['usedBytes']);
        } finally {
            try {
                $dispatch->physical = null;
            } catch (Throwable) {
            }
            Hilos::$env = $previousEnv;
            Hilos::$sr = $previousRouter;
        }
    }

    public function testFinalRunCleanupCannotDetachAManagerThatTookOverItsRouter(): void
    {
        $previousEnv = Hilos::$env;
        $previousRouter = Hilos::$sr;
        Hilos::$env = new EnvAccessor();
        $manager = new DeliveryDepartureTestManager();
        $router = Hilos::$sr;
        $replacement = new DeliveryDepartureTestManager();
        Hilos::$sr = $router;
        $replacementLoop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($replacement, $replacementLoop);
        $manager->afterBinding = static function () use ($replacement): void {
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($replacement);
        };
        try {
            $manager->leaveWithoutAnotherIteration();
            self::assertSame(DaemonDeparture::Stopped, $manager->run());
            self::assertFalse($replacementLoop->hasReadyWork());
            $router->queueSignal(new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::SYSTEM),
                new SignalName('replacement'), new SignalData());
            self::assertTrue($replacementLoop->hasReadyWork());
            $replacementLoop->tick();
            self::assertSame('replacement', $router->getNextQueuedSignal()?->signalName->getName());
            self::assertFalse($replacementLoop->hasReadyWork());
            $references = [];
            for ($index = 0; $index < 260; $index++) {
                $viewport = new TableViewportSubscription('table-' . $index);
                $references[] = WeakReference::create($viewport);
                $router->setTableViewport('replacement-socket', $viewport);
            }
            unset($viewport);
            $router->unsubscribeFromAll('replacement-socket');
            self::assertSame([], $router->getTableViewports('replacement-socket'));
            self::assertNotNull($references[0]->get(), 'Old departure cannot detach the replacement cleanup owner');
            self::assertTrue($replacementLoop->hasReadyWork());
            self::assertNull(new ReflectionProperty(DaemonManager::class, 'signalDispatchState')->getValue($manager));
            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
            for ($portion = 0; $portion < 16; $portion++) {
                $dispatch->invoke($replacement);
            }
            foreach ($references as $reference) {
                self::assertNull($reference->get());
            }
            self::assertFalse($replacementLoop->hasReadyWork());
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($replacement);
            $replacementLoop->close();
            Hilos::$env = $previousEnv;
            Hilos::$sr = $previousRouter;
        }
    }

    /**
     * @param SignalDTO $signal Original raw payload retained by the generator
     * @param Throwable $failure Actual disposal failure
     * @return Generator<int, void> Two retained steps that throw when canceled
     */
    private function throwingDepartureWork(SignalDTO $signal, Throwable $failure): Generator
    {
        try {
            yield;
            yield;
        } finally {
            throw $failure;
        }
    }

    /**
     * @param SignalDTO $signal Pending raw envelope
     * @param SignalDispatch $dispatch Exact cursor retained by its continuation, as in production
     * @return Generator<int, void> Unentered work that must never execute during departure
     */
    private function retainedWork(SignalDTO $signal, SignalDispatch $dispatch): Generator
    {
        yield;
        self::fail('Final discard must not execute abandoned delivery work');
    }
}

final class DeliveryDepartureTestManager extends DaemonManager
{
    /** @var ?Closure(): void Replacement installed after this run acquired its original binding */
    public ?Closure $afterBinding = null;

    /** Force the existing final run cleanup without changing any production clock. */
    public function leaveWithoutAnotherIteration(): void
    {
        $this->shouldExit = true;
        new ReflectionProperty(DaemonManager::class, 'shutdownStartTime')->setValue($this, microtime(true));
    }

    /** This fixture installs no process-global error handlers. */
    protected function setupErrorHandling(): void
    {
    }

    /** This fixture installs no process-global signal handlers. */
    protected function setupSignalHandlers(): void
    {
        ($this->afterBinding)?->__invoke();
        $this->afterBinding = null;
    }

    /** @return SignalRouter Local test FIFO */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Unstarted test roster */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DeliveryDepartureTestAgentManager();
    }
}

final class DeliveryDepartureTestAgentManager extends AgentManagerDaemon
{
    /**
     * @param string $agentType Unused type
     * @param ?string $agentIndex Unused index
     * @return AgentDaemonInterface No agent is created by this fixture
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('No agent starts in the departure fixture');
    }
}
