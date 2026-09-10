<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

/**
 * Where a newly created row falls against a connection's window.
 *
 * This is the whole answer to "may this row arrive on its own": a window is only left standing
 * by a row that lands at its tail with room to hold it, so Tail is the one outcome delivered as
 * a row. The other three say the set moved under the window without any of the shown rows
 * moving.
 *
 * They part ways after that. Above and Inside are announced to the window — the row is one it
 * cannot show and one it would be wrong to stay silent about, since the window would otherwise
 * drift away from the set with only a reload telling the truth. Below is a count and nothing
 * more: a row on a later page was never shown and is not missing from anything.
 *
 * The cases are backed by strings for that same reason: above and inside are the two sides of
 * the announcement and leave the server under those names, so each case is worth a name that
 * survives the trip.
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
