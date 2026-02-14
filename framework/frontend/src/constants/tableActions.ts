/**
 * Action names and payload keys for table-related actions.
 * Must match backend TableActionConstants.
 */
export const TableActionConstants = {
  /** Action: load one page (pagination) */
  ACTION_LOAD_PAGE: 'table_load_page',

  /** Action: refresh snapshot (full reload for one table) */
  ACTION_REFRESH_SNAPSHOT: 'table_refresh_snapshot',

  /** Payload key: table key (e.g. 'users') in request */
  PAYLOAD_KEY_TABLE_KEY: 'tableKey',

  /** Payload key: zero-based offset for load_page */
  PAYLOAD_KEY_OFFSET: 'offset',

  /** Payload key: page size for load_page */
  PAYLOAD_KEY_LIMIT: 'limit',

  /** Payload key: table key in response (TableDataDTO) */
  PAYLOAD_KEY_RESPONSE_TABLE_KEY: 'key',

  /** Payload key: table type (Entity|Sql|Other) in TableDataDTO */
  PAYLOAD_KEY_TYPE: 'type',

  /** Payload key: rows array in TableDataDTO */
  PAYLOAD_KEY_ROWS: 'rows',

  /** Payload key: supportsSnapshot flag in TableDataDTO */
  PAYLOAD_KEY_SUPPORTS_SNAPSHOT: 'supportsSnapshot',

  /** Payload key: totalCount in TableDataDTO */
  PAYLOAD_KEY_TOTAL_COUNT: 'totalCount',

  /** Payload key: isPage flag in TableDataDTO */
  PAYLOAD_KEY_IS_PAGE: 'isPage',

  /** Default type value when not provided */
  DEFAULT_TYPE: 'entity',

  /** Payload key: nested payload in UnknownActionPayloadDTO::toArray() */
  PAYLOAD_KEY_DATA: 'data',

  /** Default offset when not provided in load_page payload */
  DEFAULT_PAGE_OFFSET: 0,

  /** Default page size when limit is not provided in load_page payload */
  DEFAULT_PAGE_LIMIT: 20,
} as const
