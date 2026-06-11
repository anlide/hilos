import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import {
  HilosConnection,
  type ConnectionState,
  type WebSocketLike,
} from './HilosConnection.js'
import { type ProjectSignalSchemas } from '../protocol/parseSignal.js'

/**
 * Scripted stand-in for the browser WebSocket. Nothing fires on its own —
 * tests drive open/message/close/error explicitly, mirroring how the real
 * socket calls back asynchronously.
 */
class MockWebSocket implements WebSocketLike {
  static instances: MockWebSocket[] = []

  readonly url: string
  readonly sent: string[] = []
  closeCalls = 0

  private readonly listeners = new Map<
    string,
    ((event: { data?: unknown }) => void)[]
  >()

  constructor(url: string) {
    this.url = url
    MockWebSocket.instances.push(this)
  }

  static get last(): MockWebSocket {
    const instance = MockWebSocket.instances.at(-1)
    if (instance === undefined) {
      throw new Error('No MockWebSocket has been constructed')
    }
    return instance
  }

  send(data: string): void {
    this.sent.push(data)
  }

  close(): void {
    this.closeCalls += 1
  }

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

  open(): void {
    this.emit('open')
  }

  message(data: string): void {
    this.emit('message', { data })
  }

  drop(): void {
    this.emit('close')
  }

  fail(): void {
    this.emit('error')
  }
}

const WELCOME_A = '{"type":"handshake","data":{"build":"build-a"}}'
const WELCOME_B = '{"type":"handshake","data":{"build":"build-b"}}'

function createConnection(
  overrides: {
    expectedBuild?: string
    projectSchemas?: ProjectSignalSchemas
  } = {},
) {
  const connection = new HilosConnection({
    url: 'ws://test/ws',
    webSocketFactory: (url) => new MockWebSocket(url),
    random: () => 1,
    ...overrides,
  })
  const states: ConnectionState[] = []
  connection.on('state', (state) => states.push(state))
  return { connection, states }
}

beforeEach(() => {
  vi.useFakeTimers()
  MockWebSocket.instances = []
})

afterEach(() => {
  vi.useRealTimers()
})

describe('connection machine', () => {
  it('starts disconnected and walks connecting → connected', () => {
    const { connection, states } = createConnection()
    expect(connection.state).toBe('disconnected')

    connection.connect()
    expect(connection.state).toBe('connecting')
    expect(MockWebSocket.last.url).toBe('ws://test/ws')

    MockWebSocket.last.open()
    expect(connection.state).toBe('connected')
    expect(states).toEqual(['connecting', 'connected'])
  })

  it('ignores connect() unless disconnected', () => {
    const { connection } = createConnection()
    connection.connect()
    connection.connect()
    expect(MockWebSocket.instances).toHaveLength(1)
  })

  it('goes to reconnecting on a drop and retries with backoff', () => {
    const { connection, states } = createConnection()
    connection.connect()
    MockWebSocket.last.open()

    MockWebSocket.last.drop()
    expect(connection.state).toBe('reconnecting')

    // random() => 1 makes the first retry exactly baseDelayMs.
    vi.advanceTimersByTime(999)
    expect(MockWebSocket.instances).toHaveLength(1)
    vi.advanceTimersByTime(1)
    expect(MockWebSocket.instances).toHaveLength(2)

    MockWebSocket.last.open()
    expect(connection.state).toBe('connected')
    expect(states).toEqual([
      'connecting',
      'connected',
      'reconnecting',
      'connected',
    ])
  })

  it('keeps retrying with growing delays while attempts fail', () => {
    const { connection } = createConnection()
    connection.connect()
    MockWebSocket.last.open()
    MockWebSocket.last.drop()

    vi.advanceTimersByTime(1000) // attempt 0
    MockWebSocket.last.fail()
    vi.advanceTimersByTime(1999) // attempt 1 takes 2000
    expect(MockWebSocket.instances).toHaveLength(2)
    vi.advanceTimersByTime(1)
    expect(MockWebSocket.instances).toHaveLength(3)
  })

  it('resets the backoff after a successful connect', () => {
    const { connection } = createConnection()
    connection.connect()
    MockWebSocket.last.open()
    MockWebSocket.last.drop()

    vi.advanceTimersByTime(1000)
    MockWebSocket.last.open() // success resets attempts
    MockWebSocket.last.drop()

    vi.advanceTimersByTime(1000) // first retry delay is base again
    expect(MockWebSocket.instances).toHaveLength(3)
  })

  it('treats a failed first connect as reconnecting, not disconnected', () => {
    const { connection } = createConnection()
    connection.connect()
    MockWebSocket.last.fail()
    expect(connection.state).toBe('reconnecting')
  })

  it('handles the error+close double-fire of one socket as a single drop', () => {
    const { connection } = createConnection()
    connection.connect()
    MockWebSocket.last.open()

    const socket = MockWebSocket.last
    socket.fail()
    socket.drop()

    vi.advanceTimersByTime(1000)
    expect(MockWebSocket.instances).toHaveLength(2)
    vi.runOnlyPendingTimers()
    expect(MockWebSocket.instances).toHaveLength(2)
  })

  it('ignores events from a replaced socket', () => {
    const { connection } = createConnection()
    connection.connect()
    const first = MockWebSocket.last
    first.open()
    first.drop()
    vi.advanceTimersByTime(1000)

    first.open() // stale socket coming back must not flip the state
    expect(connection.state).toBe('reconnecting')
  })

  it('close() cancels reconnect and ends in disconnected', () => {
    const { connection, states } = createConnection()
    connection.connect()
    MockWebSocket.last.open()
    MockWebSocket.last.drop()

    connection.close()
    expect(connection.state).toBe('disconnected')

    vi.runOnlyPendingTimers()
    expect(MockWebSocket.instances).toHaveLength(1)
    expect(states).toEqual([
      'connecting',
      'connected',
      'reconnecting',
      'disconnected',
    ])
  })

  it('close() closes a live socket and connect() starts fresh afterwards', () => {
    const { connection } = createConnection()
    connection.connect()
    const socket = MockWebSocket.last
    socket.open()

    connection.close()
    expect(socket.closeCalls).toBe(1)

    connection.connect()
    expect(connection.state).toBe('connecting')
    expect(MockWebSocket.instances).toHaveLength(2)
  })
})

