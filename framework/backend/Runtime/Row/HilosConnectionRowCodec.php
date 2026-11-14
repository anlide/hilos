<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

use Generator;
use Hilos\HilosException;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\State\Item\RtState;
use LogicException;

/** The concrete row owns validation and access to its private-set fields. */
final class HilosConnectionRowCodec implements RtRowCodec
{
    /** @param class-string<HilosConnection> $stateClass Concrete connection class */
    public function __construct(private readonly string $stateClass)
    {
    }

    /**
     * @param RtState $state Scalar row
     * @param RtRowWriter $out Unpublished destination
     * @return Generator<int, RtWork, int, void> Bounded export
     * @throws HilosException When stored values are malformed
     * @throws LogicException When the row type differs or the output is closed
     */
    public function export(RtState $state, RtRowWriter $out): Generator
    {
        if (!$state instanceof $this->stateClass) {
            throw new LogicException('HilosConnection codec needs a HilosConnection row');
        }
        yield from $state->exportValues($out);
    }

    /**
     * @param RtRowReader $in Segmented input
     * @return Generator<int, RtWork, int, HilosConnection> Fully validated candidate
     * @throws HilosException When the row violates its existing contract
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    public function restore(RtRowReader $in): Generator
    {
        return yield from $this->stateClass::fromValues($in);
    }

    /**
     * @param RtState $draft Invisible target draft
     * @param RtRowReader $diff Segmented changed fields
     * @return Generator<int, RtWork, int, void> Fully validated atomic draft patch
     * @throws HilosException When a changed field violates its existing contract
     * @throws LogicException When the row type differs or preparation is closed
     */
    public function patch(RtState $draft, RtRowReader $diff): Generator
    {
        if (!$draft instanceof $this->stateClass) {
            throw new LogicException('HilosConnection codec needs a HilosConnection draft');
        }
        yield from $draft->patchValues($diff);
    }
}
