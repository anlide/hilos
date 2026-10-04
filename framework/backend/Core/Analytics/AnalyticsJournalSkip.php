<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/**
 * Why {@see AnalyticsJournalLoader} passed over a record of a journal file instead of applying it.
 *
 * A skipped record never stops its file: a file that cannot be loaded whole would stop the whole
 * journal of its node for good. The writer counts the skips by reason and complains in one line.
 */
enum AnalyticsJournalSkip: string
{
    /** Not a JSON object, or a field missing or of the wrong type - the tail a machine crash leaves. */
    case MALFORMED = 'malformed';

    /** A record type this writer does not know. */
    case UNKNOWN_TYPE = 'unknown_type';

    /** A record names a session no description wrote. */
    case UNKNOWN_SESSION = 'unknown_session';

    /** A page, action or address change names a connection with no opening or attachment. */
    case UNKNOWN_CONNECTION = 'unknown_connection';
}
