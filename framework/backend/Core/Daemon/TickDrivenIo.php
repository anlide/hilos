<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

/**
 * An asynchronous operation that moves only when the process ticks it.
 *
 * A worker waits on its daemon socket between ticks. While any such operation is in flight,
 * nothing would wake that wait when the operation's own socket, pipe or result becomes ready,
 * so the worker keeps its short tick until the operation settles ({@see TickDrivenIoRegistry}).
 */
interface TickDrivenIo
{
    /** @return bool True while the operation still needs ticks: a request, a reply or a result not yet taken */
    public function isInFlight(): bool;
}
