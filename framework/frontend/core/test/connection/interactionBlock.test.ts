import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  HilosConnection,
  type ConnectionState,
  type HilosConnectionEventMap,
} from '../../src/connection/HilosConnection.js'
import { Emitter } from '../../src/connection/events.js'
import {
  CONNECTION_INTERACTION_COPY,
  createConnectionInteractionState,
  HILOS_CONNECTION_STATUS_TEXT_ID,
  HILOS_INTERACTION_BLOCKED_ATTR,
  HILOS_INTERACTION_REGION_ATTR,
} from '../../src/connection/interactionBlock.js'
import { subscribeSignal } from '../../src/state/signal.js'

/** A real, unopened connection with a controlled snapshot and event source. */
function source(initial: ConnectionState) {
  let current = initial
  const connection = new HilosConnection({ url: 'ws://test/ws' })
  const events = new Emitter<HilosConnectionEventMap>()
  const readState = vi
    .spyOn(connection, 'state', 'get')
    .mockImplementation(() => current)
  const detached = vi.fn()
  const on = vi
    .spyOn(connection, 'on')
    .mockImplementation((event, listener) => {
      const unsubscribe = events.on(event, listener)

      return () => {
        detached()
        unsubscribe()
      }
    })

  return {
    connection,
    on,
    readState,
    detached,
    emit(state: ConnectionState): void {
      current = state
      events.emit('state', state)
    },
  }
}

afterEach(() => vi.restoreAllMocks())

