// Wire constants shared with the backend. Values must stay byte-identical to
// their PHP counterparts; each constant names its server-side source.

/** Signal `type` of the framework welcome frame (PHP `SignalTypeConstants::HANDSHAKE`). */
export const SIGNAL_TYPE_HANDSHAKE = 'handshake'

/** Client frame `type` subscribing the page (PHP `SignalTypeConstants::PAGE_SUBSCRIBE`). */
export const SIGNAL_TYPE_PAGE_SUBSCRIBE = 'page_subscribe'

/** Client frame `type` leaving the page (PHP `SignalTypeConstants::PAGE_UNSUBSCRIBE`). */
export const SIGNAL_TYPE_PAGE_UNSUBSCRIBE = 'page_unsubscribe'

/** Server frame `type` answering a page subscription (PHP `SignalTypeConstants::PAGE_RESPONSE`). */
export const SIGNAL_TYPE_PAGE_RESPONSE = 'page_response'

/** Client frame `type` invoking a page action (PHP `SignalTypeConstants::ACTION`). */
export const SIGNAL_TYPE_ACTION = 'action'

/** Server frame `type` reporting a failed page action (PHP `SignalConstants::ACTION_ERROR`). */
export const SIGNAL_TYPE_ACTION_ERROR = 'action_error'

/** Server frame `type` confirming a tracked page action committed (PHP `SignalConstants::ACTION_SUCCESS`). */
export const SIGNAL_TYPE_ACTION_SUCCESS = 'action_success'

/** Client frame `type` setting a table's window (PHP `SignalTypeConstants::TABLE_VIEWPORT`). */
export const SIGNAL_TYPE_TABLE_VIEWPORT = 'table_viewport'

/** Server frame `type` replying a table window snapshot (PHP `SignalTypeConstants::TABLE_WINDOW`). */
export const SIGNAL_TYPE_TABLE_WINDOW = 'table_window'

/** Server frame `type` carrying a live table pending change (PHP `SignalTypeConstants::TABLE_VIEWPORT_DELTA`). */
export const SIGNAL_TYPE_TABLE_VIEWPORT_DELTA = 'table_viewport_delta'

/** Server frame `type` carrying a live table count update (PHP `SignalTypeConstants::TABLE_VIEWPORT_COUNT`). */
export const SIGNAL_TYPE_TABLE_VIEWPORT_COUNT = 'table_viewport_count'

/** Server frame `type` carrying a live table tail append (PHP `SignalTypeConstants::TABLE_VIEWPORT_APPEND`). */
export const SIGNAL_TYPE_TABLE_VIEWPORT_APPEND = 'table_viewport_append'

/** Frame envelope key carrying the message type (PHP `SignalPayloadConstants::FIELD_TYPE`). */
export const FIELD_TYPE = 'type'

/** Subscribe frame key carrying the page key (PHP `SignalPayloadConstants::FIELD_PAGE`). */
export const FIELD_PAGE = 'page'

/** Subscribe frame key carrying the route params (PHP `SignalPayloadConstants::FIELD_PARAMS`). */
export const FIELD_PARAMS = 'params'

/** Action frame key carrying the action name (PHP `SignalPayloadConstants::FIELD_ACTION`). */
export const FIELD_ACTION = 'action'

/** Action frame key carrying the action payload (PHP `SignalPayloadConstants::FIELD_DATA`). */
export const FIELD_DATA = 'data'

/** Action/reply frame key carrying the client-minted request id (PHP `SignalPayloadConstants::FIELD_REQUEST_ID`). */
export const FIELD_REQUEST_ID = 'requestId'

/** Viewport frame key carrying the table key (PHP `SignalPayloadConstants::FIELD_TABLE_KEY`). */
export const FIELD_TABLE_KEY = 'tableKey'

/** Viewport frame key carrying the filter map (PHP `SignalPayloadConstants::FIELD_FILTER`). */
export const FIELD_FILTER = 'filter'

/** Viewport frame key carrying the sort `{field, direction}` (PHP `SignalPayloadConstants::FIELD_SORT`). */
export const FIELD_SORT = 'sort'

/** Viewport frame key carrying the window offset (PHP `SignalPayloadConstants::FIELD_OFFSET`). */
export const FIELD_OFFSET = 'offset'

/** Viewport frame key carrying the window limit (PHP `SignalPayloadConstants::FIELD_LIMIT`). */
export const FIELD_LIMIT = 'limit'

/** Keepalive text frame the client sends (PHP `WebSocketConstants::KEEPALIVE_TEXT_PING`). */
export const KEEPALIVE_TEXT_PING = 'ping'
