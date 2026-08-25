import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createHilosConnection } from '../../src/connection/createHilosConnection.js'
import {
  HilosConnection,
  type WebSocketLike,
} from '../../src/connection/HilosConnection.js'
import { PROTECTED_MODE_HINT_STORAGE_KEY } from '../../src/connection/maintenanceHint.js'
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

const VERIFYING = JSON.stringify({
  type: 'protected_mode',
  data: {
    active: true,
    operation: 'restore',
    title: 'Restoring a backup',
    message: 'Back shortly.',
    acceptsPass: true,
  },
})

/** A welcome frame carrying the freeze block a reconnect would come back to. */
function welcome(protectedMode: Record<string, unknown>): string {
  return JSON.stringify({
    type: 'handshake',
    data: { build: 'build-a', protectedMode },
  })
}

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

/** A store that answers like the browser's, backed by one map per test. */
function installStore(name: 'localStorage' | 'sessionStorage'): void {
  const entries = new Map<string, string>()
  Object.defineProperty(globalThis, name, {
    configurable: true,
    value: {
      getItem: (key: string) => entries.get(key) ?? null,
      setItem: (key: string, value: string) => void entries.set(key, value),
      removeItem: (key: string) => void entries.delete(key),
    },
  })
}

beforeEach(() => {
  vi.useFakeTimers()
  // The core runs where there is no browser at all, so the stores are optional by
  // design; these cases are about what happens when there ARE some — the pass in
  // sessionStorage, the maintenance hint in localStorage.
  installStore('sessionStorage')
  installStore('localStorage')
})

afterEach(() => {
  vi.useRealTimers()
  Reflect.deleteProperty(globalThis, 'sessionStorage')
  Reflect.deleteProperty(globalThis, 'localStorage')
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
    expect(connection.sendAction('tasks.add', {})).toBe(false)
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

    return { connection: bundle.connection, onProtectedModeLift, socket }
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

  it('stays quiet on the welcome that admits a verifier into an open window', () => {
    // Admission is decided on the 101 and reported by the welcome as "not locked
    // out" — word for word what a lift says. The window bit is what tells the two
    // apart: reloading here costs a round trip where sessionStorage works, and the
    // only way back inside where it is refused.
    const { connection, onProtectedModeLift, socket } = openBundle()
    socket.emit('message', { data: VERIFYING })
    connection.presentProtectedModePass('the-key')
    const admitted = MockWebSocket.last
    admitted.emit('open')

    admitted.emit('message', {
      data: welcome({ active: false, acceptsPass: true }),
    })

    expect(onProtectedModeLift).not.toHaveBeenCalled()
  })

  it('calls the lift handler when the window a verifier was admitted to ends', () => {
    const { connection, onProtectedModeLift, socket } = openBundle()
    socket.emit('message', { data: VERIFYING })
    connection.presentProtectedModePass('the-key')
    const admitted = MockWebSocket.last
    admitted.emit('open')
    admitted.emit('message', {
      data: welcome({ active: false, acceptsPass: true }),
    })

    admitted.emit('message', { data: LIFT })

    expect(onProtectedModeLift).toHaveBeenCalledTimes(1)
  })

  it('calls the lift handler when a verifier learns of the lift from a welcome', () => {
    // The socket was down when the mode lifted, so the only news of it is the
    // welcome of the socket that came back: not locked out AND no window left.
    const { connection, onProtectedModeLift, socket } = openBundle()
    socket.emit('message', { data: VERIFYING })
    connection.presentProtectedModePass('the-key')
    const admitted = MockWebSocket.last
    admitted.emit('open')
    admitted.emit('message', {
      data: welcome({ active: false, acceptsPass: true }),
    })

    admitted.emit('close')
    vi.runOnlyPendingTimers()
    MockWebSocket.last.emit('open')
    MockWebSocket.last.emit('message', { data: welcome({ active: false }) })

    expect(onProtectedModeLift).toHaveBeenCalledTimes(1)
  })
})

