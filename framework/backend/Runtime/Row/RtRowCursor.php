<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

use Generator;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\Snapshot\RtWork;

/** A linked page index visits exactly one bounded entry per advance. */
final class RtRowCursor
{
    private readonly RtRowValues $lease;
    private ?RtRowPage $next;

    /**
     * @internal Constructed by the row reader, whose map type check already passed.
     *
     * @param RtRowArena $arena Runtime owner
     * @param RtRowPage $root Map root
     */
    public function __construct(private readonly RtRowArena $arena, RtRowPage $root)
    {
        $this->lease = new RtRowValues($arena, $root, RtRowRole::Transient);
        $this->next = $root->first;
    }

    /**
     * @return Generator<int, RtWork, int, ?RtRowEntry> Next entry, or null at the end
     */
    public function advance(): Generator
    {
        yield new RtWork();
        if ($this->next === null) {
            return null;
        }
        $page = $this->next;
        $this->next = $page->next;

        return new RtRowEntry(
            new RtRowValues($this->arena, $page->first, RtRowRole::Transient),
            new RtRowValues($this->arena, $page->second, RtRowRole::Transient),
        );
    }
}
