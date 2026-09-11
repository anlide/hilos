<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

/**
 * Where a progress bar of a table is drawn.
 *
 * Running work is not a record of the set and gets no row of its own, so it shows as a bar
 * instead. There are exactly three places a bar can stand, and this names which one a frame is
 * about: under one row, above the table, or inside the selection panel. The place is also the
 * address a bar is replaced at — one place holds one bar, and a frame naming a place puts its
 * bar there over whatever stood there before.
 *
 * The three differ in who fills them. Row and Table are declared by the table itself out of the
 * same sources its rows come from; Bulk belongs to the framework entirely, and the mass
 * operation's own runner sends it.
 *
 * The cases are backed by strings for the reason {@see TableRowPlacement} has: the names leave
 * the server on the wire and are worth a name that survives the trip.
 */
enum TableProgressScope: string
{
    /** Under its own row, tied to that row's key. */
    case Row = 'row';

    /** Above the table; the content beside the bar is the project's. */
    case Table = 'table';

    /** Inside the selection panel, for a mass action over the marked rows. */
    case Bulk = 'bulk';
}
