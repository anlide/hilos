<?php

declare(strict_types=1);

namespace Hilos\Runtime\Snapshot;

use Generator;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowPage;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowValues;
use LogicException;

/**
 * @internal Ordered traversal with an arena-owned suspended path.
 * SCAFFOLD: production snapshot export will use this cursor after activation.
 */
final class RtIndexCursor
{
    private ?RtRowPage $next;
    private ?RtRowValues $path = null;
    private bool $closed = false;
    private bool $busy = false;

    /**
     * @param RtRowArena $arena Storage owner
     * @param RtRowValues $lease Independently retained version root
     * @param ?RtRowPage $root Index within that version
     */
    public function __construct(private readonly RtRowArena $arena, private readonly RtRowValues $lease, ?RtRowPage $root)
    {
        $this->next = $root;
    }

    /**
     * @return Generator<int, RtWork, int, ?RtRowValues> Next payload in key order, or end
     * @throws LogicException When canceled, already advancing, or its path closed
     */
    public function advance(): Generator
    {
        yield new RtWork();
        if ($this->closed || $this->busy) {
            throw new LogicException('The RT index cursor is closed or already advancing');
        }
        $source = $this->lease->retain(RtRowRole::Transient);
        $this->busy = true;
        try {
            $next = $this->next;
            $path = $this->path?->retain(RtRowRole::Transient);
            while ($next !== null && $next->kind === RtRowKind::IndexBranch) {
                $path = yield from RtVersionPages::make(
                    $this->arena,
                    RtRowKind::Path,
                    first: $next->second,
                    next: $path?->rootIn($this->arena),
                );
                $next = $next->first;
            }
            if ($next === null) {
                return null;
            }
            yield new RtWork();
            $payload = new RtRowValues($this->arena, $next->first, RtRowRole::Transient);
            if ($path === null) {
                $next = null;
            } else {
                $frame = $path->rootIn($this->arena);
                $next = $frame->first;
                $path = $frame->next === null ? null : new RtRowValues($this->arena, $frame->next, RtRowRole::Transient);
            }
            if ($this->closed) {
                throw new LogicException('The RT index cursor was closed during traversal');
            }
            $this->next = $next;
            $this->path = $path;

            return $payload;
        } finally {
            $source->release();
            $this->busy = false;
        }
    }

    /** Closes this cursor immediately; its path and version retire incrementally. */
    public function cancel(): void
    {
        $this->closed = true;
        $this->next = null;
        $this->path = null;
        $this->lease->release();
    }
}
