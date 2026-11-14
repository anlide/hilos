<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

use Hilos\Runtime\RtRowReader;

/**
 * @internal The arena takes an abandoned stack in O(1), then detaches one frame per
 * retirement step. Cancel never destroys an arbitrary-depth generator/native stack.
 */
final class RtRowCopyStack
{
    public ?RtRowCopyFrame $head = null;

    /**
     * @param RtRowArena $arena Destination's service-state owner
     */
    public function __construct(private readonly RtRowArena $arena)
    {
    }

    /** Transfers the whole suspended stack without walking or freeing it. */
    public function __destruct()
    {
        if ($this->head !== null) {
            $this->arena->retireCopyStack($this->head);
            $this->head = null;
        }
    }

    /**
     * @param RtRowReader $source One child to visit
     * @param RtRowRole $role Completed value's role
     */
    public function push(RtRowReader $source, RtRowRole $role): void
    {
        $this->head = new RtRowCopyFrame($source, $role, $this->head);
    }

    /** Removes one completed frame, detaching its parent before any destructor runs. */
    public function pop(): void
    {
        $frame = $this->head;
        $this->head = $frame->previous;
        $frame->previous = null;
    }
}
