// The session's toast stack on the wire: the frame that says what this browser
// is being shown, and the three answers a tab gives back (HIL-768).
//
// A toast of one's own action never comes through here — it is pushed into the
// store and lives and dies in the tab that pressed the button. What this module
// carries is the OTHER addressee: a card the server raised for the session, which
// the tabs of one browser have to agree about. Closing it in the second window
// takes it out of the first, and a countdown that ran out in one hides it in
// both — so neither decision is made here. The store answers, the server judges,
// and the next frame says what the stack is now.
//
// The frame carries the whole list rather than a change, which is what makes a
// reconnect, a second tab and an ordinary removal one and the same sentence.
import { z } from 'zod'
import { type HilosConnection } from '../connection/HilosConnection.js'
import { subscribeSignal, type Unsubscribe } from '../state/signal.js'
import {
  type HilosSessionToast,
  type HilosToastStore,
} from '../state/toasts.js'

/** Server→client frame carrying the session's whole stack (PHP `HILOS_SESSION_TOASTS`). */
export const SIGNAL_SESSION_TOASTS = 'hilos_session_toasts'

/** Client→server: the person closed one card (PHP `HILOS_TOAST_DISMISS`). */
export const TOAST_ACTION_DISMISS = 'hilos_toast_dismiss'

/** Client→server: this tab's countdown for one card finished (PHP `HILOS_TOAST_EXPIRED`). */
export const TOAST_ACTION_EXPIRED = 'hilos_toast_expired'

/** Client→server: the stack is (or is no longer) being read here (PHP `HILOS_TOAST_READING`). */
export const TOAST_ACTION_READING = 'hilos_toast_reading'

/**
 * One card as the server sends it. `severity` is validated against the four the
 * store draws, because a fifth is a card nobody wrote — and a frame carrying one
 * is a server defect, not something a person can cause.
 */
const sessionToastSchema = z.looseObject({
  key: z.string(),
  message: z.string(),
  severity: z.enum(['error', 'success', 'info', 'warning']),
  source: z.string(),
  destination: z.string(),
  repeats: z.number().int(),
})

/**
 * The frame: the whole stack, which may legitimately be empty.
 *
 * Exported so `SESSION_SIGNAL_SCHEMAS` can carry it into every connection's parse
 * boundary beside the handshake response, which is what keeps a project from
 * restating the pair.
 */
export const sessionToastsSchema = z.looseObject({
  toasts: z.array(sessionToastSchema),
})

/**
 * Wire a toast store to a connection: route the session's frames into it, and
 * send back what this tab has to answer. Register before the socket opens so the
 * first frame lands.
 *
 * The answers are driven off the store's three signals rather than off callbacks,
 * because each of them is a STATE the server has to end up agreeing with: a card
 * this tab closed, a card whose countdown finished here, and whether somebody is
 * reading. What has already been sent is remembered here and forgotten when the
 * store takes the key back, so an answer is sent once per card and again if the
 * same card is raised anew.
 *
 * Every one of them is said AGAIN on each connect, and that is not belt and
 * braces: the server counts an answer against the accept key that gave it, a
 * reconnect mints a new one, and the old key stops counting the moment it is no
 * longer live. A tab whose countdown finished before the socket dropped would
 * otherwise sit under a card nobody will ever take away. The same pass covers what
 * was said into a socket that was already gone — `sendAction()` on a closed
 * connection sends nothing and says so, and only what really left is remembered.
 *
 * @param connection The application's Hilos connection.
 * @param store The toast store to feed (usually `hilosToasts`).
 * @returns A function that stops the subscriptions.
 */
export function bindSessionToasts(
  connection: HilosConnection,
  store: HilosToastStore,
): Unsubscribe {
  connection.on('projectSignal', (signal) => {
    if (signal.type !== SIGNAL_SESSION_TOASTS) {
      return
    }
    const frame = signal.data as { toasts: readonly HilosSessionToast[] }
    store.syncSession(frame.toasts)
  })

  // Keys this socket has already answered about. A key the store takes back — the
  // card was removed, or the server counted it again — leaves this set too, so
  // the same card raised afresh is answered afresh.
  const dismissedSent = new Set<string>()
  const expiredSent = new Set<string>()

  /**
   * Send the action for every key that is new to this socket, and forget the ones
   * the store no longer carries.
   *
   * @param action The client action to send.
   * @param keys The keys the store now holds.
   * @param sent What has already been sent for that action.
   */
  function announce(
    action: string,
    keys: readonly string[],
    sent: Set<string>,
  ): void {
    for (const key of keys) {
      if (sent.has(key)) {
        continue
      }
      if (!connection.sendAction(action, { key })) {
        continue
      }
      sent.add(key)
    }
    for (const key of sent) {
      if (!keys.includes(key)) {
        sent.delete(key)
      }
    }
  }

  /**
   * Say everything this tab owes the server, as if none of it had been said.
   *
   * Run on each connect. The previous socket's answers are not the new socket's:
   * the server weighs them against the accept keys that are alive, and the key
   * that gave them is not one of those any more.
   */
  function announceAll(): void {
    dismissedSent.clear()
    expiredSent.clear()
    announce(
      TOAST_ACTION_DISMISS,
      store.dismissedSessionKeys.get(),
      dismissedSent,
    )
    announce(TOAST_ACTION_EXPIRED, store.expiredSessionKeys.get(), expiredSent)
    if (store.reading.get()) {
      connection.sendAction(TOAST_ACTION_READING, { reading: true })
    }
  }

  connection.on('state', (state) => {
    if (state === 'connected') {
      announceAll()
    }
  })

  const stops: readonly Unsubscribe[] = [
    subscribeSignal(store.dismissedSessionKeys, (keys) => {
      announce(TOAST_ACTION_DISMISS, keys, dismissedSent)
    }),
    subscribeSignal(store.expiredSessionKeys, (keys) => {
      announce(TOAST_ACTION_EXPIRED, keys, expiredSent)
    }),
    subscribeSignal(store.reading, (reading) => {
      connection.sendAction(TOAST_ACTION_READING, { reading })
    }),
  ]

  return () => {
    for (const stop of stops) {
      stop()
    }
  }
}
