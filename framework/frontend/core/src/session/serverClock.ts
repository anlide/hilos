// The server clock every absolute moment from the backend is read against
// (HIL-486). The backend stamps its own "now" on each handshake response; this
// keeps the difference from the local clock and converts server moments into
// local ones, so a countdown is drawn against the server's idea of time rather
// than against however wrong this browser's clock happens to be.

/**
 * How far the server's clock runs ahead of this browser's, in milliseconds —
 * measured at the last handshake, zero until one lands. A module-level value
 * because there is exactly one server behind a tab: passing it through every
 * countdown would be threading a constant of the process.
 */
let serverOffsetMs = 0

/**
 * Measure the offset from the server's "now" carried by a handshake response.
 *
 * Called on EVERY handshake, not only the first: a reconnect is when a laptop
 * comes back from sleep with a clock that drifted, and it is the one moment the
 * browser can measure the difference again for free.
 *
 * @param serverTimeMs The server's own "now" in epoch milliseconds.
 */
export function applyServerTime(serverTimeMs: number): void {
  serverOffsetMs = serverTimeMs - Date.now()
}

/**
 * How far the server clock runs ahead of the local one, in milliseconds
 * (negative when the browser is ahead); `0` until a handshake lands.
 */
export function offsetMs(): number {
  return serverOffsetMs
}

/**
 * Convert a server moment into the local epoch-ms scale `Date.now()` speaks.
 *
 * Everything on the wire is a server moment; everything a view compares against
 * or counts down to is local. This is the one place the two meet, so a screen
 * never subtracts one scale from the other.
 *
 * @param serverMs The moment in server epoch milliseconds.
 */
export function toLocal(serverMs: number): number {
  return serverMs - serverOffsetMs
}
