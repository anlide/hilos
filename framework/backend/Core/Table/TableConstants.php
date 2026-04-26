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

    /** Order direction: ascending */
    public const string ORDER_ASC = 'asc';

    /** Order direction: descending */
    public const string ORDER_DESC = 'desc';

    /** Magic property name for table/item actions (used in __get, __isset). */
    public const string PROPERTY_ACTIONS = 'actions';

    /** Payload key for table identifier in refresh/action DTOs. */
    public const string PAYLOAD_KEY_TABLE_KEY = 'tableKey';

    /** Payload key for action name in error signal. */
    public const string PAYLOAD_KEY_ACTION = 'action';

    /** Payload key for error message in error signal. */
    public const string PAYLOAD_KEY_MESSAGE = 'message';

    /** Payload key for mutation entry in mutation signal. */
    public const string PAYLOAD_KEY_MUTATION = 'mutation';

    /** Mutation entry key: type (created/updated/deleted). */
    public const string MUTATION_KEY_TYPE = 'type';

    /** Mutation entry key: stable row key. */
    public const string MUTATION_KEY_ROW_KEY = 'rowKey';

    /** Mutation entry key: row data (optional). */
    public const string MUTATION_KEY_ROW = 'row';

    /** Result key for rows array. */
    public const string RESULT_KEY_ROWS = 'rows';

    /** Result key for total count. */
    public const string RESULT_KEY_TOTAL_COUNT = 'totalCount';

    /** Result key for objects array (Object layer queryPage intermediate result). */
    public const string RESULT_KEY_OBJECTS = 'objects';

    /** Result key for offset. */
    public const string RESULT_KEY_OFFSET = 'offset';

    /** Result key for limit. */
    public const string RESULT_KEY_LIMIT = 'limit';
}