describe('a verifier presents a pass', () => {
  it('reports whether the window accepts one at all', () => {
    // The surface shows its code field off this bit and nothing else: the frozen
    // phases carry no window to be let into.
    const { connection, socket } = openConnection()

    socket.emit('message', { data: FREEZE })
    expect(connection.protectedMode.acceptsPass).toBe(false)

    socket.emit('message', { data: VERIFYING })
    expect(connection.protectedMode.acceptsPass).toBe(true)
  })

  it('goes back in with the key on the socket url', () => {
    // Not a frame: while the mode holds every outbound frame is refused, so the
    // only moment the question can be asked is the 101 of a fresh socket.
    const { connection, socket } = openConnection()
    socket.emit('message', { data: VERIFYING })

    connection.presentProtectedModePass('the-key')

    expect(MockWebSocket.last.url).toBe('ws://test/ws?hilosPass=the-key')
  })

  it('ignores a blank code instead of reconnecting for nothing', () => {
    const { connection, socket } = openConnection()
    socket.emit('message', { data: VERIFYING })
    const before = MockWebSocket.instances.length

    connection.presentProtectedModePass('   ')

    expect(MockWebSocket.instances).toHaveLength(before)
  })

  it('re-presents the key on a reconnect, so a blip does not throw the verifier out', () => {
    const { connection, socket } = openConnection()
    socket.emit('message', { data: VERIFYING })
    connection.presentProtectedModePass('the-key')
    const admitted = MockWebSocket.last
    admitted.emit('open')
    admitted.emit('message', {
      data: welcome({ active: false, acceptsPass: true }),
    })

    admitted.emit('close')
    vi.runOnlyPendingTimers()

    expect(MockWebSocket.last.url).toBe('ws://test/ws?hilosPass=the-key')
  })

  it('says the code was rejected when the reconnect comes back still locked out', () => {
    // A frozen node has no agent left to compose a refusal, so a wrong key is
    // answered by silence: the welcome simply locks this connection out again.
    const { connection, socket } = openConnection()
    socket.emit('message', { data: VERIFYING })
    connection.presentProtectedModePass('wrong-key')
    const retry = MockWebSocket.last
    retry.emit('open')

    retry.emit('message', {
      data: welcome({ active: true, operation: 'restore', acceptsPass: true }),
    })

    expect(connection.protectedMode.passRejected).toBe(true)
    expect(connection.protectedMode.acceptsPass).toBe(true)
  })

  it('says nothing of the sort when no code was presented', () => {
    const { connection, socket } = openConnection()

    socket.emit('message', {
      data: welcome({ active: true, operation: 'restore', acceptsPass: true }),
    })

    expect(connection.protectedMode.passRejected).toBe(false)
  })

  it('drops the key when the operator closes the window back', () => {
    // Closing back to a full freeze empties the pass list on the row, so the key
    // this tab holds opens nothing any more: re-presenting it would answer a
    // rejection to a verifier who typed nothing.
    const { connection, socket } = openConnection()
    socket.emit('message', { data: VERIFYING })
    connection.presentProtectedModePass('the-key')
    const admitted = MockWebSocket.last
    admitted.emit('open')

    admitted.emit('message', { data: FREEZE })
    admitted.emit('close')
    vi.runOnlyPendingTimers()

    expect(MockWebSocket.last.url).toBe('ws://test/ws')
  })

  it('drops a key the welcome says opens nothing, whoever closed the window', () => {
    // The tab was disconnected for the frame that ended the window, so the welcome
    // of the socket that came back is the first thing to say so: no window, no key.
    const { connection, socket } = openConnection()
    socket.emit('message', { data: VERIFYING })
    connection.presentProtectedModePass('the-key')
    const admitted = MockWebSocket.last
    admitted.emit('open')

    admitted.emit('message', { data: welcome({ active: false }) })
    admitted.emit('close')
    vi.runOnlyPendingTimers()

    expect(MockWebSocket.last.url).toBe('ws://test/ws')
  })

  it('drops the key when the mode lifts', () => {
    // The window is over and the key opens nothing; carrying it into the next
    // freeze would present a void pass and land on a rejection nobody asked for.
    const { connection, socket } = openConnection()
    socket.emit('message', { data: VERIFYING })
    connection.presentProtectedModePass('the-key')
    const admitted = MockWebSocket.last
    admitted.emit('open')

    admitted.emit('message', { data: LIFT })
    admitted.emit('close')
    vi.runOnlyPendingTimers()

    expect(MockWebSocket.last.url).toBe('ws://test/ws')
  })
})

