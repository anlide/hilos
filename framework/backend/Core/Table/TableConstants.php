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

    /** Result key for objects array (Object layer queryPage intermediate result). */
    public const string RESULT_KEY_OBJECTS = 'objects';

    /** Result key for the place the first row of the window sits at. */
    public const string RESULT_KEY_FIRST_ANCHOR = 'firstAnchor';

    /** Result key for the place the last row of the window sits at. */
    public const string RESULT_KEY_LAST_ANCHOR = 'lastAnchor';

    /** Result key for limit. */
    public const string RESULT_KEY_LIMIT = 'limit';
}
