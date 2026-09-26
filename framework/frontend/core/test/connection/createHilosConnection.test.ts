import { describe, expect, it, vi } from 'vitest'
import { ActionErrorStore } from '../../src/connection/ActionErrorStore.js'
import { createHilosConnection } from '../../src/connection/createHilosConnection.js'
import {
  HilosConnection,
  type WebSocketLike,
} from '../../src/connection/HilosConnection.js'

/** Scripted WebSocket stand-in; the test drives open/message explicitly. */
class MockWebSocket implements WebSocketLike {
  static instances: MockWebSocket[] = []

  static get last(): MockWebSocket {
    const instance = MockWebSocket.instances.at(-1)
    if (instance === undefined) {
      throw new Error('No MockWebSocket has been constructed')
    }

    return instance
  }

  private readonly listeners = new Map<
    string,
    ((event: { data?: unknown }) => void)[]
  >()

  constructor(readonly url: string) {
    MockWebSocket.instances.push(this)
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

  emit(type: string, event: { data?: unknown } = {}): void {
    for (const listener of this.listeners.get(type) ?? []) {
      listener(event)
    }
  }
}

function openConnection(onBuildMismatch?: () => void) {
  MockWebSocket.instances.length = 0
  const bundle = createHilosConnection({
    url: 'ws://test/ws',
    webSocketFactory: (url) => new MockWebSocket(url),
    onBuildMismatch,
  })
  bundle.connection.connect()
  const socket = MockWebSocket.last
  socket.emit('open')

  return { ...bundle, socket }
}

describe('createHilosConnection', () => {
  it('returns the connection and an action-error store', () => {
    const { connection, actionErrors } = openConnection()

    expect(connection).toBeInstanceOf(HilosConnection)
    expect(actionErrors).toBeInstanceOf(ActionErrorStore)
  })

  it('merges the framework session, page, and upload schemas', () => {
    const { connection, socket } = openConnection()
    const projectTypes: string[] = []
    let unknownCount = 0
    connection.on('projectSignal', (signal) => projectTypes.push(signal.type))
    connection.on('unknownSignal', () => {
      unknownCount += 1
    })

    socket.emit('message', {
      data: JSON.stringify({
        type: 'handshake_response',
        data: { entities: {} },
      }),
    })
    socket.emit('message', {
      data: JSON.stringify({
        type: 'page_response',
        data: { page: 'main', payload: { data: {} } },
      }),
    })
    socket.emit('message', {
      data: JSON.stringify({
        type: 'hilos_upload_state',
        data: { clientUploadId: 'upload-1' },
      }),
    })

    expect(projectTypes).toEqual([
      'handshake_response',
      'page_response',
      'hilos_upload_state',
    ])
    expect(unknownCount).toBe(0)
  })

  it('invokes the build-mismatch handler on a stale welcome', () => {
    const onBuildMismatch = vi.fn()
    const { socket } = openConnection(onBuildMismatch)

    socket.emit('message', {
      data: JSON.stringify({ type: 'handshake', data: { build: 'a' } }),
    })
    socket.emit('message', {
      data: JSON.stringify({ type: 'handshake', data: { build: 'b' } }),
    })

    expect(onBuildMismatch).toHaveBeenCalledTimes(1)
  })
})

describe('createHilosConnection with no document', () => {
  it('builds, the way the prerender build imports it', () => {
    // This very test file runs with no browser at all, which is the environment
    // the check is about: a project's connection singleton is created at module
    // scope, and the server renderer that prerenders the public pages imports
    // that module through them. A bare read of `location` there took the whole
    // build down (HIL-839).
    expect(typeof location).toBe('undefined')

    const { connection } = createHilosConnection()

    expect(connection).toBeInstanceOf(HilosConnection)
  })
})
