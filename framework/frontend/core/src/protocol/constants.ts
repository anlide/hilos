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

/** Frame envelope key carrying the message type (PHP `SignalPayloadConstants::FIELD_TYPE`). */
export const FIELD_TYPE = 'type'

/** Subscribe frame key carrying the page key (PHP `SignalPayloadConstants::FIELD_PAGE`). */
export const FIELD_PAGE = 'page'

/** Subscribe frame key carrying the route params (PHP `SignalPayloadConstants::FIELD_PARAMS`). */
export const FIELD_PARAMS = 'params'

/** Keepalive text frame the client sends (PHP `WebSocketConstants::KEEPALIVE_TEXT_PING`). */
export const KEEPALIVE_TEXT_PING = 'ping'
