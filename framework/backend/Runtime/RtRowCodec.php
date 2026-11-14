<?php

declare(strict_types=1);

namespace Hilos\Runtime;

use Generator;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Item\RtState;
use Throwable;

/** A concrete row owns its validation and normalization, including nested values. */
interface RtRowCodec
{
    /**
     * @param RtState $state Row whose immutable values are exported
     * @param RtRowWriter $out Unpublished output
     * @return Generator<int, RtWork, int, void> Bounded export preparation
     * @throws Throwable Whatever the concrete row's export contract raises
     */
    public function export(RtState $state, RtRowWriter $out): Generator;

    /**
     * @param RtRowReader $in Immutable incoming values
     * @return Generator<int, RtWork, int, RtState> Fully prepared, unpublished row
     * @throws Throwable Whatever the concrete row's validation raises
     */
    public function restore(RtRowReader $in): Generator;

    /**
     * @param RtState $draft Invisible target draft
     * @param RtRowReader $diff Immutable incoming fields
     * @return Generator<int, RtWork, int, void> Atomic draft patch preparation
     * @throws Throwable Whatever the concrete row's patch validation raises
     */
    public function patch(RtState $draft, RtRowReader $diff): Generator;
}
