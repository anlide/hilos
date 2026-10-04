<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/**
 * Losses counted before the writer loads a file. Loader skips use the value of
 * {@see AnalyticsJournalSkip} in the same database column.
 */
enum AnalyticsLossReason: string
{
    /** A node journal at its byte ceiling could not take a new batch. */
    case JOURNAL_FULL = 'journal_full';

    /** A node journal could not write a batch to disk. */
    case JOURNAL_UNWRITABLE = 'journal_unwritable';

    /** A freeze discarded journal files or source events. */
    case RESTORE = 'restore';

    /** An event arrived with its oversized or unencodable payload removed. */
    case PAYLOAD_DROPPED = 'payload_dropped';

    /** An event could not fit or be encoded even without its payload. */
    case RECORD_DROPPED = 'record_dropped';

    /** The journal reader omitted an oversized line from a file. */
    case LINE_TOO_LONG = 'line_too_long';
}
