<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\LocalClientSignalBatch;
use Hilos\Core\Router\SignalRouter;

/**
 * Exact router incarnation and consumed position, without another signal queue.
 * Retained master intake keeps replaced routers until their captured tail is consumed.
 */
final class SignalDispatchIntake
{
    public int $consumed = 0;

    public ?int $retiredTail = null;

    /**
     * @param int $id Owner-local incarnation identity
     * @param SignalRouter $router Exact existing FIFO owner
     */
    public function __construct(public readonly int $id, public readonly SignalRouter $router)
    {
    }

    /** Captures only already queued input; later enqueues on a detached router cannot extend it. */
    public function retire(): void
    {
        $this->retiredTail ??= $this->getWatermark();
    }

    /** @return int Last input currently owed by this incarnation, without consuming it */
    public function getWatermark(): int
    {
        return $this->retiredTail ?? $this->consumed + $this->router->getQueuedSignalCount();
    }

    /** @return bool Whether this incarnation still owes dispatchable input */
    public function hasQueuedSignals(): bool
    {
        return ($this->retiredTail === null || $this->consumed < $this->retiredTail) && $this->router->hasQueuedSignals();
    }

    /** @return SignalDTO|LocalClientSignalBatch|null Next original FIFO obligation, absent at its finite end */
    public function consumeSignal(): SignalDTO|LocalClientSignalBatch|null
    {
        if (!$this->hasQueuedSignals()) {
            return null;
        }
        $signal = $this->router->consumeDispatchEntry();
        if ($signal !== null) {
            $this->consumed++;
        }
        return $signal;
    }
}
