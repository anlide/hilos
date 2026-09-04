// Wire constants shared with the backend. Values must stay byte-identical to
// their PHP counterparts; each constant names its server-side source.

/** Signal `type` of the framework welcome frame (PHP `SignalTypeConstants::HANDSHAKE`). */
export const SIGNAL_TYPE_HANDSHAKE = 'handshake'

/** Server frame `type` announcing the protected-mode state (PHP `SignalTypeConstants::PROTECTED_MODE`). */
export const SIGNAL_TYPE_PROTECTED_MODE = 'protected_mode'

/**
 * Server frame `type` saying whether anything this connection's page reads is a
 * frozen replica (PHP `SignalTypeConstants::RT_STALENESS`).
 */
export const SIGNAL_TYPE_RT_STALENESS = 'rt_staleness'

/**
 * Upgrade-request query parameter a verifier's protected-mode pass rides in (PHP
 * `ProtectedModeAdmissionConstants::HILOS_PASS_QUERY_PARAM`).
 *
 * A query parameter and not a frame, because while the mode holds this client is
 * refused every outbound frame — admission has to be decided on the 101, where the
 * initiator's own exemption is decided.
 */
export const PROTECTED_MODE_PASS_PARAM = 'hilosPass'

/** Server frame `type` handing one connection a session-rotation ticket (PHP `HilosSignalConstants::HILOS_SESSION_ROTATE`). */
export const SIGNAL_TYPE_SESSION_ROTATE = 'hilos_session_rotate'

/**
 * Appended to the session cookie name to name the cookie a rotation ticket travels
 * back in (PHP `SessionRotationTicket::COOKIE_NAME_SUFFIX`). The session cookie name
 * itself is not a constant on either side — it is deployment configuration, and the
 * welcome frame tells the client which one this deployment uses.
 */
export const SESSION_ROTATE_COOKIE_SUFFIX = '_rotate'

/** Client frame `type` subscribing the page (PHP `SignalTypeConstants::PAGE_SUBSCRIBE`). */
export const SIGNAL_TYPE_PAGE_SUBSCRIBE = 'page_subscribe'

/** Client frame `type` leaving the page (PHP `SignalTypeConstants::PAGE_UNSUBSCRIBE`). */
export const SIGNAL_TYPE_PAGE_UNSUBSCRIBE = 'page_unsubscribe'

/** Server frame `type` answering a page subscription (PHP `SignalTypeConstants::PAGE_RESPONSE`). */
export const SIGNAL_TYPE_PAGE_RESPONSE = 'page_response'

/** Server frame `type` reporting a page subscription error (PHP `SignalConstants::SUBSCRIPTION_PAGE_ERROR`). */
export const SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR = 'subscription_page_error'

/** Client frame `type` joining a WebSocket group (PHP `SignalTypeConstants::GROUP_SUBSCRIBE`). */
export const SIGNAL_TYPE_GROUP_SUBSCRIBE = 'group_subscribe'

/** Client frame `type` leaving a WebSocket group (PHP `SignalTypeConstants::GROUP_UNSUBSCRIBE`). */
export const SIGNAL_TYPE_GROUP_UNSUBSCRIBE = 'group_unsubscribe'

/** Server frame `type` answering a group join (PHP `SignalTypeConstants::GROUP_RESPONSE`). */
export const SIGNAL_TYPE_GROUP_RESPONSE = 'group_response'

/** Server frame `type` refusing a group join (PHP `SignalConstants::SUBSCRIPTION_GROUP_ERROR`). */
export const SIGNAL_TYPE_GROUP_SUBSCRIPTION_ERROR = 'subscription_group_error'

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

/** Server frame `type` carrying the author's own new row, already placed (PHP `SignalTypeConstants::TABLE_VIEWPORT_OWN_CREATE`). */
export const SIGNAL_TYPE_TABLE_VIEWPORT_OWN_CREATE = 'table_viewport_own_create'

/** Frame envelope key carrying the message type (PHP `SignalPayloadConstants::FIELD_TYPE`). */
export const FIELD_TYPE = 'type'

/** Subscribe frame key carrying the page key (PHP `SignalPayloadConstants::FIELD_PAGE`). */
export const FIELD_PAGE = 'page'

/** Group frame key carrying the group identifier (PHP `SignalPayloadConstants::FIELD_GROUP`). */
export const FIELD_GROUP = 'group'

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
