<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

/**
 * Constants for table layer (order direction, magic property names, etc.).
 */
final class TableConstants
{
    /** Limit value meaning "return all rows" (no pagination limit). */
    public const int NO_LIMIT = 0;

    /**
     * Rows the first window carries for a table that declared no size of its own.
     *
     * The first window is built on the server now, so a size has to exist before any table
     * states one; this is that size. It is a fallback and not a recommendation — a table whose
     * rows are short, or whose page shows one line each, says so itself by declaring its own
     * window size on its definition.
     */
    public const int DEFAULT_WINDOW_SIZE = 25;

    /** Order direction: ascending */
    public const string ORDER_ASC = 'asc';

    /** Order direction: descending */
    public const string ORDER_DESC = 'desc';

    /** Magic property name for table/item actions (used in __get, __isset). */
    public const string PROPERTY_ACTIONS = 'actions';

    /** Payload key for table identifier in refresh/action DTOs. */
    public const string PAYLOAD_KEY_TABLE_KEY = 'tableKey';

    /** Payload key for the row keys a bulk action names one by one. */
    public const string PAYLOAD_KEY_ROW_KEYS = 'rowKeys';

    /** Generic viewport filter-map key resolved to the query search term. */
    public const string FILTER_KEY_SEARCH = 'search';

    /** Payload key for action name in error signal. */
    public const string PAYLOAD_KEY_ACTION = 'action';

    /** Payload key for error message in error signal. */
    public const string PAYLOAD_KEY_MESSAGE = 'message';

    /** Payload key for row mutation DTO in mutation signal. */
    public const string PAYLOAD_KEY_MUTATION = 'mutation';

    /** Mutation DTO key: type (create/update/delete). */
    public const string MUTATION_KEY_TYPE = 'type';

    /** Mutation DTO key: stable row key. */
    public const string MUTATION_KEY_ROW_KEY = 'rowKey';

    /** Mutation DTO key: row data (optional). */
    public const string MUTATION_KEY_ROW = 'row';

    /** Result key for rows array. */
    public const string RESULT_KEY_ROWS = 'rows';

    /** Result key for total count. */
    public const string RESULT_KEY_TOTAL_COUNT = 'totalCount';

    /** Result key for whether the total count is the size of the set rather than the ceiling it stopped at. */
    public const string RESULT_KEY_TOTAL_EXACT = 'totalExact';

    /**
     * Rows a windowed query counts before it stops and reports "at least this many".
     *
     * Counting the whole set is a full pass over it, and a window repeats that pass every time
     * it is served. Past this many rows the exact number buys nothing the reader can use — the
     * table shows "500+" and offers no page numbers — so the count stops here instead.
     */
    public const int COUNT_CEILING = 500;

    /**
     * Options a filter may offer and still have each of them counted.
     *
     * Every option is counted by a query of its own, so a list of a hundred options would order a
     * hundred counts on every change of the set. A longer list is not counted at all rather than
     * counted in part: a number beside some options and none beside the rest reads as zero.
     */
    public const int FACET_OPTION_LIMIT = 20;

    /** Facet key: the count of the set with the filter lifted altogether, the "any" option. */
    public const string FACET_KEY_ANY = 'any';

    /** Facet key: the counts of the options, keyed by the option value as text. */
    public const string FACET_KEY_OPTIONS = 'options';

    /** Facet count key: the number of rows, or the ceiling it stopped at. */
    public const string FACET_KEY_COUNT = 'count';

    /** Facet count key: whether that number is the size of the set rather than the ceiling. */
    public const string FACET_KEY_EXACT = 'exact';

    /**
     * Rows a bulk run keeps in flight - handed out in one tick, and awaiting a verdict at once.
     *
     * One bound serves both counts. Handing the whole target to the owner at once floods its
     * queue and stalls everyone else on it; judging one row per tick turns deleting forty rows
     * into a matter of minutes. Neither end is a setting: a bulk run behaves one way.
     */
    public const int BULK_ROWS_IN_FLIGHT = 10;

    /**
     * Seconds a bulk run waits for the verdict on one row before calling the row untouched.
     *
     * A guard without a deadline is a guard in name only: the owner of a row is another process,
     * and its silence has to become a named outcome rather than a run that never ends.
     */
    public const float BULK_VERDICT_TIMEOUT_SECONDS = 15.0;

    /**
     * Untouched rows a report names before it stops naming them and counts the rest.
     *
     * "39 of 40 deleted" without names is forbidden, and ten thousand names in one frame are
     * that same absence in another form - no reader gets through them and no browser draws them.
     */
    public const int BULK_UNTOUCHED_NAME_CEILING = 200;

    /** Reason a row is untouched: it had left the set before its turn came. */
    public const string BULK_REASON_ROW_GONE = 'The row was gone by the time its turn came';

    /** Reason a row is untouched: the owner of the row never answered the verdict asked of it. */
    public const string BULK_REASON_NO_VERDICT = 'The owner of the row did not answer in time';

    /** Result key for objects array (Object layer queryPage intermediate result). */
    public const string RESULT_KEY_OBJECTS = 'objects';

    /** Result key for the place the first row of the window sits at. */
    public const string RESULT_KEY_FIRST_ANCHOR = 'firstAnchor';

    /** Result key for the place the last row of the window sits at. */
    public const string RESULT_KEY_LAST_ANCHOR = 'lastAnchor';

    /** Result key for the places standing right outside the window, which never leave the server. */
    public const string RESULT_KEY_FRAME = 'frame';

    /** Result key for limit. */
    public const string RESULT_KEY_LIMIT = 'limit';

    /**
     * Result key for how many rows of the set stand before the first row of the window.
     *
     * This is what the window says about its place in the set, and the client derives the page
     * number and the row range shown in the footer from it. It exists only where page numbers
     * do - under an exact count - because past {@see self::COUNT_CEILING} there is no set size
     * for a place to be read against.
     */
    public const string RESULT_KEY_ROWS_BEFORE = 'rowsBefore';
}
