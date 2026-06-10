import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { Injector, runInInjectionContext } from '@angular/core'
import { HilosConnection } from '@hilos/core'
import type { WebSocketLike } from '@hilos/core'
import { connectionStateSignal } from './connectionStateSignal.js'

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

/** Injector.create() returns an R3Injector; destroy() is its runtime API. */
type DestroyableInjector = Injector & { destroy(): void }

function createInjector(): DestroyableInjector {
  return Injector.create({ providers: [] }) as DestroyableInjector
}

describe('connectionStateSignal', () => {
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
    const injector = createInjector()
    const state = connectionStateSignal(connection, { injector })
    expect(state()).toBe('disconnected')

    connection.connect()
    expect(state()).toBe('connecting')

    MockWebSocket.last.emit('open')
    expect(state()).toBe('connected')

    MockWebSocket.last.emit('close')
    expect(state()).toBe('reconnecting')
  })

  it('reads the state current at call time, not the initial one', () => {
    connection.connect()
    MockWebSocket.last.emit('open')

    const state = connectionStateSignal(connection, {
      injector: createInjector(),
    })
    expect(state()).toBe('connected')
  })

  it('resolves the injector from the ambient injection context', () => {
    const state = runInInjectionContext(createInjector(), () =>
      connectionStateSignal(connection),
    )

    connection.connect()
    expect(state()).toBe('connecting')
  })

  it('throws outside an injection context when no injector is given', () => {
    expect(() => connectionStateSignal(connection)).toThrowError(
      /injection context/,
    )
  })

  it('releases the subscription when the owning injector is destroyed', () => {
    const injector = createInjector()
    const state = connectionStateSignal(connection, { injector })
    injector.destroy()

    connection.connect()
    MockWebSocket.last.emit('open')
    expect(state()).toBe('disconnected')
  })
})
