// The identity confirmations a browser session has just been told of (HIL-1330).
//
// A confirmation is the SESSION's: proved in one tab, it opens the same
// operation in every tab of that browser for its lifetime. The server says so on
// this frame — to every tab of the session the moment a confirmation is written,
// and to one tab that connects later, on its handshake — and a confirmation step
// asking for one of the listed operations passes by itself.
//
// This is NOT state. The frame means "the server says, right now, that these are
// confirmed", and it is read only as it arrives: nobody is told when a
// confirmation expires, so the held value can be older than the confirmations it
// names. Never read it as current truth — subscribe to its change, and act only
// on a frame that arrives while you are listening. That is also why the server
// never sends an empty list: there is no held list for it to take anything away
// from.
//
// The name is byte-equal to the backend `HilosSignalConstants::HILOS_STEP_UP_CONFIRMED`.
import { z } from 'zod'

import { type HilosConnection } from '../connection/HilosConnection.js'
import { type ProjectSignal } from '../protocol/parseSignal.js'
import { createSignal, type ReadonlySignal } from '../state/signal.js'

/** Signal `type` of the frame (PHP `HilosSignalConstants::HILOS_STEP_UP_CONFIRMED`). */
export const SIGNAL_STEP_UP_CONFIRMED = 'hilos_step_up_confirmed'

/**
 * The frame: the operation keys the session has a live confirmation of, without
 * repeats and in ascending order. Never empty.
 */
export const stepUpConfirmedSchema = z.looseObject({
  operations: z.array(z.string()),
})

/** One frame of what the session has confirmed, as the server said it. */
export interface HilosStepUpConfirmed {
  /** Operation keys with a live confirmation when the frame was sent. */
  readonly operations: readonly string[]
}

const stepUpConfirmed = createSignal<HilosStepUpConfirmed | null>(null)

/**
 * The last confirmation frame of this browser session, or `null` before any.
 *
 * Subscribe to it; do not read it. Each frame is a new object, so the same list
 * told twice wakes a subscriber twice, and what is held may already have
 * expired on the server.
 */
export const hilosStepUpConfirmed: ReadonlySignal<HilosStepUpConfirmed | null> =
  stepUpConfirmed

/**
 * Route the session's confirmation frames into {@link hilosStepUpConfirmed}.
 *
 * Bound by `bootHilos` before the socket opens, so the handshake's frame is
 * never missed.
 *
 * @param connection The application's Hilos connection.
 * @returns Unsubscribe for the registered signal handler.
 */
export function bindStepUpConfirmed(connection: HilosConnection): () => void {
  return connection.on('projectSignal', (signal: ProjectSignal) => {
    if (signal.type !== SIGNAL_STEP_UP_CONFIRMED) {
      return
    }
    const data = signal.data as ReturnType<typeof stepUpConfirmedSchema.parse>
    stepUpConfirmed.set({ operations: [...data.operations] })
  })
}
