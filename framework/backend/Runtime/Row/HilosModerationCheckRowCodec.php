<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Item\HilosModerationCheck;
use Hilos\Runtime\State\Item\RtState;
use LogicException;

/** The concrete row owns validation and access to its private-set fields. */
final class HilosModerationCheckRowCodec implements RtRowCodec
{
    /**
     * @param RtState $state Nested-value row
     * @param RtRowWriter $out Unpublished destination
     * @return Generator<int, RtWork, int, void> Bounded export
     * @throws InvalidFormatException When stored values are malformed
     * @throws LogicException When the row type differs or the output is closed
     */
    public function export(RtState $state, RtRowWriter $out): Generator
    {
        if (!$state instanceof HilosModerationCheck) {
            throw new LogicException('HilosModerationCheck codec needs a HilosModerationCheck row');
        }
        yield from $state->exportValues($out);
    }

    /**
     * @param RtRowReader $in Segmented input
     * @return Generator<int, RtWork, int, HilosModerationCheck> Fully validated candidate
     * @throws InvalidFormatException When the row violates its existing contract
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    public function restore(RtRowReader $in): Generator
    {
        return yield from HilosModerationCheck::fromValues($in);
    }

    /**
     * @param RtState $draft Invisible target draft
     * @param RtRowReader $diff Segmented changed fields
     * @return Generator<int, RtWork, int, void> Unchanged draft, matching the legacy no-op patch
     * @throws LogicException When the row type differs or preparation is closed
     */
    public function patch(RtState $draft, RtRowReader $diff): Generator
    {
        if (!$draft instanceof HilosModerationCheck) {
            throw new LogicException('HilosModerationCheck codec needs a HilosModerationCheck draft');
        }
        yield from $draft->patchValues($diff);
    }
}
