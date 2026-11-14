<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Item\BackupHistory;
use Hilos\Runtime\State\Item\RtState;
use LogicException;

/** BackupHistory owns its prepared factory and private-set fields. */
final class BackupHistoryRowCodec implements RtRowCodec
{
    /**
     * @param RtState $state Backup history row
     * @param RtRowWriter $out Unpublished destination
     * @return Generator<int, RtWork, int, void> Bounded export
     * @throws InvalidFormatException When a stored value is malformed
     * @throws LogicException When a different row type is passed or the output is closed
     */
    public function export(RtState $state, RtRowWriter $out): Generator
    {
        if (!$state instanceof BackupHistory) {
            throw new LogicException('Backup history codec needs a backup history row');
        }
        yield from $state->exportValues($out);
    }

    /**
     * The factory prepares a candidate only. Committed roots and segmented sync
     * baselines attach when the Runtime publication path is migrated.
     *
     * @param RtRowReader $in Segmented input
     * @return Generator<int, RtWork, int, BackupHistory> Fully validated candidate
     * @throws InvalidFormatException When the row violates its existing contract
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    public function restore(RtRowReader $in): Generator
    {
        return yield from BackupHistory::fromValues($in);
    }

    /**
     * @param RtState $draft Invisible backup history draft
     * @param RtRowReader $diff Segmented changed fields
     * @return Generator<int, RtWork, int, void> Fully validated atomic draft patch
     * @throws InvalidFormatException When a changed field violates its existing contract
     * @throws LogicException When a different row type is passed or preparation is closed
     */
    public function patch(RtState $draft, RtRowReader $diff): Generator
    {
        if (!$draft instanceof BackupHistory) {
            throw new LogicException('Backup history codec needs a backup history draft');
        }
        yield from $draft->patchValues($diff);
    }
}
