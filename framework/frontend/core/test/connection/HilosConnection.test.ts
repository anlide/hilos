import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import {
  HilosConnection,
  type ConnectionState,
  type WebSocketLike,
} from '../../src/connection/HilosConnection.js'
import { type ProjectSignalSchemas } from '../../src/protocol/parseSignal.js'

/**
 * Scripted stand-in for the browser WebSocket. Nothing fires on its own —
 * tests drive open/message/close/error explicitly, mirroring how the real
 * socket calls back asynchronously.
 */
class MockWebSocket implements WebSocketLike {
  static instances: MockWebSocket[] = []

  readonly url: string
  readonly sent: (string | ArrayBuffer | Blob)[] = []
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

  send(data: string | ArrayBuffer | Blob): void {
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

  it('marks the repair as dragging once the pauses reach the ceiling', () => {
    const { connection } = createConnection()
    const dragging: boolean[] = []
    connection.on('reconnectDragging', (value) => dragging.push(value))

    connection.connect()
    MockWebSocket.last.open()
    MockWebSocket.last.drop()
    expect(connection.reconnectDragging).toBe(false)

    // random() => 1 makes every pause its exact cap: 1000, 2000, 4000, 8000,
    // 16000, and then the ceiling. Five failures still look like a hiccup.
    for (const delay of [1000, 2000, 4000, 8000]) {
      vi.advanceTimersByTime(delay)
      MockWebSocket.last.fail()
      expect(connection.reconnectDragging).toBe(false)
    }

    vi.advanceTimersByTime(16000)
    MockWebSocket.last.fail()
    expect(connection.reconnectDragging).toBe(true)
    expect(dragging).toEqual([true])

    // The retries go on forever; the announcement does not repeat with them.
    vi.advanceTimersByTime(30000)
    MockWebSocket.last.fail()
    expect(dragging).toEqual([true])

    vi.advanceTimersByTime(30000)
    MockWebSocket.last.open()
    expect(connection.reconnectDragging).toBe(false)
    expect(dragging).toEqual([true, false])
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

describe('action send', () => {
  it('frames an action as {type:action, action, data} while connected', () => {
    const { connection } = createConnection()
    connection.connect()
    const socket = MockWebSocket.last
    socket.open()

    expect(connection.sendAction('message', { content: 'hi' })).toBe(true)
    expect(socket.sent).toEqual([
      '{"type":"action","action":"message","data":{"content":"hi"}}',
    ])
  })

  it('sends nothing unless connected', () => {
    const { connection } = createConnection()
    connection.connect()
    const socket = MockWebSocket.last

    expect(connection.sendAction('message', { content: 'hi' })).toBe(false)
    expect(socket.sent).toEqual([])
  })
})

describe('table viewport send', () => {
  it('frames a table viewport with its filter and order while connected', () => {
    const { connection } = createConnection()
    connection.connect()
    const socket = MockWebSocket.last
    socket.open()

    const sent = connection.sendTableViewport('hilos_settings', 'settings', {
      filter: { search: 'theme' },
      sort: [{ field: 'key', direction: 'asc' }],
      limit: 10,
      anchor: { key: 'theme.dark' },
      anchorDirection: 'before',
      pageIndex: null,
    })

    expect(sent).toBe(true)
    expect(JSON.parse(socket.sent.at(-1) as string)).toEqual({
      type: 'table_viewport',
      page: 'hilos_settings',
      tableKey: 'settings',
      limit: 10,
      anchor: { key: 'theme.dark' },
      anchorDirection: 'before',
      filter: { search: 'theme' },
      sort: [{ field: 'key', direction: 'asc' }],
    })
  })

  it('frames the options of a table filter to count while connected', () => {
    const { connection } = createConnection()
    connection.connect()
    const socket = MockWebSocket.last
    socket.open()

    const sent = connection.sendTableFacets('hilos_deliveries', 'deliveries', {
      channel: ['email', 'sms'],
    })

    expect(sent).toBe(true)
    expect(JSON.parse(socket.sent.at(-1) as string)).toEqual({
      type: 'table_facets',
      page: 'hilos_deliveries',
      tableKey: 'deliveries',
      facets: { channel: ['email', 'sms'] },
    })
  })

  it('declares the fields a table draws in a frame of their own', () => {
    const { connection } = createConnection()
    connection.connect()
    const socket = MockWebSocket.last
    socket.open()

    const sent = connection.sendTableRendered('hilos_users', 'users', [
      'name',
      'presence',
    ])

    expect(sent).toBe(true)
    expect(JSON.parse(socket.sent.at(-1) as string)).toEqual({
      type: 'table_rendered',
      page: 'hilos_users',
      tableKey: 'users',
      rendered: ['name', 'presence'],
    })
  })

  it('an order of more than one column rides as the list of its components', () => {
    const { connection } = createConnection()
    connection.connect()
    const socket = MockWebSocket.last
    socket.open()

    connection.sendTableViewport('p', 't', {
      filter: {},
      sort: [
        { field: 'channel', direction: 'desc' },
        { field: 'createdAt', direction: 'desc' },
      ],
      limit: 10,
      anchor: null,
      anchorDirection: 'after',
      pageIndex: null,
    })

    expect(JSON.parse(socket.sent.at(-1) as string).sort).toEqual([
      { field: 'channel', direction: 'desc' },
      { field: 'createdAt', direction: 'desc' },
    ])
  })

  it('a jump travels as a page index and nothing else', () => {
    const { connection } = createConnection()
    connection.connect()
    const socket = MockWebSocket.last
    socket.open()

    connection.sendTableViewport('p', 't', {
      filter: {},
      sort: null,
      limit: 10,
      anchor: { id: 7 },
      anchorDirection: 'after',
      pageIndex: 3,
    })

    // The server refuses a frame addressed both ways, so the anchor a jump leaves
    // behind stays off the wire rather than riding along beside the page number.
    expect(JSON.parse(socket.sent.at(-1) as string)).toEqual({
      type: 'table_viewport',
      page: 'p',
      tableKey: 't',
      limit: 10,
      pageIndex: 3,
    })
  })

  it('omits an empty filter and an order with nothing in it', () => {
    const { connection } = createConnection()
    connection.connect()
    const socket = MockWebSocket.last
    socket.open()

    connection.sendTableViewport('p', 't', {
      filter: {},
      sort: null,
      limit: 10,
      anchor: null,
      anchorDirection: 'after',
      pageIndex: null,
    })

    expect(JSON.parse(socket.sent.at(-1) as string)).toEqual({
      type: 'table_viewport',
      page: 'p',
      tableKey: 't',
      limit: 10,
      anchor: null,
      anchorDirection: 'after',
    })
  })

  it('carries the drawn fields when the table declares them, and omits an empty list', () => {
    const { connection } = createConnection()
    connection.connect()
    const socket = MockWebSocket.last
    socket.open()

    connection.sendTableViewport('p', 't', {
      filter: {},
      sort: null,
      limit: 10,
      anchor: null,
      anchorDirection: 'after',
      pageIndex: null,
      rendered: ['name', 'presence'],
    })
    expect(JSON.parse(socket.sent.at(-1) as string).rendered).toEqual([
      'name',
      'presence',
    ])

    connection.sendTableViewport('p', 't', {
      filter: {},
      sort: null,
      limit: 10,
      anchor: null,
      anchorDirection: 'after',
      pageIndex: null,
      rendered: [],
    })
    // No list and an empty one mean the same to the server — compare the whole row — and
    // the frame says it by leaving the key out, as it does for the filter and the order.
    expect(JSON.parse(socket.sent.at(-1) as string)).not.toHaveProperty(
      'rendered',
    )
  })

  it('omits an order that is an empty list, the same as a null one', () => {
    const { connection } = createConnection()
    connection.connect()
    const socket = MockWebSocket.last
    socket.open()

    connection.sendTableViewport('p', 't', {
      filter: {},
      sort: [],
      limit: 10,
      anchor: null,
      anchorDirection: 'after',
      pageIndex: null,
    })

    // An empty list is no order, and the server reads it as one — but a frame that
    // never carries the key says so without asking the other side to notice.
    expect(JSON.parse(socket.sent.at(-1) as string)).not.toHaveProperty('sort')
  })

  it('sends nothing unless connected', () => {
    const { connection } = createConnection()
    connection.connect()
    const socket = MockWebSocket.last

    expect(
      connection.sendTableViewport('p', 't', {
        filter: {},
        sort: null,
        limit: 10,
        anchor: null,
        anchorDirection: 'after',
        pageIndex: null,
      }),
    ).toBe(false)
    expect(socket.sent).toEqual([])
  })
})

describe('binary send', () => {
  it('sends a raw binary frame while connected', () => {
    const { connection } = createConnection()
    connection.connect()
    const socket = MockWebSocket.last
    socket.open()

    const chunk = new Uint8Array([1, 2, 3]).buffer
    expect(connection.sendBinary(chunk)).toBe(true)
    expect(socket.sent).toEqual([chunk])
  })

  it('sends nothing unless connected', () => {
    const { connection } = createConnection()
    connection.connect()
    const socket = MockWebSocket.last

    expect(connection.sendBinary(new ArrayBuffer(8))).toBe(false)
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

  it('emits actionError for a framework action_error frame', () => {
    const { connection } = createConnection()
    const errors: { action: string; reason: string }[] = []
    connection.on('actionError', (signal) =>
      errors.push({ action: signal.action, reason: signal.reason }),
    )

    connection.connect()
    MockWebSocket.last.open()
    MockWebSocket.last.message(
      '{"type":"action_error","data":{"action":"message","reason":"Message rate limit is active"},"outcome":"fail"}',
    )

    expect(errors).toEqual([
      { action: 'message', reason: 'Message rate limit is active' },
    ])
    expect(connection.state).toBe('connected')
  })

  it('emits tableWindowRefused for a table_window_refused frame', () => {
    const { connection } = createConnection()
    const received: { page: string; tableKey: string; errorCode: string }[] = []
    connection.on('tableWindowRefused', (signal) => received.push(signal.data))

    connection.connect()
    MockWebSocket.last.open()
    MockWebSocket.last.message(
      '{"type":"table_window_refused","data":{"page":"hilos_settings","tableKey":"settings","errorCode":"internal_error"}}',
    )

    expect(received).toEqual([
      {
        page: 'hilos_settings',
        tableKey: 'settings',
        errorCode: 'internal_error',
      },
    ])
    expect(connection.state).toBe('connected')
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

describe('page frame buffer', () => {
  const PAGE_SCHEMAS: ProjectSignalSchemas = {
    subscription_page_hilos_logs: z.looseObject({ node: z.string() }),
    subscription_page_hilos_log_presets: z.looseObject({ preset: z.string() }),
    subscription_page_error: z.looseObject({ page: z.string() }),
    page_response: z.looseObject({ page: z.string() }),
    logs_lines_appended: z.looseObject({ line: z.string() }),
  }

  // The answer to a subscription, the one page_response that opens a page's table windows.
  const WINDOW_ANSWER =
    '{"type":"page_response","data":{"page":"hilos_settings","payload":{"windows":' +
    '{"settings":{"rows":[],"sort":[],"limit":10,"totalCount":0,"totalExact":true,' +
    '"firstAnchor":null,"lastAnchor":null}}}}}'

  function connected(): HilosConnection {
    const { connection } = createConnection({ projectSchemas: PAGE_SCHEMAS })
    connection.connect()
    MockWebSocket.last.open()
    return connection
  }

  it('replays a page frame that arrived before the listener registered', () => {
    const connection = connected()
    MockWebSocket.last.message(
      '{"type":"subscription_page_hilos_logs","data":{"node":"one"}}',
    )

    const received: { type: string; data: unknown }[] = []
    connection.on('projectSignal', (signal) =>
      received.push({ type: signal.type, data: signal.data }),
    )

    expect(received).toEqual([
      { type: 'subscription_page_hilos_logs', data: { node: 'one' } },
    ])
  })

  it('replays the page_response a table binder registered too late for', () => {
    const connection = connected()
    MockWebSocket.last.message(WINDOW_ANSWER)

    const received: { type: string; data: unknown }[] = []
    connection.on('projectSignal', (signal) =>
      received.push({ type: signal.type, data: signal.data }),
    )

    // The first window of every table rides in this frame since HIL-642, and a table's
    // binder registers when its view mounts — which is after the frame has arrived. Without
    // the replay the window is delivered to nobody and the table never draws a row.
    expect(received).toHaveLength(1)
    expect(received[0]?.type).toBe('page_response')
  })

  it('replays a page_response that only refused table windows', () => {
    const connection = connected()
    MockWebSocket.last.message(
      '{"type":"page_response","data":{"page":"hilos_settings","payload":{"refusedWindows":{"settings":{"errorCode":"internal_error"}}}}}',
    )

    const received: { type: string; data: unknown }[] = []
    connection.on('projectSignal', (signal) =>
      received.push({ type: signal.type, data: signal.data }),
    )

    expect(received).toHaveLength(1)
    expect(received[0]?.type).toBe('page_response')
    expect(received[0]?.data).toEqual({
      page: 'hilos_settings',
      payload: {
        refusedWindows: { settings: { errorCode: 'internal_error' } },
      },
    })
  })

  it('buffers no page_response that carries no window', () => {
    const connection = connected()
    MockWebSocket.last.message(WINDOW_ANSWER)
    MockWebSocket.last.message(
      '{"type":"page_response","data":{"page":"hilos_settings","payload":{"tables":{"settings":{"rows":[{"rowKey":"a","slots":{}}]}}}}}',
    )

    const received: unknown[] = []
    connection.on('projectSignal', (signal) => received.push(signal.data))

    // That name carries two frames: the answer to a subscription and a live flush of
    // changed rows. Buffering by type alone, the flush would take the answer's place — and
    // a replayed delta doubles what was already delivered, which is what this buffer is
    // built to avoid.
    expect(received).toEqual([
      {
        page: 'hilos_settings',
        payload: {
          windows: {
            settings: {
              rows: [],
              sort: [],
              limit: 10,
              totalCount: 0,
              totalExact: true,
              firstAnchor: null,
              lastAnchor: null,
            },
          },
        },
      },
    ])
  })

  it('replays every buffered type once, in arrival order', () => {
    const connection = connected()
    MockWebSocket.last.message(
      '{"type":"subscription_page_hilos_logs","data":{"node":"one"}}',
    )
    MockWebSocket.last.message(
      '{"type":"subscription_page_hilos_log_presets","data":{"preset":"frugal"}}',
    )

    const received: string[] = []
    connection.on('projectSignal', (signal) => received.push(signal.type))

    expect(received).toEqual([
      'subscription_page_hilos_logs',
      'subscription_page_hilos_log_presets',
    ])
  })

  it('keeps only the last frame of a type', () => {
    const connection = connected()
    MockWebSocket.last.message(
      '{"type":"subscription_page_hilos_logs","data":{"node":"one"}}',
    )
    MockWebSocket.last.message(
      '{"type":"subscription_page_hilos_logs","data":{"node":"two"}}',
    )

    const received: unknown[] = []
    connection.on('projectSignal', (signal) => received.push(signal.data))

    expect(received).toEqual([{ node: 'two' }])
  })

  it('buffers neither a delta frame nor a subscription refusal', () => {
    const connection = connected()
    MockWebSocket.last.message(
      '{"type":"logs_lines_appended","data":{"line":"first"}}',
    )
    MockWebSocket.last.message(
      '{"type":"subscription_page_error","data":{"page":"hilos_logs"}}',
    )

    const received: string[] = []
    connection.on('projectSignal', (signal) => received.push(signal.type))

    expect(received).toEqual([])
  })

  it('delivers live frames to a listener that already replayed', () => {
    const connection = connected()
    MockWebSocket.last.message(
      '{"type":"subscription_page_hilos_logs","data":{"node":"one"}}',
    )

    const received: unknown[] = []
    connection.on('projectSignal', (signal) => received.push(signal.data))
    MockWebSocket.last.message(
      '{"type":"subscription_page_hilos_logs","data":{"node":"two"}}',
    )

    expect(received).toEqual([{ node: 'one' }, { node: 'two' }])
  })

  it('replays nothing after forgetPageFrames()', () => {
    const connection = connected()
    MockWebSocket.last.message(
      '{"type":"subscription_page_hilos_logs","data":{"node":"one"}}',
    )
    connection.forgetPageFrames()

    const received: string[] = []
    connection.on('projectSignal', (signal) => received.push(signal.type))

    expect(received).toEqual([])
  })

  it('replays nothing to a listener of another event', () => {
    const connection = connected()
    MockWebSocket.last.message(
      '{"type":"subscription_page_hilos_logs","data":{"node":"one"}}',
    )

    const kinds: string[] = []
    connection.on('signal', (signal) => kinds.push(signal.kind))

    expect(kinds).toEqual([])
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

describe('a browser that refuses its storage', () => {
  /**
   * Make one of the browser's stores throw on the global read itself, the way
   * Chrome with every cookie blocked does — before any getItem is reached.
   */
  function refuseStorage(name: 'localStorage' | 'sessionStorage'): void {
    Object.defineProperty(globalThis, name, {
      configurable: true,
      get() {
        throw new DOMException('denied', 'SecurityError')
      },
    })
  }

  afterEach(() => {
    delete (globalThis as { localStorage?: Storage }).localStorage
    delete (globalThis as { sessionStorage?: Storage }).sessionStorage
  })

  it('still creates and opens the connection', () => {
    refuseStorage('localStorage')
    refuseStorage('sessionStorage')

    const { connection, states } = createConnection()
    connection.connect()
    MockWebSocket.last.open()

    expect(states).toEqual(['connecting', 'connected'])
  })
})
