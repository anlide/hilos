<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

/**
 * Which of the three identity-judged frames one parked entry holds (HIL-599).
 *
 * Names the doors that ask who is behind a connection before they act, because each
 * waits for something slightly different and reports itself differently in the log:
 * a subscribe and an action wait for the identity alone, a viewport request also
 * waits for the subscription it is addressed to ({@see PageSignalRouter::releasePendingFrames}).
 */
enum PendingFrameKind: string
{
    /** A page_subscribe frame, judged by the page access gate before onSubscribe. */
    case PageSubscribe = 'page_subscribe';

    /** An action frame, judged by the page access level and the action auth guard. */
    case Action = 'action';

    /** A table_viewport frame, whose window delivery re-checks the page guards. */
    case TableViewport = 'table_viewport';
}