describe('createConnectionInteractionState', () => {
  it.each([
    ['connected', false],
    ['connecting', true],
    ['reconnecting', true],
    ['disconnected', true],
  ] as const)('reads the initial %s snapshot', (snapshot, expected) => {
    const initial = source(snapshot)
    const state = createConnectionInteractionState(initial.connection)
    expect(state.unavailable.get()).toBe(expected)
    expect(state.blocked.get()).toBe(expected)
    expect(initial.on).toHaveBeenCalledOnce()
    expect(initial.on).toHaveBeenCalledWith('state', expect.any(Function))
    state.dispose()
  })

  it.each(['connecting', 'reconnecting', 'disconnected'] as const)(
    'publishes connected ↔ %s synchronously',
    (unavailable) => {
      const initial = source('connected')
      const state = createConnectionInteractionState(initial.connection)
      const availability = vi.fn()
      const blocking = vi.fn()
      const stopAvailability = subscribeSignal(state.unavailable, availability)
      const stopBlocking = subscribeSignal(state.blocked, blocking)

      initial.emit(unavailable)
      expect(state.unavailable.get()).toBe(true)
      expect(state.blocked.get()).toBe(true)
      expect(availability.mock.calls).toEqual([[true]])
      expect(blocking.mock.calls).toEqual([[true]])
      initial.emit('connected')
      expect(state.unavailable.get()).toBe(false)
      expect(state.blocked.get()).toBe(false)
      expect(availability.mock.calls).toEqual([[true], [false]])
      expect(blocking.mock.calls).toEqual([[true], [false]])

      stopAvailability()
      stopBlocking()
      state.dispose()
    },
  )

  it('keeps availability independent of the initial opt-out and live policy', () => {
    const initial = source('disconnected')
    const state = createConnectionInteractionState(initial.connection, true)
    expect(state.unavailable.get()).toBe(true)
    expect(state.blocked.get()).toBe(false)
    state.setAllowDisconnectedInteraction(false)
    expect(state.blocked.get()).toBe(true)
    state.setAllowDisconnectedInteraction(true)
    expect(state.unavailable.get()).toBe(true)
    expect(state.blocked.get()).toBe(false)
    initial.emit('connecting')
    initial.emit('reconnecting')
    expect(state.unavailable.get()).toBe(true)
    expect(state.blocked.get()).toBe(false)
    initial.emit('connected')
    state.setAllowDisconnectedInteraction(false)
    expect(state.unavailable.get()).toBe(false)
    expect(state.blocked.get()).toBe(false)
    initial.emit('disconnected')
    expect(state.blocked.get()).toBe(true)
    state.dispose()
  })

  it('detaches before synchronously reading and observing the replacement', () => {
    const old = source('connected')
    const next = source('reconnecting')
    const state = createConnectionInteractionState(old.connection)
    state.setConnection(next.connection)
    expect(old.detached).toHaveBeenCalledOnce()
    expect(old.detached.mock.invocationCallOrder[0]).toBeLessThan(
      next.readState.mock.invocationCallOrder[0]!,
    )
    expect(next.on).toHaveBeenCalledOnce()
    expect(state.unavailable.get()).toBe(true)
    expect(state.blocked.get()).toBe(true)

    old.emit('disconnected')
    old.emit('connected')
    expect(state.unavailable.get()).toBe(true)
    next.emit('connected')
    expect(state.unavailable.get()).toBe(false)
    expect(state.blocked.get()).toBe(false)
    state.dispose()
    expect(next.detached).toHaveBeenCalledOnce()
    expect(old.detached).toHaveBeenCalledOnce()
  })

  it('ignores a late old callback even when the same connection is bound again', () => {
    const old = source('connected')
    const next = source('disconnected')
    const state = createConnectionInteractionState(old.connection)
    const late = old.on.mock.calls[0]![1]
    state.setConnection(next.connection)
    late('connected')
    expect(state.unavailable.get()).toBe(true)
    expect(state.blocked.get()).toBe(true)
    state.setConnection(old.connection)
    late('disconnected')
    expect(state.unavailable.get()).toBe(false)
    expect(state.blocked.get()).toBe(false)
    old.emit('reconnecting')
    expect(state.blocked.get()).toBe(true)
    state.dispose()
  })

  it('disposes once and never publishes from late callbacks or setters', () => {
    const initial = source('disconnected')
    const next = source('connected')
    const state = createConnectionInteractionState(initial.connection)
    const late = initial.on.mock.calls[0]![1]
    const availability = vi.fn()
    const blocking = vi.fn()
    const stopAvailability = subscribeSignal(state.unavailable, availability)
    const stopBlocking = subscribeSignal(state.blocked, blocking)
    state.dispose()
    state.dispose()
    initial.emit('connected')
    late('connected')
    state.setAllowDisconnectedInteraction(true)
    state.setConnection(next.connection)
    expect(state.unavailable.get()).toBe(true)
    expect(state.blocked.get()).toBe(true)
    expect(availability).not.toHaveBeenCalled()
    expect(blocking).not.toHaveBeenCalled()
    expect(initial.detached).toHaveBeenCalledOnce()
    expect(next.on).not.toHaveBeenCalled()
    expect(next.readState).not.toHaveBeenCalled()
    stopAvailability()
    stopBlocking()
  })

  it('keeps the policy and cleanup local to each shell instance', () => {
    const initial = source('disconnected')
    const page = createConnectionInteractionState(initial.connection, true)
    const chrome = createConnectionInteractionState(initial.connection)
    expect(page.blocked.get()).toBe(false)
    expect(chrome.blocked.get()).toBe(true)
    page.dispose()
    initial.emit('connected')
    expect(page.unavailable.get()).toBe(true)
    expect(chrome.unavailable.get()).toBe(false)
    expect(chrome.blocked.get()).toBe(false)
    chrome.dispose()
  })

  it('declares the shared DOM markers and connection explanation literally', () => {
    expect(HILOS_CONNECTION_STATUS_TEXT_ID).toBe('hilos-connection-status-text')
    expect(HILOS_INTERACTION_BLOCKED_ATTR).toBe(
      'data-hilos-interaction-blocked',
    )
    expect(HILOS_INTERACTION_REGION_ATTR).toBe('data-hilos-interaction-region')
    expect(CONNECTION_INTERACTION_COPY.unavailable).toBe(
      'The connection is unavailable. Controls will work when it returns.',
    )
  })
})
