// The latch the gate is built on is MODULE state (see pageReadyGate.ts) and it
// only ever goes one way: once a page has answered on this connection, it has.
// That is right for the product — a relay mounted later must not wait for a
// second answer nobody will send — and wrong for a spec file, where one test's
// arrival would silently become the next test's starting condition. So each test
// takes the module fresh: `vi.resetModules()` drops the registry and the dynamic
// import builds the gate again, unlatched.
import { describe, expect, it, vi } from 'vitest'

import { type HilosConnection } from '../../src/index.js'
import {
  SIGNAL_TYPE_PAGE_RESPONSE,
  SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR,
} from '../../src/protocol/constants.js'
import { type ProjectSignal } from '../../src/protocol/parseSignal.js'

/**
 * The gate module, built fresh so its latch starts down.
 *
 * @returns The two exports, from a module instance no other test has touched.
 */
async function freshGate(): Promise<
  typeof import('../../src/subscription/pageReadyGate.js')
> {
  vi.resetModules()

  return await import('../../src/subscription/pageReadyGate.js')
}

/**
 * A connection double replaying project signals into whatever the gate binds.
 * The gate touches `on('projectSignal')` and nothing else, so that is all this
 * implements before the cast to the full connection type.
 *
 * @returns The double, with an `emit` that replays one signal to every listener.
 */
function fakeConnection() {
  const listeners: Array<(signal: ProjectSignal) => void> = []

  return {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        listeners.push(listener as (signal: ProjectSignal) => void)
      }

      return () => {}
    },
    emit(type: string): void {
      const signal = {
        kind: 'project',
        type,
        data: {},
        envelope: {},
      } as unknown as ProjectSignal
      for (const listener of listeners) {
        listener(signal)
      }
    },
  }
}

/**
 * Whether a promise has settled once the microtask queue drains — the question
 * every "is it still holding?" assertion below actually asks.
 *
 * @param promise The promise to probe without awaiting it.
 * @returns True when it has already resolved, false while it is still pending.
 */
async function isSettled(promise: Promise<void>): Promise<boolean> {
  let settled = false
  void promise.then(() => {
    settled = true
  })
  await Promise.resolve()
  await Promise.resolve()

  return settled
}

describe('pageReadyGate', () => {
  it('holds a waiter while no page has answered', async () => {
    const { bindPageReady, whenPageReady } = await freshGate()
    const connection = fakeConnection()
    bindPageReady(connection as unknown as HilosConnection)

    expect(await isSettled(whenPageReady())).toBe(false)
  })

  it('keeps holding through a signal that is not a page answer', async () => {
    const { bindPageReady, whenPageReady } = await freshGate()
    const connection = fakeConnection()
    bindPageReady(connection as unknown as HilosConnection)
    const waiting = whenPageReady()

    connection.emit('handshake_response')

    expect(await isSettled(waiting)).toBe(false)
  })

  it('releases every parked waiter on the first page response', async () => {
    const { bindPageReady, whenPageReady } = await freshGate()
    const connection = fakeConnection()
    bindPageReady(connection as unknown as HilosConnection)
    const first = whenPageReady()
    const second = whenPageReady()

    connection.emit(SIGNAL_TYPE_PAGE_RESPONSE)

    expect(await isSettled(first)).toBe(true)
    expect(await isSettled(second)).toBe(true)
  })

  it('resolves a waiter that arrives after the page already answered', async () => {
    const { bindPageReady, whenPageReady } = await freshGate()
    const connection = fakeConnection()
    bindPageReady(connection as unknown as HilosConnection)

    connection.emit(SIGNAL_TYPE_PAGE_RESPONSE)

    // The relay view mounts on a connection that has long since settled; it must
    // not wait for a second answer nobody has a reason to send.
    expect(await isSettled(whenPageReady())).toBe(true)
  })

  it('releases on a subscription error, so a refused page cannot wedge a relay', async () => {
    const { bindPageReady, whenPageReady } = await freshGate()
    const connection = fakeConnection()
    bindPageReady(connection as unknown as HilosConnection)
    const waiting = whenPageReady()

    connection.emit(SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR)

    expect(await isSettled(waiting)).toBe(true)
  })

  it('binds on the connection it is given', async () => {
    const { bindPageReady } = await freshGate()
    const connection = {
      on: vi.fn().mockReturnValue(() => undefined),
    } as unknown as HilosConnection

    bindPageReady(connection)

    expect(connection.on).toHaveBeenCalledWith(
      'projectSignal',
      expect.any(Function),
    )
  })
})
