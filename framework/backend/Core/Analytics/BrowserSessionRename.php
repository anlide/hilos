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

    /** Sessions under both tokens were combined into the new one. */
    case MERGED;

    /** The old token now points to the new one; no row had to move. */
    case ALIASED;
}
