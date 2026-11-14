<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

use Hilos\Runtime\RtRowReader;

/** @internal Equality uses the arena's existing retirement lane for abandoned frames. */
final class RtRowCompareStack
{
    public ?RtRowCompareFrame $head = null;

    /** @param RtRowArena $arena Shared storage and retirement owner */
    public function __construct(private readonly RtRowArena $arena)
    {
    }

    /** Detaches a suspended walk without destroying a native chain. */
    public function __destruct()
    {
        if ($this->head !== null) {
            $this->arena->retireComparisonStack($this->head);
            $this->head = null;
        }
    }

    /**
     * @param RtRowReader $left Current subvalue
     * @param RtRowReader $right Baseline subvalue
     */
    public function push(RtRowReader $left, RtRowReader $right): void
    {
        $this->head = new RtRowCompareFrame($left, $right, $this->head);
    }

    /** Drops one completed frame after detaching its parent. */
    public function pop(): void
    {
        $frame = $this->head;
        $this->head = $frame->previous;
        $frame->previous = null;
    }
}
