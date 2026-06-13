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

/** Keepalive text frame the client sends (PHP `WebSocketConstants::KEEPALIVE_TEXT_PING`). */
export const KEEPALIVE_TEXT_PING = 'ping'
