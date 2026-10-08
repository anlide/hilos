import { describe, expect, it, vi } from 'vitest'

import {
  bindStepUpConfirmed,
  hilosStepUpConfirmed,
  SIGNAL_STEP_UP_CONFIRMED,
  stepUpConfirmedSchema,
} from '../../src/auth/stepUpConfirmed.js'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import { SESSION_SIGNAL_SCHEMAS } from '../../src/session/sessionScope.js'
import { subscribeSignal } from '../../src/state/signal.js'

/** Bind the held frame to a fake connection and return what pushes a frame into it. */
function bound(): (operations: string[]) => void {
  const listeners: ((signal: { type: string; data: unknown }) => void)[] = []
  const connection = {
    on: (_event: string, listener: (signal: never) => void) => {
      listeners.push(
        listener as (signal: { type: string; data: unknown }) => void,
      )

      return () => undefined
    },
  } as unknown as HilosConnection
  bindStepUpConfirmed(connection)

  return (operations) => {
    for (const listener of listeners) {
      listener({
        type: SIGNAL_STEP_UP_CONFIRMED,
        data: stepUpConfirmedSchema.parse({ operations }),
      })
    }
  }
}

describe('step-up confirmed frame', () => {
  it('names the frame as the backend does', () => {
    // Byte-equal to the PHP constant: the wire is where the two meet.
    expect(SIGNAL_STEP_UP_CONFIRMED).toBe('hilos_step_up_confirmed')
  })

  it('holds what the server said last', () => {
    const tell = bound()
    tell(['change_email', 'export_data'])

    expect(hilosStepUpConfirmed.get()).toEqual({
      operations: ['change_email', 'export_data'],
    })
  })

  it('wakes a listener on every frame, even one repeating the list', () => {
    const tell = bound()
    const heard = vi.fn()
    const stop = subscribeSignal(hilosStepUpConfirmed, heard)

    tell(['change_email'])
    const first = hilosStepUpConfirmed.get()
    tell(['change_email'])

    // A frame is "the server says so now", read on arrival: the same list told
    // again is a new arrival, so it is a new object.
    expect(heard).toHaveBeenCalledTimes(2)
    expect(hilosStepUpConfirmed.get()).not.toBe(first)
    stop()
  })

  it('is merged into every connection rather than one project at a time', () => {
    expect(SESSION_SIGNAL_SCHEMAS[SIGNAL_STEP_UP_CONFIRMED]).toBe(
      stepUpConfirmedSchema,
    )
  })
})
