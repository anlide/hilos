import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { effectScope } from 'vue'
import { HilosConnection } from '@hilos/core'
import type { WebSocketLike } from '@hilos/core'
import { useConnectionState } from './useConnectionState.js'

// Scripted stand-in for the browser WebSocket — the adapter tests only drive
// the open/close transitions. Kept inside the test file deliberately: the
// build ships only src modules, never test doubles.
class MockWebSocket implements WebSocketLike {
  static instances: MockWebSocket[] = []

  private readonly listeners = new Map<
    string,
    ((event: { data?: unknown }) => void)[]
  >()

  constructor() {
    MockWebSocket.instances.push(this)
  }

  static get last(): MockWebSocket {
    const instance = MockWebSocket.instances.at(-1)
    if (instance === undefined) {
      throw new Error('No MockWebSocket has been constructed')
    }
    return instance
  }

  send(): void {}

  close(): void {}

  addEventListener(
    type: string,
    listener: (event: { data?: unknown }) => void,
  ): void {
    const list = this.listeners.get(type) ?? []
    list.push(listener)
    this.listeners.set(type, list)
  }

  emit(type: string): void {
    for (const listener of this.listeners.get(type) ?? []) {
      listener({})
    }
  }
}

describe('useConnectionState', () => {
  let connection: HilosConnection

  beforeEach(() => {
    MockWebSocket.instances = []
    connection = new HilosConnection({
      url: 'ws://test/ws',
      webSocketFactory: () => new MockWebSocket(),
    })
  })

  afterEach(() => {
    // Cancels any reconnect timer the machine scheduled during the test.
    connection.close()
  })

  it('mirrors the machine state through its transitions', () => {
    const scope = effectScope()
    const state = scope.run(() => useConnectionState(connection))
    expect(state?.value).toBe('disconnected')

    connection.connect()
    expect(state?.value).toBe('connecting')

    MockWebSocket.last.emit('open')
    expect(state?.value).toBe('connected')

    MockWebSocket.last.emit('close')
    expect(state?.value).toBe('reconnecting')
    scope.stop()
  })

  it('reads the state current at call time, not the initial one', () => {
    connection.connect()
    MockWebSocket.last.emit('open')

    const scope = effectScope()
    const state = scope.run(() => useConnectionState(connection))
    expect(state?.value).toBe('connected')
    scope.stop()
  })

  it('releases the subscription when the calling scope is disposed', () => {
    const scope = effectScope()
    const state = scope.run(() => useConnectionState(connection))
    scope.stop()

    connection.connect()
    MockWebSocket.last.emit('open')
    expect(state?.value).toBe('disconnected')
  })
})
