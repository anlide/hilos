import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { z } from 'zod'
import {
  HilosConnection,
  type ConnectionState,
  type TableWindowDescriptorSource,
  type WebSocketLike,
} from '../../src/connection/HilosConnection.js'
import { type ProjectSignalSchemas } from '../../src/protocol/parseSignal.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { subscribeSignal } from '../../src/state/signal.js'
import {
  bindPageScope,
  PAGE_SIGNAL_SCHEMAS,
} from '../../src/subscription/bindPageScope.js'
import { bindTableViewport } from '../../src/subscription/bindTableViewport.js'
import { TableViewportController } from '../../src/table/TableViewportController.js'
import { type TablePresentationChange } from '../../src/table/tableViewportGeometry.js'

/**
 * Scripted stand-in for the browser WebSocket. Nothing fires on its own —
 * tests drive open/message/close/error explicitly, mirroring how the real
 * socket calls back asynchronously.
 */
class MockWebSocket implements WebSocketLike {
  static instances: MockWebSocket[] = []

  readonly url: string
  readonly sent: (string | ArrayBuffer | Blob)[] = []
  bufferedAmount = 0
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

describe('table presentation dispatch', () => {
  function source(name: string, events: string[]): TableWindowDescriptorSource {
    return {
      descriptor: () => null,
      withPresentationUpdate<T>(
        change: TablePresentationChange,
        update: () => T,
      ): T {
        events.push(`${name}:${change.kind}`)
        try {
          return update()
        } finally {
          events.push(`${name}:finally`)
        }
      },
    }
  }

  it('nests all optional sources around one update and returns its value', () => {
    const { connection } = createConnection()
    const events: string[] = []
    const update = vi.fn(() => {
      events.push('update')
      return 42
    })
    expect(
      connection.withTablePresentationUpdate({ kind: 'window' }, update),
    ).toBe(42)
    events.length = 0
    update.mockClear()
    connection.registerTableWindow('first', source('first', events))
    connection.registerTableWindow('legacy', { descriptor: () => null })
    connection.registerTableWindow('last', source('last', events))

    expect(
      connection.withTablePresentationUpdate({ kind: 'window' }, update),
    ).toBe(42)
    expect(update).toHaveBeenCalledTimes(1)
    expect(events).toEqual([
      'first:window',
      'last:window',
      'update',
      'last:finally',
      'first:finally',
    ])
  })

  it('unwinds every source on throw and uses current registrations on the next call', () => {
    const { connection } = createConnection()
    const events: string[] = []
    connection.registerTableWindow('first', source('old', events))
    connection.registerTableWindow('last', source('last', events))
    expect(() =>
      connection.withTablePresentationUpdate({ kind: 'window' }, () => {
        events.push('throw')
        throw new Error('publication failed')
      }),
    ).toThrow('publication failed')
    expect(events).toEqual([
      'old:window',
      'last:window',
      'throw',
      'last:finally',
      'old:finally',
    ])

    connection.unregisterTableWindow('last')
    connection.registerTableWindow('first', source('remount', events))
    events.length = 0
    connection.withTablePresentationUpdate(
      { kind: 'rows', rowKeys: ['a'] },
      () => events.push('next'),
    )
    expect(events).toEqual(['remount:rows', 'next', 'remount:finally'])
  })

  it('unwinds earlier sources when a later preparation throws before publication', () => {
    const { connection } = createConnection()
    const events: string[] = []
    const update = vi.fn()
    connection.registerTableWindow('first', source('first', events))
    connection.registerTableWindow('throwing', {
      descriptor: () => null,
      withPresentationUpdate() {
        throw new Error('capture failed')
      },
    })
    expect(() =>
      connection.withTablePresentationUpdate({ kind: 'window' }, update),
    ).toThrow('capture failed')
    expect(update).not.toHaveBeenCalled()
    expect(events).toEqual(['first:window', 'first:finally'])
    connection.unregisterTableWindow('throwing')
    connection.withTablePresentationUpdate({ kind: 'window' }, update)
    expect(update).toHaveBeenCalledTimes(1)
  })

  it('holds page_response through every project listener and remembers it before delivery', () => {
    const { connection } = createConnection({
      projectSchemas: { page_response: z.looseObject({ page: z.string() }) },
    })
    const events: string[] = []
    connection.registerTableWindow('table', source('table', events))
    connection.on('projectSignal', () => events.push('early'))
    connection.on('projectSignal', () => events.push('late'))
    connection.on('signal', () => events.push('signal'))
    connection.connect()
    MockWebSocket.last.message(
      '{"type":"page_response","data":{"page":"main","payload":{"windows":{"table":{}}}}}',
    )
    expect(events).toEqual([
      'table:window',
      'early',
      'late',
      'table:finally',
      'signal',
    ])
    const replay = vi.fn()
    connection.on('projectSignal', replay)
    expect(replay).toHaveBeenCalledTimes(1)
    connection.close()
  })

  it('leaves other project signals in their existing dispatch path', () => {
    const { connection } = createConnection({
      projectSchemas: { subscription_page_state: z.looseObject({}) },
    })
    const events: string[] = []
    connection.registerTableWindow('table', source('table', events))
    connection.on('projectSignal', () => events.push('project'))
    connection.connect()
    MockWebSocket.last.message('{"type":"subscription_page_state","data":{}}')
    expect(events).toEqual(['project'])
    connection.close()
  })
})

function createConnection(
  overrides: {
    url?: string
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

const intl = Object.getOwnPropertyDescriptor(globalThis, 'Intl')
// Taken before any fake clock: the fake timers stand an Intl of their own in,
// and its formatters still answer from the engine's own prototype.
const engineDateTimeFormat = Intl.DateTimeFormat
const resolved = new engineDateTimeFormat().resolvedOptions()

/**
 * Make the engine report `timeZone` as this browser's zone, whatever zone the
 * machine running the suite is in.
 *
 * @param timeZone The zone the next reads report.
 */
function reportZone(timeZone: unknown): void {
  vi.spyOn(engineDateTimeFormat.prototype, 'resolvedOptions').mockReturnValue({
    ...resolved,
    timeZone: timeZone as string,
  })
}

beforeEach(() => {
  vi.useFakeTimers()
  MockWebSocket.instances = []
  reportZone('Europe/Warsaw')
})

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
  if (intl !== undefined) {
    Object.defineProperty(globalThis, 'Intl', intl)
  }
})

describe('connection machine', () => {
  it('emits typed page recovery on the existing socket without reconnecting or sending actions', () => {
    const { connection } = createConnection()
    const requests: Array<{ page: string; params: Record<string, unknown> }> =
      []
    connection.on('pageResubscribe', (signal) => requests.push(signal.data))
    connection.connect()
    const socket = MockWebSocket.last
    socket.open()
    socket.message(WELCOME_A)
    socket.message(
      JSON.stringify({
        type: 'page_resubscribe',
        data: { page: 'picture', params: { room: '42' } },
      }),
    )
    socket.message('{"type":"page_resubscribe","data":{"page":"picture"}}')
    expect(requests).toEqual([{ page: 'picture', params: { room: '42' } }])
    expect(MockWebSocket.instances).toEqual([socket])
    expect(socket.closeCalls).toBe(0)
    expect(socket.sent).toEqual([])
    expect(connection.state).toBe('connected')
  })

  it('starts disconnected and walks connecting → connected', () => {
    const { connection, states } = createConnection()
    expect(connection.state).toBe('disconnected')

    connection.connect()
    expect(connection.state).toBe('connecting')
    expect(MockWebSocket.last.url).toBe(
      'ws://test/ws?browserTimeZone=Europe%2FWarsaw',
    )

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

describe('browser time zone on the socket url', () => {
  it('adds the zone after the configured query of an absolute url', () => {
    const { connection } = createConnection({
      url: 'wss://test.example:8443/ws?room=7&debug&note=a%20b',
    })
    connection.connect()

    expect(MockWebSocket.last.url).toBe(
      'wss://test.example:8443/ws?room=7&debug&note=a%20b&browserTimeZone=Europe%2FWarsaw',
    )
  })

  it('adds the zone to a relative url', () => {
    const { connection } = createConnection({ url: '/ws' })
    connection.connect()

    expect(MockWebSocket.last.url).toBe('/ws?browserTimeZone=Europe%2FWarsaw')
  })

  it('replaces every copy of the reserved parameter standing in the configured url', () => {
    const { connection } = createConnection({
      url: '/ws?browserTimeZone=Mars%2FStale&room=7&browser%54imeZone=Asia%2FTokyo',
    })
    connection.connect()

    expect(MockWebSocket.last.url).toBe(
      '/ws?room=7&browserTimeZone=Europe%2FWarsaw',
    )
  })

  it('carries the zone beside a presented pass', () => {
    const { connection } = createConnection({ url: '/ws?room=7' })
    connection.connect()
    MockWebSocket.last.open()

    connection.presentProtectedModePass('the key')

    expect(MockWebSocket.last.url).toBe(
      '/ws?room=7&browserTimeZone=Europe%2FWarsaw&hilosPass=the%20key',
    )
  })

  it('reads the zone again for the socket a reconnect opens', () => {
    const { connection } = createConnection({
      url: '/ws?browserTimeZone=Mars%2FStale',
    })
    connection.connect()
    MockWebSocket.last.open()

    reportZone('Asia/Tokyo')
    MockWebSocket.last.drop()
    vi.advanceTimersByTime(1000)

    expect(MockWebSocket.instances).toHaveLength(2)
    expect(MockWebSocket.last.url).toBe('/ws?browserTimeZone=Asia%2FTokyo')
  })

  it.each([
    ['an unusable name', () => reportZone('+02:00')],
    [
      'a throwing resolver',
      () =>
        vi
          .spyOn(engineDateTimeFormat.prototype, 'resolvedOptions')
          .mockImplementation(() => {
            throw new RangeError('refused')
          }),
    ],
    [
      'no Intl at all',
      () =>
        Object.defineProperty(globalThis, 'Intl', {
          configurable: true,
          value: undefined,
        }),
    ],
  ])(
    'leaves the parameter out and still connects on %s',
    (_label, silenceBrowser) => {
      const { connection, states } = createConnection({
        url: '/ws?room=7&browserTimeZone=Mars%2FStale',
      })
      silenceBrowser()
      connection.connect()
      MockWebSocket.last.open()

      expect(MockWebSocket.last.url).toBe('/ws?room=7')
      expect(states).toEqual(['connecting', 'connected'])
    },
  )

  it("names neither the project's zone nor the person's own when the browser cannot say", () => {
    const { connection } = createConnection({
      projectSchemas: { handshake_response: z.looseObject({}) },
    })
    connection.connect()
    MockWebSocket.last.open()
    MockWebSocket.last.message(
      JSON.stringify({
        type: 'handshake',
        data: { build: 'build-a', projectTimeZone: 'America/New_York' },
      }),
    )
    MockWebSocket.last.message(
      JSON.stringify({
        type: 'handshake_response',
        data: { entities: { currentUser: { id: 1, timeZone: 'Asia/Tokyo' } } },
      }),
    )
    expect(connection.projectTimeZone).toBe('America/New_York')

    reportZone(undefined)
    MockWebSocket.last.drop()
    vi.advanceTimersByTime(1000)

    expect(MockWebSocket.last.url).toBe('ws://test/ws')
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
  it('applies only its tracked response in an ordered batch and recovers the desired window on a new socket', () => {
    const { connection } = createConnection({
      projectSchemas: PAGE_SIGNAL_SCHEMAS,
    })
    const scopes = new ScopeManager()
    const pages = bindPageScope(connection, scopes)
    connection.connect()
    const socket = MockWebSocket.last
    socket.open()
    pages.subscribe('p')
    pages.releaseOnSession()
    const controller = new TableViewportController({
      resolve: (row) => row,
      sendViewport: (descriptor) =>
        connection.sendTableViewport('p', 't', descriptor),
      frame: { columns: [] },
    })
    const unbind = bindTableViewport(
      connection,
      scopes,
      { page: 'p', tableKey: 't' },
      controller,
    )
    const window = {
      rows: [{ rowKey: 'initial', slots: {} }],
      totalCount: 1,
      totalExact: true,
      limit: 10,
      sort: [],
      firstAnchor: null,
      lastAnchor: null,
    }
    socket.message(
      JSON.stringify({
        type: 'page_response',
        data: { page: 'p', payload: { windows: { t: window } } },
      }),
    )
    expect(socket.sent).toHaveLength(1) // The page's first answer asks for no viewport.
    controller.setSearch('first')
    const old = (
      JSON.parse(socket.sent.at(-1) as string) as { requestId: string }
    ).requestId
    controller.setSearch('second')
    const current = (
      JSON.parse(socket.sent.at(-1) as string) as { requestId: string }
    ).requestId
    const completed: string[] = []
    const stop = subscribeSignal(
      controller.lastAppliedWindowRequestId,
      (id) => {
        if (id !== null) {
          completed.push(id)
          expect(controller.rows.get().map((row) => row.rowKey)).toEqual([
            'accepted',
          ])
        }
      },
    )
    socket.message(
      JSON.stringify({
        type: 'signal_batch',
        data: {
          frames: [
            {
              type: 'table_window_refused',
              data: {
                page: 'p',
                tableKey: 't',
                errorCode: 'internal_error',
                requestId: old,
              },
            },
            {
              type: 'page_response',
              data: { page: 'p', payload: { data: { label: 'live' } } },
            },
            {
              type: 'table_window',
              data: {
                ...window,
                page: 'p',
                tableKey: 't',
                rows: [{ rowKey: 'accepted', slots: {} }],
                requestId: current,
              },
            },
            {
              type: 'table_window',
              data: { ...window, page: 'p', tableKey: 't', requestId: old },
            },
          ],
        },
      }),
    )
    expect(completed).toEqual([current])
    controller.setSearch('desired')
    const beforeDrop = (
      JSON.parse(socket.sent.at(-1) as string) as { requestId: string }
    ).requestId
    socket.drop()
    expect(controller.acceptsWindowResponse(beforeDrop)).toBe(false)
    vi.advanceTimersByTime(1000)
    const reconnected = MockWebSocket.last
    reconnected.open()
    pages.releaseOnSession()
    expect(reconnected.sent).toHaveLength(1)
    const report = JSON.parse(reconnected.sent[0] as string) as {
      tableWindows: { t: Record<string, unknown> }
    }
    expect(report.tableWindows.t.filter).toEqual({ search: 'desired' })
    expect(report.tableWindows.t).not.toHaveProperty('requestId')
    reconnected.message(
      JSON.stringify({
        type: 'page_response',
        data: { page: 'p', payload: { windows: { t: window } } },
      }),
    )
    expect(controller.frame.body.get()).toBe('rows')
    expect(controller.lastAppliedWindowRequestId.get()).toBeNull()
    expect(reconnected.sent).toHaveLength(1)
    controller.refresh()
    const refused = (
      JSON.parse(reconnected.sent.at(-1) as string) as { requestId: string }
    ).requestId
    pages.denyCurrentPage()
    expect(controller.acceptsWindowResponse(refused)).toBe(false)
    expect(controller.lastAppliedWindowRequestId.get()).toBeNull()
    stop()
    unbind()
    controller.dispose()
    connection.close()
  })

  it('sends correlation at the top level and strips it from held reports across replacement and disconnect', () => {
    const { connection } = createConnection()
    connection.connect()
    const socket = MockWebSocket.last
    socket.open()
    const held = {
      filter: {},
      sort: null,
      limit: 10,
      anchor: null,
      anchorDirection: 'after' as const,
      pageIndex: null,
    }
    const descriptor = { ...held, requestId: 'mount:1' }
    const reset = vi.fn()
    const first = { descriptor: () => descriptor, resetWindowRequests: reset }
    connection.registerTableWindow('t', first)
    connection.sendTableViewport('p', 't', descriptor)
    expect(JSON.parse(socket.sent.at(-1) as string)).toEqual({
      type: 'table_viewport',
      page: 'p',
      tableKey: 't',
      limit: 10,
      anchor: null,
      anchorDirection: 'after',
      requestId: 'mount:1',
    })
    expect(connection.tableWindowDescriptors()).toEqual({ t: held })
    socket.drop()
    expect(reset).toHaveBeenCalledTimes(1)
    const nextReset = vi.fn()
    const replacement = {
      descriptor: () => held,
      resetWindowRequests: nextReset,
    }
    connection.registerTableWindow('t', replacement)
    expect(reset).toHaveBeenCalledTimes(2)
    connection.unregisterTableWindow('t', first)
    expect(connection.tableWindowDescriptors()).toEqual({ t: held })
    connection.unregisterTableWindow('t', replacement)
    expect(nextReset).toHaveBeenCalledTimes(1)
    expect(connection.tableWindowDescriptors()).toEqual({})
  })

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

  it('names the row a tab holds in focus in a frame of its own, and lets it go with an empty key', () => {
    const { connection } = createConnection()
    connection.connect()
    const socket = MockWebSocket.last
    socket.open()

    const sent = connection.sendTableRowFocus(
      'hilos_settings',
      'settings',
      'site.title',
    )

    expect(sent).toBe(true)
    expect(JSON.parse(socket.sent.at(-1) as string)).toEqual({
      type: 'table_row_focus',
      page: 'hilos_settings',
      tableKey: 'settings',
      rowKey: 'site.title',
    })

    connection.sendTableRowFocus('hilos_settings', 'settings', '')

    expect(JSON.parse(socket.sent.at(-1) as string)).toEqual({
      type: 'table_row_focus',
      page: 'hilos_settings',
      tableKey: 'settings',
      rowKey: '',
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
  it('reports the active socket send buffer', () => {
    const { connection } = createConnection()

    expect(connection.bufferedAmount).toBe(0)

    connection.connect()
    MockWebSocket.last.bufferedAmount = 321

    expect(connection.bufferedAmount).toBe(321)
  })

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

  it('keeps the project time zone from the last welcome and clears it when a later one omits the field', () => {
    const { connection } = createConnection()
    expect(connection.projectTimeZone).toBeUndefined()

    const seenInHandler: Array<string | undefined> = []
    connection.on('handshake', () => {
      seenInHandler.push(connection.projectTimeZone)
    })

    connection.connect()
    MockWebSocket.last.open()
    MockWebSocket.last.message(
      '{"type":"handshake","data":{"build":"build-a","projectTimeZone":"Europe/Kyiv"}}',
    )
    expect(seenInHandler).toEqual(['Europe/Kyiv'])
    expect(connection.projectTimeZone).toBe('Europe/Kyiv')

    MockWebSocket.last.message(
      '{"type":"handshake","data":{"build":"build-a","projectTimeZone":"Europe/Warsaw"}}',
    )
    expect(connection.projectTimeZone).toBe('Europe/Warsaw')

    MockWebSocket.last.drop()
    vi.advanceTimersByTime(1000)
    MockWebSocket.last.open()
    MockWebSocket.last.message(WELCOME_A)

    expect(connection.projectTimeZone).toBeUndefined()
    expect(seenInHandler).toEqual(['Europe/Kyiv', 'Europe/Warsaw', undefined])
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

  it('emits tableViewportFrozen for a table_viewport_frozen frame', () => {
    const { connection } = createConnection()
    const received: { page: string; tableKey: string; since: number }[] = []
    connection.on('tableViewportFrozen', (signal) => received.push(signal.data))

    connection.connect()
    MockWebSocket.last.open()
    MockWebSocket.last.message(
      '{"type":"table_viewport_frozen","data":{"page":"hilos_settings","tableKey":"settings","since":1790000000123}}',
    )

    expect(received).toEqual([
      { page: 'hilos_settings', tableKey: 'settings', since: 1790000000123 },
    ])
    expect(connection.state).toBe('connected')
  })

  it('emits parseFailure for a table_viewport_frozen frame whose since is not an integer', () => {
    const { connection } = createConnection()
    const received: unknown[] = []
    const invalid: string[] = []
    connection.on('tableViewportFrozen', (signal) => received.push(signal.data))
    connection.on('parseFailure', (failure) => invalid.push(failure.kind))

    connection.connect()
    MockWebSocket.last.open()
    MockWebSocket.last.message(
      '{"type":"table_viewport_frozen","data":{"page":"hilos_settings","tableKey":"settings","since":1.5}}',
    )

    expect(received).toEqual([])
    expect(invalid).toEqual(['invalid-signal-data'])
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

  it('dispatches every inner frame synchronously and preserves late-listener replay', () => {
    const connection = connected()
    const seen: string[] = []
    const replies: unknown[] = []
    connection.on('signal', (signal) => seen.push(signal.envelope.type))
    connection.on('parseFailure', (failure) => seen.push(failure.kind))
    connection.on('actionSuccess', (signal) => replies.push(signal))
    const ack = {
      type: 'action_success',
      data: { action: 'save' },
      requestId: 'req',
      outcome: 'success',
      time: 123,
    }
    const frames = [
      { type: 'subscription_page_hilos_logs', data: { node: 'early' } },
      { type: 'handshake', data: {} },
      ack,
      { type: 'signal_batch', data: { frames: [ack] } },
      {
        type: 'table_viewport_delta',
        data: { page: 'p', tableKey: 't', kind: 'row_updated' },
      },
      JSON.parse(WINDOW_ANSWER),
      { type: 'future', data: null },
    ]
    MockWebSocket.last.message(
      JSON.stringify({ type: 'signal_batch', data: { frames } }),
    )

    expect(seen).toEqual([
      'subscription_page_hilos_logs',
      'invalid-signal-data',
      'action_success',
      'invalid-signal-data',
      'table_viewport_delta',
      'page_response',
      'future',
    ])
    expect(replies).toMatchObject([{ requestId: 'req', envelope: ack }])
    const late: string[] = []
    connection.on('projectSignal', (signal) => late.push(signal.type))
    expect(late).toEqual(['subscription_page_hilos_logs', 'page_response'])
    const tail: string[] = []
    connection.on('projectSignal', (signal) => {
      if (signal.type === 'page_response') {
        connection.on('unknownSignal', (inner) => tail.push(inner.type))
      }
    })
    MockWebSocket.last.message(
      JSON.stringify({
        type: 'signal_batch',
        data: {
          frames: [JSON.parse(WINDOW_ANSWER), { type: 'after_page', data: {} }],
        },
      }),
    )
    // Both the replayed page response and its new delivery register a listener.
    expect(tail).toEqual(['after_page', 'after_page'])
  })

  it('reports a malformed outer once and dispatches none of its inner frames', () => {
    const connection = connected()
    const seen: string[] = []
    connection.on('signal', () => seen.push('signal'))
    connection.on('parseFailure', () => seen.push('failure'))
    MockWebSocket.last.message(
      JSON.stringify({
        type: 'signal_batch',
        outcome: 'bad',
        data: { frames: [JSON.parse(WINDOW_ANSWER)] },
      }),
    )
    expect(seen).toEqual(['failure'])
  })

  it('stops an old delivery when a listener closes and opens a new generation', () => {
    const connection = connected()
    const before = MockWebSocket.last
    const seen: string[] = []
    connection.on('unknownSignal', (signal) => {
      seen.push(signal.type)
      connection.close()
      connection.connect()
    })
    before.message(
      JSON.stringify({
        type: 'signal_batch',
        data: {
          frames: [
            { type: 'first', data: {} },
            { type: 'old_tail', data: {} },
          ],
        },
      }),
    )
    expect(seen).toEqual(['first'])
    expect(MockWebSocket.instances).toHaveLength(2)
    expect(connection.state).toBe('connecting')
    before.message('{"type":"obsolete","data":{}}')
    expect(seen).toEqual(['first'])
  })

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
      '{"type":"subscription_page_error","data":{"page":"hilos_logs","httpCode":404,"errorCode":"not_found","title":"Page Not Found","message":"The page you are looking for does not exist."}}',
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
