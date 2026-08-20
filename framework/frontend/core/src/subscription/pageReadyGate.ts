// The page-ready gate (HIL-607): the one latch a cold-loaded return route waits
// on before it dispatches. An auth return — /auth/magic, /auth/callback — is
// entered by a full browser load, so the app mounts the relay view in the same
// tick `bootHilos` calls `connect()`: the socket is not open yet, `send()` drops
// the frame rather than queueing it (a new socket is a fresh protocol exchange),
// and the dispatch rejects as `disconnected` before the server has heard of the
// click at all.
//
// What is waited on is the PAGE'S first answer, not the handshake. A relay's
// dispatch is an action, and an action is routed by the backend through the
// connection's page subscription — so the readiness that matters is the one the
// subscription itself reports. Gating on the handshake looked equivalent only
// because the page_subscribe happens to leave first (the latch resolves on a
// microtask while `releaseOnSession` runs synchronously); that is an ordering
// coincidence, and this gate does not rest on it.
//
// No timeout lives here. A gate that gave up on its own would turn a slow
// connection into a silent failure at a depth that cannot say what to do next;
// the relay screen that shows the spinner owns the backstop and the retry.

import { type HilosConnection } from '../connection/HilosConnection.js'
import {
  SIGNAL_TYPE_PAGE_RESPONSE,
  SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR,
} from '../protocol/constants.js'

/**
 * Whether a page subscription on this connection has answered at least once,
 * with a payload or with a refusal alike. Latched by {@link bindPageReady} and
 * read by {@link whenPageReady}.
 *
 * Module scope, because the two ends are two different callers — the boot
 * sequence latches, a relay view mounted later waits — and a per-closure copy
 * would leave each of them holding its own memory of an arrival only one saw.
 */
let pageReady = false

/** Resolvers parked by {@link whenPageReady} before the first answer landed. */
const pageReadyWaiters: Array<() => void> = []

/**
 * Latch the page-ready state from the page subscription's first answer, so a
 * cold-loaded relay route can hold its dispatch until the connection can carry
 * one. Register once at boot, before the socket opens, so the first answer is
 * never missed; the latch stays set across a later reconnect, whose re-subscribe
 * is a refresh of a page already answered rather than a fresh wait. A no-op for
 * every other signal. Returns an unsubscribe.
 *
 * A subscription ERROR latches too, and deliberately: it says the round trip
 * completed and the backend judged the page, which is exactly what a dispatch
 * needs to know. Waiting on a clean answer instead would hang a relay forever on
 * a page the visitor is not allowed to see — a worse outcome than dispatching
 * and being told no.
 *
 * The two signal names are the framework's own page-protocol constants, the same
 * ones {@link bindPageScope} routes into the page scope: this is that arrival
 * read for a second purpose, not a second name that happens to match.
 *
 * @param connection The application's Hilos connection.
 * @returns Unsubscribe for the registered signal handler.
 */
export function bindPageReady(connection: HilosConnection): () => void {
  return connection.on('projectSignal', (signal) => {
    if (
      signal.type !== SIGNAL_TYPE_PAGE_RESPONSE &&
      signal.type !== SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR
    ) {
      return
    }
    pageReady = true
    while (pageReadyWaiters.length > 0) {
      pageReadyWaiters.shift()?.()
    }
  })
}

/**
 * Resolve once a page subscription on this connection has answered. Resolves
 * immediately when one already has; otherwise parks until {@link bindPageReady}
 * latches the next answer. A connection that never answers leaves this pending
 * — the relay screen's own timeout is what keeps its spinner from wedging.
 *
 * @returns A promise that settles when the page is first answered.
 */
export function whenPageReady(): Promise<void> {
  if (pageReady) {
    return Promise.resolve()
  }

  return new Promise<void>((resolve) => {
    pageReadyWaiters.push(resolve)
  })
}
