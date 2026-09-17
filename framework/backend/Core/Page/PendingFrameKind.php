<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

/**
 * Which of the identity-judged frames one parked entry holds (HIL-599, HIL-689).
 *
 * Names the doors that ask who is behind a connection before they act, because each
 * waits for something slightly different and reports itself differently in the log:
 * a subscribe, a subscription update and an action wait for the identity alone, a
 * viewport request also waits for the subscription it is addressed to
 * ({@see PageSignalRouter::releasePendingFrames}).
 */
enum PendingFrameKind: string
{
    /** A page_subscribe frame, judged by the page access gate before onSubscribe. */
    case PageSubscribe = 'page_subscribe';

    /** An action frame, judged by the page access level and the action auth guard. */
    case Action = 'action';

    /** A table_viewport frame, whose window delivery re-checks the page guards. */
    case TableViewport = 'table_viewport';

    /** A table_facets frame, whose counts re-check the page guards the way a window does. */
    case TableFacets = 'table_facets';

    /** A table_rendered frame, whose second read of the window re-checks the page guards the way a window does. */
    case TableRendered = 'table_rendered';

    /** A page_update_subscription frame, judged like a subscribe but on the merged params. */
    case PageUpdateSubscription = 'page_update_subscription';

    /**
     * Whether a frame at this door also waits for the page subscription it is addressed to.
     *
     * The three table doors do: what they deliver or read re-checks the page guards, and those guards read
     * the subscription's params - judged without it they judge an empty param set, which is a
     * different question from the one the client asked.
     *
     * @return bool Whether the frame waits for its page subscription as well as for the identity
     */
    public function waitsForPageSubscription(): bool
    {
        return $this === self::TableViewport || $this === self::TableFacets || $this === self::TableRendered;
    }
}
