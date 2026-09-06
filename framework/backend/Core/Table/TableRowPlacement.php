<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

/**
 * Where a newly created row falls against a connection's window.
 *
 * This is the whole answer to "may this row arrive on its own": a window is only left standing
 * by a row that lands at its tail with room to hold it, so Tail is the one outcome delivered as
 * a row. The other three say the set moved under the window without any of the shown rows
 * moving, and today all three travel the same way, as a count.
 *
 * They are told apart here anyway. The frame that shows the difference to a person — the strip
 * that announces rows the window is not showing — belongs to the next leaf, and it takes this
 * decision made rather than making it again over the same boundaries.
 *
 * The cases are backed by strings for that same reason: the doctrine already names above and
 * inside as the two sides of that future announcement, so each case is worth a name that
 * survives leaving the server.
 */
enum TableRowPlacement: string
{
    /** At the end of a window that reaches the end of the set and has a free slot. */
    case Tail = 'tail';

    /** Between two rows the window is already showing. */
    case Inside = 'inside';

    /** Above the window, on a page before the one it shows. */
    case Above = 'above';

    /** Below the window, on a page after the one it shows. */
    case Below = 'below';
}
