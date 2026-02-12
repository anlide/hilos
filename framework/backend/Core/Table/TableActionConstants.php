<?php

declare(strict_types=1);

namespace Hilos\Core\Table;

/**
 * Action names and payload keys for table-related actions.
 */
final class TableActionConstants
{
    /** Action: load one page (pagination) */
    public const string ACTION_LOAD_PAGE = 'table_load_page';

    /** Action: refresh snapshot (full reload for one table) */
    public const string ACTION_REFRESH_SNAPSHOT = 'table_refresh_snapshot';

    /** Payload key: table key (e.g. 'users') */
    public const string PAYLOAD_KEY_TABLE_KEY = 'tableKey';

    /** Payload key: zero-based offset for load_page */
    public const string PAYLOAD_KEY_OFFSET = 'offset';

    /** Payload key: page size for load_page */
    public const string PAYLOAD_KEY_LIMIT = 'limit';
}
