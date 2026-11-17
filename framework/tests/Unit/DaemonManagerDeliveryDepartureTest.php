<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Daemon\SignalDispatcher;
use Hilos\Tests\Fixtures\SignalDispatcherFixture;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use Closure;
use Generator;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
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
        $owner = SignalDispatcherFixture::owner($manager);
        $state = $owner->signalDispatchState();
        $holds = new ReflectionMethod(DaemonManager::class, 'agentSignalHolds')->invoke($manager);
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
                new ReflectionMethod(DaemonManager::class, 'queueSignalEffectInDispatch')->invoke(
                    $manager, static function (SignalDispatch $dispatch) use ($signal): Generator {
                        yield;
                        for ($step = 0; $step < 66; $step++) {
                            self::assertNotNull($signal->data);
                            yield;
                        }
                }, null);
            });
        $original = $state->effects[key($state->effects)];
        $original->stopPrefixId = 101;
        $hold = $holds->add(new ParkedAgentSignal(new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET), new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName('page'), new WebSocketPageSubscribeSignalDTO('nested-connection', 'page'),
        ), 'agent', 0.0, false), $original->stopPrefixId);
        $holds->release($hold->id);
        $holds->begin($hold->id, $original);
        unset($hold);
        for ($index = 0; $index < 512; $index++) {
            $original->agentsDelivered[] = new AgentDestination('agent', (string)$index);
            $original->predecessorsChecked['agent:' . $index] = true;
        }
        if ($included) {
            $state->browsers = $prefixFactory->invoke($manager);
        }
        $originalCohort = $original->effectCohortId;
        $caller = new SignalDispatch(null, static function (SignalDispatch $dispatch) use ($manager): Generator {
            yield;
            new ReflectionMethod(DaemonManager::class, 'cancelPendingPageDeliveries')->invoke(
                $manager, $dispatch, 'nested-connection', null, false,
            );
        });
        $caller->stopPrefixId = 202;
        $caller->browserPrefixId = $included ? null : $state->browsers->id;
        try {
            $owner->runSignalPortion($caller, 1);
            $cleanup = $state->effects[array_key_last($state->effects)];
            self::assertSame($caller->effectCohortId, $cleanup->effectCohortId);
            self::assertNotSame($originalCohort, $cleanup->effectCohortId);
            self::assertSame($caller->browserPrefixId, $cleanup->browserPrefixId);
            for ($step = 0; $step < 16 && $original->hasWork(); $step++) {
                $owner->runSignalPortion($cleanup, 1);
            }
            self::assertSame(SignalDispatchPhase::Canceled, $original->phase);
            self::assertNull($raw->get());
            $memory->afterDispatch();
            self::assertSame(0, $memory->inspect()['usedBytes']);
            self::assertSame($state->browsers->id, $cleanup->browserPrefixId,
                'The connection callback transfers captured hold debt before settlement releases its raw owner');
            self::assertCount(4, $state->effects);
            foreach ($state->effects as $effect) {
                if ($effect !== $original && $effect !== $cleanup) {
                    self::assertSame($originalCohort, $effect->effectCohortId);
                    self::assertSame(101, $effect->stopPrefixId);
                    self::assertSame($included ? $state->browsers->id : null, $effect->browserPrefixId,
                        'Metadata debt belongs to the canceled source, even when the caller has another dispatch context');
                }
            }
            unset($effect);
            for ($index = 0; $index < 512; $index++) {
                $cleanup->agentsDelivered[] = new AgentDestination('nested-agent', (string)$index);
                $cleanup->predecessorsChecked['nested-agent:' . $index] = true;
            }
            $cleanupTail = $state->effectSequence;
            $cleanupCohort = $cleanup->effectCohortId;
            $owner->runSignalPortion($cleanup, 16);
            self::assertFalse($cleanup->hasWork());
            self::assertCount(6, $state->effects);
            foreach ($state->effects as $effect) {
                if ($effect->effectId > $cleanupTail) {
                    self::assertSame($cleanupCohort, $effect->effectCohortId);
                    self::assertSame(202, $effect->stopPrefixId);
                    self::assertSame($state->browsers->id, $effect->browserPrefixId,
                        'Nested retirement inherits the cleanup lifetime after its held-prefix transfer');
                }
            }
            unset($effect);
            $owner->advanceInitialSignalEffect($original);
            $weakOriginal = WeakReference::create($original);
            unset($original, $caller, $cleanup);
            self::assertNull($weakOriginal->get(), 'Queued cleanup keeps scalar prefix debt, never the canceled lifetime');
            for ($step = 0; $step < 4; $step++) {
                $state->browsers->advanceValidation($holds);
            }
            self::assertFalse($state->browsers->isReadyForAccounting());
            $drain = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
            for ($portion = 0; $portion < 300; $portion++) {
                $drain->invoke($manager);
            }
            self::assertSame([], $state->effects);
            self::assertTrue($state->browsers->complete, 'The captured prefix ends only after its nested cleanup');
        } finally {
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
        $state = SignalDispatcherFixture::owner($manager)->signalDispatchState();
        $memory = new UploadMemoryMaster('native-metadata-retirement');
        $memory->setTestLimit(1);
        new ReflectionProperty(DaemonManager::class, 'uploadMemory')->setValue($manager, $memory);
        $raw = null;
        $memory->enqueueTest('local', 'probe', null, 1,
            static function ($delivery, SignalDTO $signal) use ($manager, &$raw): void {
                $raw = WeakReference::create($signal->data);
                SignalDispatcherFixture::queue($manager, static function () use ($signal): Generator {
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
        $state = SignalDispatcherFixture::owner($manager)->signalDispatchState();
        $holds = new ReflectionMethod(DaemonManager::class, 'agentSignalHolds')->invoke($manager);
        $memory = new UploadMemoryMaster('departure');
        $memory->setTestLimit(10);
        $references = [];
        $lifetimes = [];
        $slots = ['active', 'released', 'peer', 'effect', 'identity', 'parked', 'held', 'executing', 'secondPeer', 'retired'];
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
                            new ReflectionProperty(SignalDispatcher::class, 'parkedSignals')->setValue(
                                SignalDispatcherFixture::owner($manager), [1 => new ParkedSignal($signal, 0.0)],
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
                        } elseif ($slot === 'secondPeer') {
                            $state->peers[2] = $dispatch;
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
            self::assertNull(SignalDispatcherFixture::owner($manager)->retainedState());
            self::assertSame([], $state->peers);
            self::assertSame([],
                new ReflectionProperty(SignalDispatcher::class, 'parkedSignals')->getValue(SignalDispatcherFixture::owner($manager)));
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
        $state = SignalDispatcherFixture::owner($manager)->signalDispatchState();
        $holds = new ReflectionMethod(DaemonManager::class, 'agentSignalHolds')->invoke($manager);
        $boundary = new SignalDispatchPrefix(1, $state->intake, [], 0, 0, 0);
        $state->browsers = $boundary;
        $memory = new UploadMemoryMaster('throwing-departure');
        $memory->setTestLimit(5);
        $references = [];
        $lifetimes = [];
        $first = new RuntimeException('first disposal failure');
        foreach (['secondPeer', 'active', 'peer', 'effect', 'held'] as $slot) {
            $failure = $slot === 'active' ? $first : new RuntimeException($slot . ' disposal failure');
            $memory->enqueueTest('local', 'probe', null, 1,
                function ($delivery, SignalDTO $signal) use ($slot, $manager, $state, $holds, $failure, &$references, &$lifetimes): void {
                    $references[] = WeakReference::create($signal->data);
                    $dispatch = new SignalDispatch($signal, fn(): Generator => $this->throwingDepartureWork($signal, $failure));
                    $dispatch->runPortion(1);
                    $lifetimes[] = $dispatch;
                    if ($slot === 'secondPeer') {
                        $state->peers[2] = $dispatch;
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
            self::assertNull(SignalDispatcherFixture::owner($manager)->retainedState());
            self::assertSame([], $state->peers);
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
        $state = SignalDispatcherFixture::owner($manager)->signalDispatchState();
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
        $state = SignalDispatcherFixture::owner($manager)->signalDispatchState();
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
                new ReflectionMethod(DaemonManager::class, 'signalDispatcher')->invoke($manager)->runSignalPortion($dispatch, 64);
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
        // The scenario starts idle; previous tests may have left independent RT retirement.
        while (RtRowArena::process()->hasPendingRetirement()) {
            RtRowArena::process()->advanceRetirement(new RtWorkBudget());
        }
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
            self::assertNull(SignalDispatcherFixture::owner($manager)->retainedState());
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
