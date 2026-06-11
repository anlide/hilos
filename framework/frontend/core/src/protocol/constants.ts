// Wire constants shared with the backend. Values must stay byte-identical to
// their PHP counterparts; each constant names its server-side source.

/** Signal `type` of the framework welcome frame (PHP `SignalTypeConstants::HANDSHAKE`). */
export const SIGNAL_TYPE_HANDSHAKE = 'handshake'

/** Keepalive text frame the client sends (PHP `WebSocketConstants::KEEPALIVE_TEXT_PING`). */
export const KEEPALIVE_TEXT_PING = 'ping'
