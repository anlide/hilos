<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * RestorePhase - where a restore run currently is.
 *
 * The full set is what the engine actually passes through and what the cold path
 * prints inline. The hot path's runtime row reports a coarse subset (the supervisor
 * only sees its child's lifecycle, not the steps inside it); per-step progress over
 * the wire is HIL-277's work, which this enum already accommodates.
 */
enum RestorePhase: string
{
    /** Accepted, waiting for protected mode before anything runs. */
    case PENDING = 'pending';

    /** Re-checking the archive digest before any destructive step. */
    case VERIFYING = 'verifying';

    /** Unpacking the archive into a temporary workdir. */
    case EXTRACTING = 'extracting';

    /** Importing the dumps into the configured connections. */
    case IMPORTING = 'importing';

    /** Bringing each restored database up to the migration level the code expects. */
    case MIGRATING = 'migrating';

    /** Running the anonymization pass over the imported data. */
    case ANONYMIZING = 'anonymizing';

    /**
     * Re-reading the replaced database everywhere before anything is let back in (HIL-436).
     *
     * Set by the supervising agent rather than by the engine: the child process is already dead by
     * the time this begins, and what happens here is the node putting itself back together - every
     * worker, and in a cluster every node, dropping caches of a database that no longer exists.
     */
    case REHYDRATING = 'rehydrating';

    /** Terminal: the restore completed. */
    case SUCCEEDED = 'succeeded';

    /** Terminal: the restore failed; the runtime row carries the reason. */
    case FAILED = 'failed';

    /**
     * What share of a run each phase takes; the entries sum to a whole run.
     *
     * Code constants rather than measured per-run figures, for the reason {@see BackupPhase}
     * states: per-phase durations would have to be written into every sidecar and the whole store
     * rescanned, and a progress bar is not worth a change of the archive format. The phases with
     * no share of their own are the ones a run does not spend time in - it waits in PENDING before
     * anything runs, and it is already over in SUCCEEDED and FAILED.
     */
    private const array WEIGHTS = [
        self::PENDING->value => 0.0,
        self::VERIFYING->value => 0.05,
        self::EXTRACTING->value => 0.15,
        self::IMPORTING->value => 0.55,
        self::MIGRATING->value => 0.10,
        self::ANONYMIZING->value => 0.10,
        self::REHYDRATING->value => 0.05,
        self::SUCCEEDED->value => 0.0,
        self::FAILED->value => 0.0,
    ];

    /**
     * The share of a whole run this phase is expected to take.
     *
     * @return float Share of the run, between 0 and 1; the cases sum to 1
     */
    public function weight(): float
    {
        return self::WEIGHTS[$this->value];
    }

    /**
     * The share of a whole run already behind a run that has just entered this phase.
     *
     * Summed from the phases before it rather than written out again, so the two figures cannot
     * drift apart: the declaration order of the cases is the order a run passes through them, and
     * both terminal cases therefore report a whole run behind them.
     *
     * @return float Share of the run completed before this phase, between 0 and 1
     */
    public function weightBefore(): float
    {
        $before = 0.0;
        foreach (self::cases() as $case) {
            if ($case === $this) {
                break;
            }

            $before += $case->weight();
        }

        return $before;
    }
}
