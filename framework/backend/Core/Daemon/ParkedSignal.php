<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\Page\PendingFrame;
use Hilos\Core\Router\DTO\SignalDTO;

/**
 * One signal the master holds before routing it, waiting to learn who is behind its
 * connection (HIL-627).
 *
 * The master's twin of {@see PendingFrame}, and held for the same reason: a subscription to
 * a page whose instance is the person behind the connection cannot be addressed until the
 * connection's row has crossed the RT sync. Routing it then would send a signed-in person's
 * page to the fallback agent, which answers a guest.
 *
 * The whole signal is kept, not a closure over it, so a released signal walks the very same
 * path as one that never waited.
 */
final readonly class ParkedSignal
{
    /**
     * @param SignalDTO $signal Signal as it was queued
     * @param float $deadline Unix seconds after which the signal is routed whether or not the identity arrived
     */
    public function __construct(
        public SignalDTO $signal,
        public float $deadline,
    ) {
    }
}
