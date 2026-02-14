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

    /** Payload key: table key (e.g. 'users') in request */
    public const string PAYLOAD_KEY_TABLE_KEY = 'tableKey';

    /** Payload key: table key in response (TableDataDTO) */
    public const string PAYLOAD_KEY_RESPONSE_TABLE_KEY = 'key';

    /** Payload key: table type (Entity|Sql|Other) in TableDataDTO */
    public const string PAYLOAD_KEY_TYPE = 'type';

    /** Payload key: rows array in TableDataDTO */
    public const string PAYLOAD_KEY_ROWS = 'rows';

    /** Payload key: supportsSnapshot flag in TableDataDTO */
    public const string PAYLOAD_KEY_SUPPORTS_SNAPSHOT = 'supportsSnapshot';

    /** Payload key: totalCount in TableDataDTO */
    public const string PAYLOAD_KEY_TOTAL_COUNT = 'totalCount';

    /** Payload key: isPage flag in TableDataDTO */
    public const string PAYLOAD_KEY_IS_PAGE = 'isPage';

    /** Default type value when not provided */
    public const string DEFAULT_TYPE = 'entity';

    /** Payload key: nested payload in UnknownActionPayloadDTO::toArray() */
    public const string PAYLOAD_KEY_DATA = 'data';

    /** Payload key: zero-based offset for load_page */
    public const string PAYLOAD_KEY_OFFSET = 'offset';

    /** Payload key: page size for load_page */
    public const string PAYLOAD_KEY_LIMIT = 'limit';

    /** Default offset when not provided in load_page payload */
    public const int DEFAULT_PAGE_OFFSET = 0;

    /** Default page size when limit is not provided in load_page payload */
    public const int DEFAULT_PAGE_LIMIT = 20;
}