describe('keepalive', () => {
  it('pings every interval while connected and stops on drop', () => {
    const { connection } = createConnection()
    connection.connect()
    const socket = MockWebSocket.last
    socket.open()

    vi.advanceTimersByTime(40000)
    expect(socket.sent).toEqual(['ping'])
    vi.advanceTimersByTime(40000)
    expect(socket.sent).toEqual(['ping', 'ping'])

    socket.drop()
    vi.advanceTimersByTime(120000)
    expect(socket.sent).toEqual(['ping', 'ping'])
  })

  it('stops pinging after close()', () => {
    const { connection } = createConnection()
    connection.connect()
    const socket = MockWebSocket.last
    socket.open()

    connection.close()
    vi.advanceTimersByTime(120000)
    expect(socket.sent).toEqual([])
  })
})

describe('signal routing', () => {
  it('emits handshake and signal for the welcome', () => {
    const { connection } = createConnection()
    const handshakes: string[] = []
    const signals: string[] = []
    connection.on('handshake', (signal) => handshakes.push(signal.build))
    connection.on('signal', (signal) => signals.push(signal.kind))

    connection.connect()
    MockWebSocket.last.open()
    MockWebSocket.last.message(WELCOME_A)

    expect(handshakes).toEqual(['build-a'])
    expect(signals).toEqual(['handshake'])
  })

  it('emits projectSignal for a schema-validated project type', () => {
    const { connection } = createConnection({
      projectSchemas: { greet: z.looseObject({ who: z.string() }) },
    })
    const received: { type: string; data: unknown }[] = []
    connection.on('projectSignal', (signal) =>
      received.push({ type: signal.type, data: signal.data }),
    )

    connection.connect()
    MockWebSocket.last.open()
    MockWebSocket.last.message('{"type":"greet","data":{"who":"hilos"}}')

    expect(received).toEqual([{ type: 'greet', data: { who: 'hilos' } }])
  })

  it('emits unknownSignal for unknown types and stays connected', () => {
    const { connection } = createConnection()
    const unknown: string[] = []
    connection.on('unknownSignal', (signal) => unknown.push(signal.type))

    connection.connect()
    MockWebSocket.last.open()
    MockWebSocket.last.message(
      '{"type":"handshake_response","data":{"selfId":1}}',
    )

    expect(unknown).toEqual(['handshake_response'])
    expect(connection.state).toBe('connected')
  })

  it('reports parse failures without dropping the connection', () => {
    const { connection } = createConnection()
    const failures: string[] = []
    connection.on('parseFailure', (failure) => failures.push(failure.kind))

    connection.connect()
    MockWebSocket.last.open()
    MockWebSocket.last.message('{broken')

    expect(failures).toEqual(['malformed-json'])
    expect(connection.state).toBe('connected')
  })
})

describe('build-version check', () => {
  it('latches the first welcome build and flags a different one on reconnect', () => {
    const { connection } = createConnection()
    const mismatches: { expected: string; received: string }[] = []
    connection.on('buildMismatch', (mismatch) => mismatches.push(mismatch))

    connection.connect()
    MockWebSocket.last.open()
    MockWebSocket.last.message(WELCOME_A)
    expect(mismatches).toEqual([])

    MockWebSocket.last.drop()
    vi.advanceTimersByTime(1000)
    MockWebSocket.last.open()
    MockWebSocket.last.message(WELCOME_B)

    expect(mismatches).toEqual([{ expected: 'build-a', received: 'build-b' }])
  })

  it('compares against expectedBuild when configured', () => {
    const { connection } = createConnection({ expectedBuild: 'build-x' })
    const mismatches: { expected: string; received: string }[] = []
    connection.on('buildMismatch', (mismatch) => mismatches.push(mismatch))

    connection.connect()
    MockWebSocket.last.open()
    MockWebSocket.last.message(WELCOME_A)

    expect(mismatches).toEqual([{ expected: 'build-x', received: 'build-a' }])
  })

  it('unsubscribe stops a listener', () => {
    const { connection } = createConnection()
    const builds: string[] = []
    const off = connection.on('handshake', (signal) =>
      builds.push(signal.build),
    )

    connection.connect()
    MockWebSocket.last.open()
    MockWebSocket.last.message(WELCOME_A)
    off()
    MockWebSocket.last.message(WELCOME_A)

    expect(builds).toEqual(['build-a'])
  })
})