describe('the first frame waits for the answer it is about to draw', () => {
  /** Open a connection on a browser that remembers maintenance on this node. */
  function openHinted() {
    globalThis.localStorage.setItem(PROTECTED_MODE_HINT_STORAGE_KEY, '1')
    MockWebSocket.instances = []
    const connection = new HilosConnection({
      url: 'ws://test/ws',
      webSocketFactory: (url) => new MockWebSocket(url),
      random: () => 1,
    })
    const releases: boolean[] = []
    connection.on('firstFrameHold', (held) => releases.push(held))
    connection.connect()
    MockWebSocket.last.emit('open')

    return { connection, releases, socket: MockWebSocket.last }
  }

  it('holds nothing on a browser that has never met maintenance here', () => {
    // The ordinary load, and it must stay free: no hint, no hold, no timer.
    const { connection } = openConnection()

    expect(connection.firstFrameHeld).toBe(false)
    vi.advanceTimersByTime(5000)
    expect(connection.firstFrameHeld).toBe(false)
  })

  it('holds the first frame on a browser that has', () => {
    const { connection } = openHinted()

    expect(connection.firstFrameHeld).toBe(true)
  })

  it('lets go on the welcome, with the freeze already readable', () => {
    const { connection, socket, releases } = openHinted()

    socket.emit('message', {
      data: welcome({
        active: true,
        operation: 'restore',
        title: 'Restoring a backup',
        message: 'Back shortly.',
      }),
    })

    expect(connection.firstFrameHeld).toBe(false)
    expect(releases).toEqual([false])
    expect(connection.protectedMode.active).toBe(true)
  })

  it('lets go on a welcome that reports no freeze at all', () => {
    // The common case of a stale hint: maintenance ended while no tab was open.
    // What it costs is this one welcome, not a maintenance screen.
    const { connection, socket } = openHinted()

    socket.emit('message', {
      data: welcome({
        active: false,
        operation: null,
        title: null,
        message: null,
      }),
    })

    expect(connection.firstFrameHeld).toBe(false)
    expect(connection.protectedMode.active).toBe(false)
  })

  it('lets go when the socket drops before any frame arrives', () => {
    const { connection, socket } = openHinted()

    socket.emit('close')

    expect(connection.firstFrameHeld).toBe(false)
  })

  it('lets go on the deadline when nothing answers at all', () => {
    const { connection, releases } = openHinted()

    vi.advanceTimersByTime(1500)

    expect(connection.firstFrameHeld).toBe(false)
    expect(releases).toEqual([false])
  })

  it('lets go once, whichever of the three gets there first', () => {
    const { socket, releases } = openHinted()

    socket.emit('message', {
      data: welcome({
        active: false,
        operation: null,
        title: null,
        message: null,
      }),
    })
    socket.emit('close')
    vi.advanceTimersByTime(5000)

    expect(releases).toEqual([false])
  })

  it('writes the hint down from the frame that announced the freeze', () => {
    const { socket } = openConnection()

    socket.emit('message', { data: FREEZE })

    expect(
      globalThis.localStorage.getItem(PROTECTED_MODE_HINT_STORAGE_KEY),
    ).not.toBeNull()
  })

  it('takes the hint back off when a frame says the node is fine', () => {
    const { socket } = openHinted()

    socket.emit('message', { data: LIFT })

    expect(
      globalThis.localStorage.getItem(PROTECTED_MODE_HINT_STORAGE_KEY),
    ).toBeNull()
  })

  it('keeps the hint for an admitted verifier, who is told the freeze is off', () => {
    // His `active: false` is about this connection alone; judged by it he would
    // clear the hint and then flash the shell on every reload he makes inside
    // the window — the one window where reloads are frequent.
    const { connection, socket } = openConnection()
    socket.emit('message', { data: VERIFYING })
    connection.presentProtectedModePass('the-key')
    const admitted = MockWebSocket.last
    admitted.emit('open')
    admitted.emit('message', {
      data: welcome({
        active: false,
        operation: 'restore',
        title: 'Restoring a backup',
        message: 'Back shortly.',
        acceptsPass: true,
      }),
    })

    expect(
      globalThis.localStorage.getItem(PROTECTED_MODE_HINT_STORAGE_KEY),
    ).not.toBeNull()
  })
})
