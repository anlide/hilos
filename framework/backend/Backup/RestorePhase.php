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
}
