<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\Delivery;

use Closure;
use Generator;
use Hilos\Core\Daemon\SignalDispatch;

/** Transfers process-private retired arrays to the master's existing guarded effect queue. */
final class DeliveryCleanup
{
    /** @param ?Closure(Generator<int, void>, ?SignalDispatch): void $defer Master owner, absent for standalone consuming clients */
    public function __construct(private ?Closure $defer = null)
    {
    }

    /** Final manager departure leaves escaped clients on the standalone consuming path. */
    public function detach(): void
    {
        $this->defer = null;
    }

    /**
     * Moves the array before scheduling; later same-address packets own a different array.
     * Each step releases one private slot. An individual frame/DTO and its decoded tree remain
     * an atomic PHP destruction cost; this bounds the number of those payloads, not their size.
     *
     * @param array<array-key, mixed> $entries Private array whose ownership ends here
     * @param ?SignalDispatch $origin Original metadata lifetime, independent of the canceling caller's dispatch context
     */
    public function retire(array &$entries, ?SignalDispatch $origin = null): void
    {
        if ($entries === []) {
            return;
        }
        $cleanup = self::release($entries);
        $entries = [];
        $this->schedule($cleanup, $origin);
    }

    /**
     * @param Generator<int, void> $cleanup Effect-free admission followed by bounded physical cleanup
     * @param ?SignalDispatch $origin Original metadata lifetime; only its scalar prefix membership is inherited
     */
    public function schedule(Generator $cleanup, ?SignalDispatch $origin = null): void
    {
        if ($this->defer !== null) {
            ($this->defer)($cleanup, $origin);
        } else {
            foreach ($cleanup as $_) {
            }
        }
    }

    /**
     * Only detached private arrays enter here: no other writer can trigger a COW copy.
     * Nested metadata maps are moved onto a stack rather than destroyed in a single unset.
     *
     * @param array<array-key, mixed> $entries Original array, transferred without a roster copy
     * @return Generator<int, void> Effect-free admission, then one slot or stack transition per step
     */
    private static function release(array $entries): Generator
    {
        yield;
        $parents = [];
        while ($entries !== [] || $parents !== []) {
            if ($entries === []) {
                $entries = array_pop($parents);
            } else {
                $key = array_key_last($entries);
                if (is_array($entries[$key])) {
                    $nested = $entries[$key];
                    unset($entries[$key]);
                    $parents[] = $entries;
                    $entries = $nested;
                    unset($nested);
                } else {
                    unset($entries[$key]);
                }
            }
            yield;
        }
    }
}
