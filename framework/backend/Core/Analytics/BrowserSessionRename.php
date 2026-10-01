<?php

declare(strict_types=1);

namespace Hilos\Core\Analytics;

/**
 * What {@see AnalyticsStore::renameBrowserSession()} did with a rotated token.
 */
enum BrowserSessionRename
{
    /** The session moved onto the new token. */
    case RENAMED;

    /** No session answers to the old token: it was never opened, so there is nothing to move. */
    case ABSENT;

    /**
     * A session already answers to the new token, so the move would collide on the unique token.
     * Nothing was changed: the visit stays split in two rows.
     */
    case CONFLICT;
}
