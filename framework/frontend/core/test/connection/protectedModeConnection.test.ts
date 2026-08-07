import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createHilosConnection } from '../../src/connection/createHilosConnection.js'
import {
  HilosConnection,
  type WebSocketLike,
} from '../../src/connection/HilosConnection.js'
import { type ProtectedModeStatus } from '../../src/protocol/protectedMode.js'

/** Scripted WebSocket stand-in; the test drives open/message/close explicitly. */
class MockWebSocket implements WebSocketLike {
  static instances: MockWebSocket[] = []

  static get last(): MockWebSocket {
    const instance = MockWebSocket.instances.at(-1)
    if (instance === undefined) {
      throw new Error('No MockWebSocket has been constructed')
    }

    return instance
  }

  readonly sent: (string | ArrayBuffer | Blob)[] = []

  private readonly listeners = new Map<
    string,
    ((event: { data?: unknown }) => void)[]
  >()

  constructor(readonly url: string) {
    MockWebSocket.instances.push(this)
  }

  send(data: string | ArrayBuffer | Blob): void {
    this.sent.push(data)
  }

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

const FREEZE = JSON.stringify({
  type: 'protected_mode',
  data: {
    active: true,
    operation: 'restore',
    title: 'Restoring a backup',
    message: 'Back shortly.',
  },
})

const LIFT = JSON.stringify({
  type: 'protected_mode',
  data: { active: false, operation: null, title: null, message: null },
})

function openConnection() {
  MockWebSocket.instances = []
  const connection = new HilosConnection({
    url: 'ws://test/ws',
    webSocketFactory: (url) => new MockWebSocket(url),
    random: () => 1,
  })
  const changes: ProtectedModeStatus[] = []
  connection.on('protectedMode', (status) => changes.push(status))
  connection.connect()
  MockWebSocket.last.emit('open')

  return { connection, changes, socket: MockWebSocket.last }
}

beforeEach(() => {
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('the connection holds the freeze', () => {
  it('starts with no freeze known', () => {
    const { connection } = openConnection()

    expect(connection.protectedMode.active).toBe(false)
  })

  it('takes the freeze off the pushed frame and reports it', () => {
    const { connection, changes, socket } = openConnection()

    socket.emit('message', { data: FREEZE })

    expect(connection.protectedMode.title).toBe('Restoring a backup')
    expect(changes).toHaveLength(1)
  })

  it('stays quiet when a welcome re-announces the freeze already held', () => {
    // A reconnect mid-mode re-states what the client is already showing; only the
    // pushed frame carries a transition, so the welcome must not report one.
    const { changes, socket } = openConnection()
    socket.emit('message', { data: FREEZE })

    socket.emit('message', {
      data: JSON.stringify({
        type: 'handshake',
        data: {
          build: 'build-a',
          protectedMode: {
            active: true,
            operation: 'restore',
            title: 'Restoring a backup',
            message: 'Back shortly.',
          },
        },
      }),
    })

    expect(changes).toHaveLength(1)
  })

  it('takes the freeze off the welcome of a connection that arrives mid-mode', () => {
    const { connection } = openConnection()

    MockWebSocket.last.emit('message', {
      data: JSON.stringify({
        type: 'handshake',
        data: {
          build: 'build-a',
          protectedMode: { active: true, operation: 'restore' },
        },
      }),
    })

    expect(connection.protectedMode.active).toBe(true)
  })

  it('keeps showing the freeze across a socket drop', () => {
    // A blip during planned maintenance must not turn the maintenance surface
    // into an outage screen: the last known state outlives the socket.
    const { connection, socket } = openConnection()
    socket.emit('message', { data: FREEZE })

    socket.emit('close')

    expect(connection.state).toBe('reconnecting')
    expect(connection.protectedMode.active).toBe(true)
  })
})

describe('the connection sends nothing while the freeze holds', () => {
  it('refuses actions, group subscriptions and raw frames', () => {
    const { connection, socket } = openConnection()
    socket.emit('message', { data: FREEZE })
    socket.sent.length = 0

    expect(connection.send('anything')).toBe(false)
    expect(connection.sendAction('todo.add', {})).toBe(false)
    expect(connection.subscribeToGroup('room-1')).toBe(false)
    expect(connection.sendBinary(new ArrayBuffer(4))).toBe(false)
    expect(socket.sent).toEqual([])
  })

  it('sends again once the freeze lifts', () => {
    const { connection, socket } = openConnection()
    socket.emit('message', { data: FREEZE })
    socket.emit('message', { data: LIFT })

    expect(connection.send('anything')).toBe(true)
    expect(socket.sent).toEqual(['anything'])
  })
})

describe('lifting the mode reloads the document', () => {
  /** Bundle wired to a scripted socket, with the reload handler replaced by a spy. */
  function openBundle() {
    const onProtectedModeLift = vi.fn()
    MockWebSocket.instances = []
    const bundle = createHilosConnection({
      url: 'ws://test/ws',
      webSocketFactory: (url) => new MockWebSocket(url),
      onProtectedModeLift,
    })
    bundle.connection.connect()
    const socket = MockWebSocket.last
    socket.emit('open')

    return { onProtectedModeLift, socket }
  }

  it('calls the lift handler on the transition', () => {
    const { onProtectedModeLift, socket } = openBundle()

    socket.emit('message', { data: FREEZE })
    socket.emit('message', { data: LIFT })

    expect(onProtectedModeLift).toHaveBeenCalledTimes(1)
  })

  it('calls the lift handler for the initiator, which never saw the freeze', () => {
    // The initiator is excluded from the "mode on" push by its accept key and its
    // own welcome reports the mode off, so the lift frame carries exactly the state
    // it already holds. It is deliberately NOT excluded from that frame: after a
    // restore its data is as stale as everybody else's, and the frame means reload.
    const { onProtectedModeLift, socket } = openBundle()
    socket.emit('message', {
      data: JSON.stringify({
        type: 'handshake',
        data: { build: 'build-a', protectedMode: { active: false } },
      }),
    })

    socket.emit('message', { data: LIFT })

    expect(onProtectedModeLift).toHaveBeenCalledTimes(1)
  })

  it('stays quiet on the welcome of a daemon that is not frozen', () => {
    // Every welcome of an unfrozen daemon says active:false; that is the state the
    // client is already in, so it must not be read as "the mode just lifted" —
    // otherwise an ordinary page load reloads itself forever.
    const { onProtectedModeLift, socket } = openBundle()

    socket.emit('message', {
      data: JSON.stringify({
        type: 'handshake',
        data: { build: 'build-a', protectedMode: { active: false } },
      }),
    })

    expect(onProtectedModeLift).not.toHaveBeenCalled()
  })
})
