import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, render, screen } from '@testing-library/react'
import { HilosConnection } from '@hilos/core'
import type { WebSocketLike } from '@hilos/core'
import { useConnectionState } from '../src/useConnectionState.js'

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

function Probe({ connection }: { connection: HilosConnection }) {
  const state = useConnectionState(connection)
  return <span data-testid="conn-state">{state}</span>
}

function stateText(): string | null {
  return screen.getByTestId('conn-state').textContent
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
    cleanup()
  })

  it('mirrors the machine state through its transitions', () => {
    render(<Probe connection={connection} />)
    expect(stateText()).toBe('disconnected')

    act(() => {
      connection.connect()
    })
    expect(stateText()).toBe('connecting')

    act(() => {
      MockWebSocket.last.emit('open')
    })
    expect(stateText()).toBe('connected')

    act(() => {
      MockWebSocket.last.emit('close')
    })
    expect(stateText()).toBe('reconnecting')
  })

  it('reads the state current at mount time, not the initial one', () => {
    connection.connect()
    MockWebSocket.last.emit('open')

    render(<Probe connection={connection} />)
    expect(stateText()).toBe('connected')
  })

  it('releases the subscription on unmount', () => {
    const realOn = connection.on.bind(connection)
    let unsubscribed = false
    vi.spyOn(connection, 'on').mockImplementation(((event, listener) => {
      const off = realOn(event, listener)
      return () => {
        unsubscribed = true
        off()
      }
    }) as typeof connection.on)

    const { unmount } = render(<Probe connection={connection} />)
    unmount()
    expect(unsubscribed).toBe(true)
  })
})
