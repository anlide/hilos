<?php

declare(strict_types=1);

namespace Hilos\Runtime\Ordinary;

use Hilos\Runtime\RtRowValues;

/** @internal One bounded FIFO cell; variable operation lists live in arena pages. */
final class RtOrdinaryReservation
{
    public ?RtRowValues $prefix = null;
    public int $bytes = 0;
    public bool $large = false;
    /** An ordinary compound longer than the whole operation quota, admitted alone into the exclusive slot */
    public bool $oversized = false;
    public bool $committed = false;
    public bool $closed = false;

    /**
     * @param RtOrdinaryQueue $queue Exact source admission owner
     * @param int $operations Entire compound operation count, reserved before preparation
     * @param bool $cleanup Internal service lane, never a permission bypass
     */
    public function __construct(public readonly RtOrdinaryQueue $queue, public readonly int $operations, public readonly bool $cleanup)
    {
    }

    /** Closing publication enqueues only this cell's independent page lease. */
    public function cancel(): void
    {
        $this->closed = true;
        $this->prefix?->release();
    }

    /** @return bool Whether this cell still owns a physical reference or a pending operation */
    public function isRetained(): bool
    {
        return !$this->closed || ($this->prefix?->isRetained() ?? false);
    }
}
