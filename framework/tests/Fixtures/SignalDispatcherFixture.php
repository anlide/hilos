<?php

declare(strict_types=1);

namespace Hilos\Tests\Fixtures;

use Closure;
use Generator;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\SignalDispatch;
use Hilos\Core\Daemon\SignalDispatcher;
use Hilos\Core\Router\DTO\SignalDTO;
use ReflectionMethod;

/** Test access to the lazy collaborator without adding a production inspection door. */
final class SignalDispatcherFixture
{
    /**
     * @param DaemonManager $manager Original master, including constructor-less fixtures
     * @return SignalDispatcher Sole owner of retained work and native bindings
     */
    public static function owner(DaemonManager $manager): SignalDispatcher
    {
        return new ReflectionMethod(DaemonManager::class, 'signalDispatcher')->invoke($manager);
    }

    /**
     * @param DaemonManager $manager Original master
     * @param Closure(SignalDispatch): Generator<int, void> $work Work bound to its own created lifetime
     * @param ?SignalDispatch $origin Explicit parent; null admits an independent root
     * @return SignalDispatch Queued effect after its optional root portion
     */
    public static function queue(DaemonManager $manager, Closure $work, ?SignalDispatch $origin = null): SignalDispatch
    {
        $owner = self::owner($manager);
        $effect = $owner->queueSignalEffect($work, $origin);
        if ($origin === null || $origin->isDirect()) {
            $owner->advanceInitialSignalEffect($effect);
        }
        $owner->markReady();
        return $effect;
    }

    /**
     * @param ?SignalDTO $signal Independent envelope, absent for standalone cleanup
     * @return SignalDispatch Explicit context for a direct helper invocation
     */
    public static function direct(?SignalDTO $signal = null): SignalDispatch
    {
        return new SignalDispatch($signal, static fn(): Generator => (static function (): Generator { yield from []; })());
    }
}
