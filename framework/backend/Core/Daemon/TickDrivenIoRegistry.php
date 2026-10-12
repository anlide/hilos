<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use WeakMap;

/**
 * Every tick-driven operation alive in this process, held weakly.
 *
 * An operation registers itself once, when it is built; a destroyed one leaves on its own, so
 * no owner has to remember to sign it out. The worker asks {@see anyInFlight()} before it
 * waits: the answer decides between its short tick and a wait on the daemon socket.
 */
final class TickDrivenIoRegistry
{
    /** @var ?WeakMap<TickDrivenIo, true> */
    private static ?WeakMap $operations = null;

    /**
     * @param TickDrivenIo $operation Operation built in this process
     */
    public static function track(TickDrivenIo $operation): void
    {
        self::$operations ??= new WeakMap();
        self::$operations[$operation] = true;
    }

    /** @return bool True while at least one tracked operation still needs ticks */
    public static function anyInFlight(): bool
    {
        foreach (self::$operations ?? [] as $operation => $tracked) {
            if ($operation->isInFlight()) {
                return true;
            }
        }

        return false;
    }
}
