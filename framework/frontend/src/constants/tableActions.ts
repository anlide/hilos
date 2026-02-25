/**
 * Action names and payload keys for table-related actions.
 * Must match backend ChatSignalConstants.
 */
export const TableActionConstants = {
  /** Action: refresh table data (full reload for one table) */
  TABLE_REFRESH: 'table_refresh',

  /** Payload key: table key (e.g. 'users') in request */
  PAYLOAD_KEY_TABLE_KEY: 'tableKey',
} as const
