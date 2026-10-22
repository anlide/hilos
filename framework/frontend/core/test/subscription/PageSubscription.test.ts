import { describe, expect, it, vi } from 'vitest'
import { PageSubscription } from '../../src/subscription/PageSubscription.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { subscribeSignal } from '../../src/state/signal.js'
import { type EntityRef } from '../../src/state/EntityStore.js'
import {
  HilosConnection,
  type ConnectionState,
  type HilosConnectionEventMap,
  type TableViewportDescriptor,
} from '../../src/connection/HilosConnection.js'
import {
  PROTECTED_MODE_INACTIVE,
  type ProtectedModeStatus,
} from '../../src/protocol/protectedMode.js'
import { TableViewportController } from '../../src/table/TableViewportController.js'
import { type TableRow } from '../../src/state/TableRowsStore.js'
import { type TableGeometryCaptureRequest } from '../../src/table/tableViewportGeometry.js'

/** The connection events the double replays. */
interface FakeConnectionEvents {
  state: ConnectionState
  reconnectDragging: boolean
  protectedMode: ProtectedModeStatus
  pageResubscribe: HilosConnectionEventMap['pageResubscribe']
}

/**
 * A connection double recording sent frames, replaying state changes and
 * protected-mode frames, and counting how often the page-frame buffer was
 * dropped. Like HilosConnection, it refuses every frame while the mode holds it.
 */
function fakeConnection(initialState: ConnectionState = 'connected') {
  const listeners: Array<(state: ConnectionState) => void> = []
  const draggingListeners: Array<(dragging: boolean) => void> = []
  const protectedModeListeners: Array<(status: ProtectedModeStatus) => void> =
    []
  const recoveryListeners: Array<
    (signal: HilosConnectionEventMap['pageResubscribe']) => void
  > = []
  const listenersOf: {
    [K in keyof FakeConnectionEvents]: Array<
      (payload: FakeConnectionEvents[K]) => void
    >
  } = {
    state: listeners,
    reconnectDragging: draggingListeners,
    protectedMode: protectedModeListeners,
    pageResubscribe: recoveryListeners,
  }
  const sent: Array<Record<string, unknown>> = []

  return {
    state: initialState,
    reconnectDragging: false,
    sent,
    journal: [] as string[],
    /** Whether protected mode holds this connection, as the last frame said. */
    frozen: false,
    /** The windows this connection's tables are holding, as the subscribe frame reports them. */
    windows: {} as Record<string, TableViewportDescriptor>,
    forgotPageFrames: 0,
    requestRecovery(page: string, params: Record<string, unknown> = {}) {
      for (const listener of [...recoveryListeners]) {
        listener({
          kind: 'pageResubscribe',
          data: { page, params },
          envelope: { type: 'page_resubscribe', data: { page, params } },
        })
      }
    },
    forgetPageFrames(): void {
      this.forgotPageFrames += 1
    },
    setState(state: ConnectionState) {
      this.state = state
      for (const listener of [...listeners]) {
        listener(state)
      }
    },
    setReconnectDragging(dragging: boolean) {
      this.reconnectDragging = dragging
      for (const listener of [...draggingListeners]) {
        listener(dragging)
      }
    },
    setProtectedMode(status: ProtectedModeStatus) {
      this.frozen = status.active
      for (const listener of [...protectedModeListeners]) {
        listener(status)
      }
    },
    send(text: string): boolean {
      if (this.state !== 'connected' || this.frozen) {
        return false
      }
      const frame = JSON.parse(text) as Record<string, unknown>
      sent.push(frame)
      this.journal.push(String(frame.type))

      return true
    },
    on<K extends keyof FakeConnectionEvents>(
      event: K,
      listener: (payload: FakeConnectionEvents[K]) => void,
    ) {
      listenersOf[event].push(listener)

      return () => {
        const index = listenersOf[event].indexOf(listener)
        if (index !== -1) {
          listenersOf[event].splice(index, 1)
        }
      }
    },
    listenerCount(event: keyof FakeConnectionEvents): number {
      return listenersOf[event].length
    },
    tableWindowDescriptors(): Record<string, TableViewportDescriptor> {
      return this.windows
    },
  }
}

/** Waiting must leave no per-call connection listener or callback behind. */
function expectNoSubscriptionWaiters(
  pages: PageSubscription,
  connection: ReturnType<typeof fakeConnection>,
): void {
  expect(connection.listenerCount('state')).toBe(1)
  expect(connection.listenerCount('reconnectDragging')).toBe(0)
  expect(pages).toHaveProperty('subscriptionWaiters.size', 0)
}

describe('PageSubscription.runWhenSubscribed', () => {
  it('starts in the subscribe send stack, after the session answer and before returning', async () => {
    const connection = fakeConnection('connecting')
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.subscribe('main')
    const controller = new AbortController()
    const add = vi.spyOn(controller.signal, 'addEventListener')
    const remove = vi.spyOn(controller.signal, 'removeEventListener')
    const result = pages.runWhenSubscribed(() => {
      connection.journal.push('callback')
      connection.send(JSON.stringify({ type: 'action' }))

      return Promise.resolve('answered')
    }, controller.signal)
    connection.setState('connected')
    pages.ingestPageResponse('main', {})
    expect(connection.journal).toEqual([])
    expect(connection.listenerCount('state')).toBe(2)
    expect(connection.listenerCount('reconnectDragging')).toBe(1)

    pages.releaseOnSession()
    connection.journal.push('returned')
    expect(connection.journal).toEqual([
      'page_subscribe',
      'callback',
      'action',
      'returned',
    ])
    expectNoSubscriptionWaiters(pages, connection)
    expect(remove).toHaveBeenCalledExactlyOnceWith(
      'abort',
      add.mock.calls[0]?.[1],
    )
    await expect(result).resolves.toBe('answered')
    pages.releaseOnSession()
    connection.requestRecovery('main')
    expect(
      connection.journal.filter((event) => event === 'callback'),
    ).toHaveLength(1)
  })

  it('calls an already ready callback before runWhenSubscribed returns', async () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.subscribe('main')
    pages.releaseOnSession()
    const result = pages.runWhenSubscribed(() => {
      connection.journal.push('callback')

      return Promise.resolve(42)
    })
    connection.journal.push('returned')

    expect(connection.journal).toEqual([
      'page_subscribe',
      'callback',
      'returned',
    ])
    expectNoSubscriptionWaiters(pages, connection)
    await expect(result).resolves.toBe(42)
  })

  it.each(['initial', 'navigation', 'recovery'] as const)(
    'holds a refused %s subscribe until its existing retry succeeds',
    async (path) => {
      const connection = fakeConnection()
      const pages = new PageSubscription(connection, new ScopeManager())
      pages.subscribe('main')
      if (path !== 'initial') pages.releaseOnSession()
      connection.frozen = true
      if (path === 'navigation') pages.subscribe('next', { id: 2 })
      if (path === 'recovery') connection.requestRecovery('main')
      if (path === 'initial') pages.releaseOnSession()
      const run = vi.fn(() => {
        connection.journal.push('callback')

        return Promise.resolve('sent')
      })
      const result = pages.runWhenSubscribed(run)
      const sentBeforeRetry = connection.sent.length
      expect(run).not.toHaveBeenCalled()

      connection.setProtectedMode(PROTECTED_MODE_INACTIVE)
      expect(connection.sent).toHaveLength(sentBeforeRetry + 1)
      expect(connection.journal.slice(-2)).toEqual([
        'page_subscribe',
        'callback',
      ])
      expect(run).toHaveBeenCalledTimes(1)
      expectNoSubscriptionWaiters(pages, connection)
      await expect(result).resolves.toBe('sent')
    },
  )

  it('clears old delivery before navigation notifies page observers', async () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.subscribe('main')
    pages.releaseOnSession()
    pages.ingestPageResponse('main', {})
    connection.frozen = true
    const run = vi.fn(() => {
      connection.journal.push('callback')

      return Promise.resolve()
    })
    let result: Promise<void> | undefined
    const stop = subscribeSignal(pages.pageLoading, () => {
      result = pages.runWhenSubscribed(run)
    })

    pages.subscribe('next', { id: 2 })
    stop()
    expect(result).toBeDefined()
    expect(run).not.toHaveBeenCalled()
    connection.setProtectedMode(PROTECTED_MODE_INACTIVE)
    expect(connection.journal).toEqual([
      'page_subscribe',
      'page_subscribe',
      'callback',
    ])
    expectNoSubscriptionWaiters(pages, connection)
    await result
  })

  it('forgets old delivery on reconnect and waits through another socket attempt', async () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.subscribe('main', { id: 1 })
    pages.releaseOnSession()
    pages.ingestPageResponse('main', {})
    connection.setState('reconnecting')
    const run = vi.fn(() => {
      connection.journal.push('callback')

      return Promise.resolve()
    })
    const result = pages.runWhenSubscribed(run)
    connection.setState('connected')
    pages.ingestPageResponse('main', {})
    expect(run).not.toHaveBeenCalled()
    connection.setState('reconnecting')
    connection.setState('connected')
    expect(run).not.toHaveBeenCalled()

    pages.releaseOnSession()
    expect(connection.journal).toEqual([
      'page_subscribe',
      'page_subscribe',
      'callback',
    ])
    expect(connection.sent[1]).toEqual({
      type: 'page_subscribe',
      page: 'main',
      params: { id: 1 },
    })
    expect(run).toHaveBeenCalledTimes(1)
    expectNoSubscriptionWaiters(pages, connection)
    await result
  })

  it('rejects an aborted signal even when the subscription is already ready', async () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.subscribe('main')
    pages.releaseOnSession()
    const controller = new AbortController()
    const reason = new Error('Canceled by the caller')
    controller.abort(reason)
    const run = vi.fn(() => Promise.resolve())

    await expect(pages.runWhenSubscribed(run, controller.signal)).rejects.toBe(
      reason,
    )
    expect(run).not.toHaveBeenCalled()
    expectNoSubscriptionWaiters(pages, connection)
  })

  it('aborts a pending wait, removes its abort listener, and never dispatches it later', async () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.subscribe('main')
    const controller = new AbortController()
    const add = vi.spyOn(controller.signal, 'addEventListener')
    const remove = vi.spyOn(controller.signal, 'removeEventListener')
    const run = vi.fn(() => Promise.resolve())
    const result = pages.runWhenSubscribed(run, controller.signal)
    controller.abort()

    await expect(result).rejects.toMatchObject({ name: 'AbortError' })
    expect(remove).toHaveBeenCalledExactlyOnceWith(
      'abort',
      add.mock.calls[0]?.[1],
    )
    expectNoSubscriptionWaiters(pages, connection)
    pages.releaseOnSession()
    expect(run).not.toHaveBeenCalled()
  })

  it.each(['page', 'params', 'resubscribe', 'unsubscribe'] as const)(
    'cancels the captured subscription on %s',
    async (change) => {
      const connection = fakeConnection()
      const pages = new PageSubscription(connection, new ScopeManager())
      pages.subscribe('main', { id: 1 })
      const controller = new AbortController()
      const remove = vi.spyOn(controller.signal, 'removeEventListener')
      const run = vi.fn(() => Promise.resolve())
      const result = pages.runWhenSubscribed(run, controller.signal)
      if (change === 'page') pages.subscribe('next', { id: 1 })
      if (change === 'params') pages.subscribe('main', { id: 2 })
      if (change === 'resubscribe') pages.subscribe('main', { id: 1 })
      if (change === 'unsubscribe') pages.unsubscribe()

      await expect(result).rejects.toMatchObject({ name: 'AbortError' })
      expect(remove).toHaveBeenCalledTimes(1)
      expectNoSubscriptionWaiters(pages, connection)
      pages.releaseOnSession()
      expect(run).not.toHaveBeenCalled()
    },
  )

  it('refuses a wait with no current page instead of attaching it to future navigation', async () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    const run = vi.fn(() => Promise.resolve())

    await expect(pages.runWhenSubscribed(run)).rejects.toMatchObject({
      name: 'AbortError',
    })
    expectNoSubscriptionWaiters(pages, connection)
    pages.subscribe('main')
    pages.releaseOnSession()
    expect(run).not.toHaveBeenCalled()
  })

  it.each(['disconnected', 'dragging'] as const)(
    'rejects immediately when %s is already known',
    async (unavailable) => {
      const connection = fakeConnection()
      const pages = new PageSubscription(connection, new ScopeManager())
      pages.subscribe('main')
      pages.releaseOnSession()
      if (unavailable === 'disconnected') connection.setState('disconnected')
      else connection.setReconnectDragging(true)
      const run = vi.fn(() => Promise.resolve())

      await expect(pages.runWhenSubscribed(run)).rejects.toThrow(
        'Connection is unavailable',
      )
      expect(run).not.toHaveBeenCalled()
      expectNoSubscriptionWaiters(pages, connection)
    },
  )

  it.each(['disconnected', 'dragging'] as const)(
    'rejects every pending wait when %s arrives and cleans up even before an abort',
    async (unavailable) => {
      const connection = fakeConnection('reconnecting')
      const pages = new PageSubscription(connection, new ScopeManager())
      pages.subscribe('main')
      const controller = new AbortController()
      const remove = vi.spyOn(controller.signal, 'removeEventListener')
      const run = vi.fn(() => Promise.resolve())
      const first = pages.runWhenSubscribed(run, controller.signal)
      const second = pages.runWhenSubscribed(run)
      if (unavailable === 'disconnected') connection.setState('disconnected')
      else connection.setReconnectDragging(true)

      await expect(first).rejects.toThrow('Connection is unavailable')
      await expect(second).rejects.toThrow('Connection is unavailable')
      expect(remove).toHaveBeenCalledTimes(1)
      expectNoSubscriptionWaiters(pages, connection)
      connection.setReconnectDragging(false)
      connection.setState('connected')
      pages.releaseOnSession()
      controller.abort()
      expect(run).not.toHaveBeenCalled()
    },
  )

  it('rechecks readiness after a preceding callback closes the socket', async () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.subscribe('main')
    const first = pages.runWhenSubscribed(() => {
      connection.journal.push('first')
      connection.setState('reconnecting')

      return Promise.resolve(1)
    })
    const second = pages.runWhenSubscribed(() => {
      connection.journal.push('second')

      return Promise.resolve(2)
    })
    pages.releaseOnSession()
    expect(connection.journal).toEqual(['page_subscribe', 'first'])
    connection.setState('connected')
    expect(connection.journal).toEqual(['page_subscribe', 'first'])

    pages.releaseOnSession()
    expect(connection.journal).toEqual([
      'page_subscribe',
      'first',
      'page_subscribe',
      'second',
    ])
    expectNoSubscriptionWaiters(pages, connection)
    await expect(first).resolves.toBe(1)
    await expect(second).resolves.toBe(2)
  })

  it.each(['navigation', 'abort'] as const)(
    'does not release a second callback canceled by the first through %s',
    async (change) => {
      const connection = fakeConnection()
      const pages = new PageSubscription(connection, new ScopeManager())
      pages.subscribe('main')
      const controller = new AbortController()
      const first = pages.runWhenSubscribed(() => {
        if (change === 'navigation') pages.subscribe('next')
        else controller.abort()

        return Promise.resolve()
      })
      const run = vi.fn(() => Promise.resolve())
      const second = pages.runWhenSubscribed(run, controller.signal)
      pages.releaseOnSession()

      await first
      await expect(second).rejects.toMatchObject({ name: 'AbortError' })
      expect(run).not.toHaveBeenCalled()
      expectNoSubscriptionWaiters(pages, connection)
    },
  )

  it.each([
    { ready: true, throws: true },
    { ready: true, throws: false },
    { ready: false, throws: true },
    { ready: false, throws: false },
  ])(
    'passes through callback failure and cleans up ($ready, $throws)',
    async ({ ready, throws }) => {
      const connection = fakeConnection()
      const pages = new PageSubscription(connection, new ScopeManager())
      pages.subscribe('main')
      if (ready) pages.releaseOnSession()
      const failure = new Error('Action failed')
      const controller = new AbortController()
      const remove = vi.spyOn(controller.signal, 'removeEventListener')
      const result = pages.runWhenSubscribed(() => {
        if (throws) throw failure

        return Promise.reject(failure)
      }, controller.signal)
      if (!ready) pages.releaseOnSession()

      expectNoSubscriptionWaiters(pages, connection)
      expect(remove).toHaveBeenCalledTimes(1)
      await expect(result).rejects.toBe(failure)
    },
  )

  it('hands the result to the action lifecycle once dispatch starts', async () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.subscribe('main')
    const controller = new AbortController()
    let finish!: (result: string) => void
    const action = new Promise<string>((resolve) => {
      finish = resolve
    })
    const result = pages.runWhenSubscribed(() => action, controller.signal)
    pages.releaseOnSession()
    expectNoSubscriptionWaiters(pages, connection)
    controller.abort()
    pages.unsubscribe()
    connection.setState('disconnected')
    finish('action result')

    await expect(result).resolves.toBe('action result')
  })
})

describe('PageSubscription leader recovery', () => {
  it.each(['anchor', 'pageIndex'] as const)(
    'reports fresh %s window coordinates on the same ready scope',
    (position) => {
      const connection = fakeConnection()
      const scopes = new ScopeManager()
      const pages = new PageSubscription(connection, scopes)
      pages.releaseOnSession()
      const scope = pages.subscribe('picture', { room: '42' })
      pages.ingestPageResponse('picture', { data: { leader: 'old' } })
      connection.windows = {
        placement: {
          filter: { query: 'current' },
          sort: [{ field: 'name', direction: 'desc' }],
          limit: 25,
          anchor: position === 'anchor' ? { name: 'last', id: 9 } : null,
          anchorDirection: 'before',
          pageIndex: position === 'pageIndex' ? 4 : null,
          rendered: ['name', 'state'],
        },
      }
      connection.requestRecovery('picture', { room: '42' })
      expect(connection.sent).toEqual([
        { type: 'page_subscribe', page: 'picture', params: { room: '42' } },
        {
          type: 'page_subscribe',
          page: 'picture',
          params: { room: '42' },
          tableWindows: connection.windows,
        },
      ])
      expect(scopes.page()).toBe(scope)
      expect(scope.data.signal('leader').get()).toBe('old')
      expect(pages.pageLoading.get()).toBe(false)
      expect(connection.forgotPageFrames).toBe(1)
      pages.ingestPageResponse('picture', { data: { leader: 'new' } })
      expect(scopes.page()).toBe(scope)
      expect(scope.data.signal('leader').get()).toBe('new')
      expect(pages.pageError.get()).toBeNull()
    },
  )

  it('ignores late pages and differing param values, and compares map keys by value', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.releaseOnSession()
    pages.subscribe('picture', { room: '42', nested: { a: 1, b: [2, 3] } })
    connection.requestRecovery('old', {})
    connection.requestRecovery('picture', {
      room: 42,
      nested: { a: 1, b: [2, 3] },
    })
    connection.requestRecovery('picture', {
      room: '42',
      nested: { a: 1, b: [3, 2] },
    })
    connection.requestRecovery('picture', { room: '42' })
    expect(connection.sent).toHaveLength(1)
    connection.requestRecovery('picture', {
      nested: { b: [2, 3], a: 1 },
      room: '42',
    })
    expect(connection.sent).toHaveLength(2)
    pages.subscribe('picture', { room: '43' })
    connection.requestRecovery('picture', { room: '42' })
    expect(connection.sent).toHaveLength(3)
    pages.unsubscribe()
    connection.requestRecovery('picture', { room: '43' })
    expect(connection.sent).toHaveLength(4)
  })

  it('holds recovery for a frozen connection and sends the current page on release', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = new PageSubscription(connection, scopes)
    pages.releaseOnSession()
    const scope = pages.subscribe('picture')
    pages.ingestPageResponse('picture', { data: { leader: 'old' } })
    connection.setProtectedMode({
      ...PROTECTED_MODE_INACTIVE,
      active: true,
      operation: 'restore',
      message: 'Held',
    })
    connection.requestRecovery('picture')
    expect(connection.sent).toHaveLength(1)
    expect(scopes.page()).toBe(scope)
    expect(pages.pageLoading.get()).toBe(false)
    pages.subscribe('another', { id: 'new' })
    connection.setProtectedMode(PROTECTED_MODE_INACTIVE)
    expect(connection.sent.at(-1)).toEqual({
      type: 'page_subscribe',
      page: 'another',
      params: { id: 'new' },
    })
    expect(connection.sent).toHaveLength(2)
  })

  it('waits for the session on this socket and releases the subscription once', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.subscribe('picture')
    connection.requestRecovery('picture')
    expect(connection.sent).toEqual([])
    pages.releaseOnSession()
    pages.releaseOnSession()
    expect(connection.sent).toEqual([
      { type: 'page_subscribe', page: 'picture', params: {} },
    ])
  })
})

describe('PageSubscription.refuseUnbuilt', () => {
  it('leaves the current page, drops its data and held frames, and ignores late answers', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = new PageSubscription(connection, scopes)
    pages.releaseOnSession()
    pages.subscribe('main')
    pages.ingestPageResponse('main', { data: { greeting: 'hi' } })
    const forgotten = connection.forgotPageFrames

    pages.refuseUnbuilt('hilos_roles')

    expect(connection.sent).toEqual([
      { type: 'page_subscribe', page: 'main', params: {} },
      { type: 'page_unsubscribe', page: 'main' },
    ])
    expect(pages.pageKey()).toBeUndefined()
    expect(scopes.page()).toBeUndefined()
    expect(connection.forgotPageFrames).toBe(forgotten + 1)
    expect(pages.pageError.get()).toEqual({
      page: 'hilos_roles',
      httpCode: 404,
      errorCode: 'not_served',
      title: 'Page Not Found',
      message: 'The page you are looking for does not exist.',
    })
    expect(pages.pageLoading.get()).toBe(false)
    expect(
      pages.ingestPageResponse('main', { data: { greeting: 'late' } }),
    ).toBe(false)
    expect(
      pages.handleSubscriptionError({
        page: 'main',
        httpCode: 403,
        errorCode: 'forbidden',
        title: 'Forbidden',
        message: 'late',
      }),
    ).toBe(false)
    expect(pages.pageError.get()?.errorCode).toBe('not_served')
  })

  it('never subscribes a cold refusal after the handshake, reconnect, or thaw', () => {
    const connection = fakeConnection('connecting')
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.refuseUnbuilt('hilos_roles')
    expect(connection.sent).toEqual([])
    expect(pages.pageLoading.get()).toBe(false)
    connection.setState('connected')
    pages.releaseOnSession()
    connection.setState('disconnected')
    connection.setState('connected')
    pages.releaseOnSession()
    connection.setProtectedMode(PROTECTED_MODE_INACTIVE)
    expect(connection.sent).toEqual([])
    expect(pages.pageError.get()?.errorCode).toBe('not_served')

    pages.subscribe('main')
    expect(pages.pageError.get()).toBeNull()
    expect(pages.pageLoading.get()).toBe(true)
    expect(connection.sent).toEqual([
      { type: 'page_subscribe', page: 'main', params: {} },
    ])
  })
})

describe('PageSubscription', () => {
  it('subscribing while connected sends one page_subscribe frame', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = new PageSubscription(connection, scopes)
    pages.releaseOnSession()

    pages.subscribe('main')

    expect(connection.sent).toEqual([
      { type: 'page_subscribe', page: 'main', params: {} },
    ])
    expect(pages.pageKey()).toBe('main')
    expect(scopes.page()?.key).toBe('main')
  })

  it('reports the windows its tables hold, and no key at all when they hold none', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.releaseOnSession()

    pages.subscribe('main')
    expect(connection.sent[0]).toEqual({
      type: 'page_subscribe',
      page: 'main',
      params: {},
    })

    connection.windows = {
      settings: {
        filter: {},
        sort: [{ field: 'key', direction: 'asc' }],
        limit: 25,
        anchor: null,
        anchorDirection: 'after',
        pageIndex: 2,
      },
    }
    pages.subscribe('other')

    // What separates a navigation from a reconnect without a flag saying which: on the first
    // the new page's tables have not mounted, on the second they are holding their windows,
    // and the server serves back whatever the frame reports (HIL-642).
    expect(connection.sent[1]).toEqual({
      type: 'page_subscribe',
      page: 'other',
      params: {},
      tableWindows: connection.windows,
    })
  })

  it('subscribing while disconnected sends when the new socket answers', () => {
    const connection = fakeConnection('disconnected')
    const pages = new PageSubscription(connection, new ScopeManager())

    pages.subscribe('main', { tab: 'open' })
    expect(connection.sent).toEqual([])

    connection.setState('connecting')
    connection.setState('connected')
    expect(connection.sent).toEqual([])

    pages.releaseOnSession()
    expect(connection.sent).toEqual([
      { type: 'page_subscribe', page: 'main', params: { tab: 'open' } },
    ])
  })

  it('re-sends the current subscription when the reconnected socket answers', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.releaseOnSession()
    pages.subscribe('main')

    connection.setState('reconnecting')
    connection.setState('connected')

    // The socket is back, but the backend does not know it yet: a subscribe sent
    // here would be judged against a connection nobody has heard of.
    expect(connection.sent).toHaveLength(1)

    pages.releaseOnSession()

    expect(connection.sent).toHaveLength(2)
    expect(connection.sent[1]).toEqual({
      type: 'page_subscribe',
      page: 'main',
      params: {},
    })
  })

  it('subscribing another page atomically replaces scope and subscription', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = new PageSubscription(connection, scopes)
    pages.releaseOnSession()

    const first = pages.subscribe('main')
    first.data.set('marker', 1)
    pages.subscribe('profile')

    expect(scopes.page()?.key).toBe('profile')
    expect(scopes.page()?.data.signal('marker').get()).toBeUndefined()
    expect(connection.sent.map((frame) => frame['page'])).toEqual([
      'main',
      'profile',
    ])
  })

  it('unsubscribe drops the page scope and tells the backend', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = new PageSubscription(connection, scopes)
    pages.releaseOnSession()
    pages.subscribe('main')

    pages.unsubscribe()

    expect(pages.pageKey()).toBeUndefined()
    expect(scopes.page()).toBeUndefined()
    expect(connection.sent[1]).toEqual({
      type: 'page_unsubscribe',
      page: 'main',
    })
  })

  it('ingests a payload for the current page into the page scope', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = new PageSubscription(connection, scopes)
    pages.releaseOnSession()
    pages.subscribe('main')

    const applied = pages.ingestPageResponse('main', {
      entities: { user: { id: 7, name: 'Ada' } },
      data: { title: 'Lobby' },
    })

    expect(applied).toBe(true)
    expect(scopes.page()?.data.signal('title').get()).toBe('Lobby')
    const ref = scopes.page()?.data.signal('user').get() as EntityRef
    expect(scopes.entitySignal(ref).get()?.fields['name']).toBe('Ada')
  })

  it('drops a late payload for a page the client has left', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = new PageSubscription(connection, scopes)
    pages.releaseOnSession()
    pages.subscribe('main')
    pages.subscribe('profile')

    const applied = pages.ingestPageResponse('main', {
      data: { title: 'Lobby' },
    })

    expect(applied).toBe(false)
    expect(scopes.page()?.data.signal('title').get()).toBeUndefined()
  })

  it('records a subscription error for the current page', () => {
    const pages = new PageSubscription(fakeConnection(), new ScopeManager())
    pages.subscribe('user', { id: '9' })

    const recorded = pages.handleSubscriptionError({
      page: 'user',
      httpCode: 404,
      errorCode: 'not_found',
      title: 'Server chose this title',
      message: 'Server chose this description',
    })

    expect(recorded).toBe(true)
    expect(pages.pageError.get()?.httpCode).toBe(404)
    expect(pages.pageError.get()?.errorCode).toBe('not_found')
    expect(pages.pageError.get()?.title).toBe('Server chose this title')
    expect(pages.pageError.get()?.message).toBe('Server chose this description')
  })

  it('keeps a server transition line, then lets the initiator move on', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = new PageSubscription(connection, scopes)
    pages.releaseOnSession()
    pages.subscribe('hilos_i18n_country', { id: '7' })
    pages.ingestPageResponse('hilos_i18n_country', { data: { name: 'France' } })
    const forgotten = connection.forgotPageFrames

    const recorded = pages.handleSubscriptionError({
      page: 'hilos_i18n_country',
      httpCode: 404,
      errorCode: 'not_found',
      title: 'Page Not Found',
      message: 'The page you are looking for does not exist.',
      transitionMessage: 'It was deleted just now.',
    })

    expect(recorded).toBe(true)
    expect(pages.pageError.get()?.transitionMessage).toBe(
      'It was deleted just now.',
    )
    expect(pages.pageKey()).toBe('hilos_i18n_country')
    expect(scopes.page()?.data.signal('name').get()).toBeUndefined()
    expect(connection.forgotPageFrames).toBe(forgotten + 1)
    expect(
      pages.handleSubscriptionError({
        page: 'hilos_i18n_country_names',
        httpCode: 404,
        errorCode: 'not_found',
        title: 'Page Not Found',
        message: 'The page you are looking for does not exist.',
        transitionMessage: 'Late for another page.',
      }),
    ).toBe(false)
    expect(pages.pageError.get()?.transitionMessage).toBe(
      'It was deleted just now.',
    )

    pages.subscribe('main')
    expect(pages.pageKey()).toBe('main')
    expect(pages.pageError.get()).toBeNull()
    expect(
      pages.handleSubscriptionError({
        page: 'hilos_i18n_country',
        httpCode: 404,
        errorCode: 'not_found',
        title: 'Page Not Found',
        message: 'The page you are looking for does not exist.',
        transitionMessage: 'It was deleted just now.',
      }),
    ).toBe(false)
    expect(pages.pageError.get()).toBeNull()

    pages.handleSubscriptionError({
      page: 'main',
      httpCode: 404,
      errorCode: 'not_found',
      title: 'Page Not Found',
      message: 'The page you are looking for does not exist.',
      transitionMessage: 'It was deleted just now.',
    })
    const applied = pages.ingestPageResponse('main', {
      data: { title: 'Home' },
    })
    expect(applied).toBe(true)
    expect(pages.pageError.get()).toBeNull()
  })

  it('drops a subscription error for a page the client has left', () => {
    const pages = new PageSubscription(fakeConnection(), new ScopeManager())
    pages.subscribe('user', { id: '9' })
    pages.subscribe('main')

    const recorded = pages.handleSubscriptionError({
      page: 'user',
      httpCode: 404,
      errorCode: 'not_found',
      title: 'Page Not Found',
      message: 'Resource #9 not found',
    })

    expect(recorded).toBe(false)
    expect(pages.pageError.get()).toBeNull()
  })

  it('clears the page error when subscribing another page', () => {
    const pages = new PageSubscription(fakeConnection(), new ScopeManager())
    pages.subscribe('user', { id: '9' })
    pages.handleSubscriptionError({
      page: 'user',
      httpCode: 403,
      errorCode: 'forbidden',
      title: 'Forbidden',
      message: 'Access forbidden',
    })

    pages.subscribe('main')

    expect(pages.pageError.get()).toBeNull()
  })

  it('clears the page error on unsubscribe', () => {
    const pages = new PageSubscription(fakeConnection(), new ScopeManager())
    pages.subscribe('user', { id: '9' })
    pages.handleSubscriptionError({
      page: 'user',
      httpCode: 403,
      errorCode: 'forbidden',
      title: 'Forbidden',
      message: 'Access forbidden',
    })

    pages.unsubscribe()

    expect(pages.pageError.get()).toBeNull()
  })

  it('clears the page error in place without leaving the page', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.releaseOnSession()
    pages.subscribe('user', { id: '9' })
    pages.handleSubscriptionError({
      page: 'user',
      httpCode: 401,
      errorCode: 'unauthorized',
      title: 'Unauthorized',
      message: 'Authentication required',
    })
    const sentBefore = connection.sent.length

    pages.clearPageError()

    expect(pages.pageError.get()).toBeNull()
    // The page stays subscribed: no unsubscribe/subscribe frame goes out.
    expect(pages.pageKey()).toBe('user')
    expect(connection.sent.length).toBe(sentBefore)
  })
})

describe('holding the subscribe until the session answers', () => {
  it('sends nothing until released, then sends the held page', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const held = new PageSubscription(connection, scopes)

    held.subscribe('hilos_settings')
    expect(connection.sent).toEqual([])

    held.releaseOnSession()

    expect(connection.sent).toMatchObject([
      { type: 'page_subscribe', page: 'hilos_settings' },
    ])
  })
})

describe('reacting to a rights change on the open page', () => {
  it('a page_response for the current page clears a standing error', () => {
    const scopes = new ScopeManager()
    const pages = new PageSubscription(fakeConnection(), scopes)
    pages.releaseOnSession()
    pages.subscribe('hilos_backup')
    pages.handleSubscriptionError({
      page: 'hilos_backup',
      httpCode: 403,
      errorCode: 'forbidden',
      title: 'Forbidden',
      message: 'Access forbidden',
    })

    const applied = pages.ingestPageResponse('hilos_backup', {
      data: { archives: 3 },
    })

    expect(applied).toBe(true)
    expect(pages.pageError.get()).toBeNull()
    expect(scopes.page()?.data.signal('archives').get()).toBe(3)
  })

  it('a page_response leaves the 401 the auth gate is holding', () => {
    const scopes = new ScopeManager()
    const pages = new PageSubscription(fakeConnection(), scopes)
    pages.releaseOnSession()
    pages.subscribe('profile')
    pages.handleSubscriptionError({
      page: 'profile',
      httpCode: 401,
      errorCode: 'unauthorized',
      title: 'Unauthorized',
      message: 'Authentication required',
    })

    const applied = pages.ingestPageResponse('profile', {
      data: { name: 'Ada' },
    })

    // The sign-in surface stays on screen: a 401 is the gate's, and only its
    // resume lifts it — postponed while the session owes an ack (HIL-422), so a
    // page_response landing in that window must not un-gate the page.
    expect(applied).toBe(true)
    expect(pages.pageError.get()?.httpCode).toBe(401)
    expect(pages.pageLoading.get()).toBe(false)
    // The data is ingested all the same, which is why clearing the error later
    // needs no round trip.
    expect(scopes.page()?.data.signal('name').get()).toBe('Ada')

    pages.clearPageError()

    expect(pages.pageError.get()).toBeNull()
  })

  it('denyCurrentPage draws a 403 and empties the page scope', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = new PageSubscription(connection, scopes)
    pages.releaseOnSession()
    pages.subscribe('hilos_backup')
    pages.ingestPageResponse('hilos_backup', { data: { archives: 3 } })
    const sentBefore = connection.sent.length

    pages.denyCurrentPage()

    expect(pages.pageError.get()).toEqual({
      page: 'hilos_backup',
      httpCode: 403,
      errorCode: 'forbidden',
      title: 'Forbidden',
      message: 'You do not have access to this page.',
    })
    expect(pages.pageLoading.get()).toBe(false)
    // The rows are gone, not hidden behind the error surface.
    expect(scopes.page()?.data.signal('archives').get()).toBeUndefined()
    // The subscription is untouched and nothing is asked of the server.
    expect(pages.pageKey()).toBe('hilos_backup')
    expect(connection.sent.length).toBe(sentBefore)
  })

  it('awaitPageAnswer clears the error and raises loading', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.releaseOnSession()
    pages.subscribe('hilos_users')
    pages.handleSubscriptionError({
      page: 'hilos_users',
      httpCode: 403,
      errorCode: 'forbidden',
      title: 'Forbidden',
      message: 'Access forbidden',
    })
    const sentBefore = connection.sent.length

    pages.awaitPageAnswer()

    expect(pages.pageError.get()).toBeNull()
    expect(pages.pageLoading.get()).toBe(true)
    expect(connection.sent.length).toBe(sentBefore)
  })

  it('awaitPageAnswer empties the page scope of a page that was answered', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = new PageSubscription(connection, scopes)
    pages.releaseOnSession()
    pages.subscribe('hilos_users')
    pages.ingestPageResponse('hilos_users', { data: { rows: 4 } })
    const sentBefore = connection.sent.length

    pages.awaitPageAnswer()

    // Waiting is not hiding: the identity these rows were decided for is gone,
    // and a view reading the scope directly would keep rendering them (HIL-652).
    expect(scopes.page()?.data.signal('rows').get()).toBeUndefined()
    expect(pages.pageError.get()).toBeNull()
    expect(pages.pageLoading.get()).toBe(true)
    // The subscription is untouched and nothing is asked of the server.
    expect(pages.pageKey()).toBe('hilos_users')
    expect(connection.sent.length).toBe(sentBefore)
  })

  it('awaitPageAnswer does nothing to the scope when no page is subscribed', () => {
    const scopes = new ScopeManager()
    const pages = new PageSubscription(fakeConnection(), scopes)
    pages.releaseOnSession()

    pages.awaitPageAnswer()

    expect(scopes.page()).toBeUndefined()
    expect(pages.pageError.get()).toBeNull()
  })

  it('a refusal from the server empties the page scope too', () => {
    const scopes = new ScopeManager()
    const pages = new PageSubscription(fakeConnection(), scopes)
    pages.releaseOnSession()
    pages.subscribe('hilos_users')
    pages.ingestPageResponse('hilos_users', { data: { rows: 4 } })

    pages.handleSubscriptionError({
      page: 'hilos_users',
      httpCode: 403,
      errorCode: 'forbidden',
      title: 'Forbidden',
      message: 'Access denied',
    })

    // A refused page holds no data, whoever refused it: the page scope is the
    // most specific layer of entity resolution, so a row left there outranks the
    // session's own copy and no fan-out arrives to correct it.
    expect(scopes.page()?.data.signal('rows').get()).toBeUndefined()
    expect(pages.pageError.get()?.httpCode).toBe(403)
  })

  it('denyCurrentPage does nothing when no page is subscribed', () => {
    const pages = new PageSubscription(fakeConnection(), new ScopeManager())
    pages.releaseOnSession()

    pages.denyCurrentPage()

    expect(pages.pageError.get()).toBeNull()
  })
})

describe('page frame lifetime', () => {
  it('subscribing drops the frames of the page being left', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.releaseOnSession()

    pages.subscribe('hilos_logs')

    expect(connection.forgotPageFrames).toBe(1)
  })

  it('unsubscribing drops the frames of the page being left', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.releaseOnSession()
    pages.subscribe('hilos_logs')

    pages.unsubscribe()

    expect(connection.forgotPageFrames).toBe(2)
  })

  it('putting the page back to waiting drops its frames', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.releaseOnSession()
    pages.subscribe('hilos_logs')
    pages.ingestPageResponse('hilos_logs', { data: { nodes: 2 } })

    pages.awaitPageAnswer()

    expect(connection.forgotPageFrames).toBe(2)
  })

  it('a refusal drops the frames of the refused page', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.releaseOnSession()
    pages.subscribe('hilos_users')

    pages.handleSubscriptionError({
      page: 'hilos_users',
      httpCode: 403,
      errorCode: 'forbidden',
      title: 'Forbidden',
      message: 'Access denied',
    })

    expect(connection.forgotPageFrames).toBe(2)
  })

  it('a re-subscribe after a reconnect keeps the frames', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.releaseOnSession()
    pages.subscribe('hilos_logs')

    connection.setState('reconnecting')
    connection.setState('connected')
    pages.releaseOnSession()

    // The socket dropped, the page did not: the backend re-sends the frame on
    // the new subscribe, and blanking the screen on a tremor costs more than a
    // moment of slightly stale content.
    expect(connection.sent).toHaveLength(2)
    expect(connection.forgotPageFrames).toBe(1)
  })
})

describe('pageLoading', () => {
  it('is raised by a subscribe and lowered by an empty answer', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = new PageSubscription(connection, scopes)
    pages.releaseOnSession()

    pages.subscribe('hilos_about')
    expect(pages.pageLoading.get()).toBe(true)

    const applied = pages.ingestPageResponse('hilos_about', {})

    expect(applied).toBe(true)
    expect(pages.pageLoading.get()).toBe(false)
  })
})

describe('a subscribe the freeze refused', () => {
  const frozen: ProtectedModeStatus = {
    ...PROTECTED_MODE_INACTIVE,
    active: true,
    acceptsPass: true,
  }
  const admitted: ProtectedModeStatus = {
    ...PROTECTED_MODE_INACTIVE,
    acceptsPass: true,
  }

  it('is sent once when the admission frame arrives', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    connection.setProtectedMode(frozen)
    pages.releaseOnSession()

    pages.subscribe('main', { room: 7 })
    expect(connection.sent).toEqual([])

    connection.setProtectedMode(admitted)

    expect(connection.sent).toEqual([
      { type: 'page_subscribe', page: 'main', params: { room: 7 } },
    ])
  })

  it('is not sent again when the server already holds it', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.releaseOnSession()
    pages.subscribe('main')
    connection.setProtectedMode(frozen)

    connection.setProtectedMode(admitted)

    expect(connection.sent.length).toBe(1)
  })

  it('carries a refused re-subscribe without raising pageLoading', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.releaseOnSession()
    pages.subscribe('main')
    pages.ingestPageResponse('main', {})
    connection.setState('connecting')
    connection.setProtectedMode(frozen)
    connection.setState('connected')
    pages.releaseOnSession()
    expect(connection.sent.length).toBe(1)

    connection.setProtectedMode(admitted)

    expect(connection.sent.length).toBe(2)
    expect(connection.sent[1]).toMatchObject({
      type: 'page_subscribe',
      page: 'main',
    })
    expect(pages.pageLoading.get()).toBe(false)
  })

  it('stays unsent while the frame still says the mode holds', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    connection.setProtectedMode(frozen)
    pages.releaseOnSession()
    pages.subscribe('main')

    connection.setProtectedMode({ ...frozen, passIssued: true })

    expect(connection.sent).toEqual([])
  })

  it('waits for the session answer when admitted before it', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    connection.setProtectedMode(frozen)
    pages.subscribe('main')

    connection.setProtectedMode(admitted)
    expect(connection.sent).toEqual([])

    pages.releaseOnSession()

    expect(connection.sent).toMatchObject([
      { type: 'page_subscribe', page: 'main' },
    ])
    expect(connection.sent.length).toBe(1)
  })
})

// A direct page ingress must prepare real controller numbers before a store can notify.
describe('PageSubscription presentation boundary', () => {
  function fixture() {
    const connection = new HilosConnection({ url: 'ws://test/ws' })
    const scopes = new ScopeManager()
    const pages = new PageSubscription(connection, scopes)
    pages.subscribe('main')
    pages.ingestPageResponse('main', {
      entities: { user: { id: 7, name: 'old' } },
    })
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: () => {},
      frame: { columns: [] },
    })
    controller.ingestSubscriptionWindow(
      [{ rowKey: 'a', slots: { user: { type: 'user', id: 7 } } }],
      1,
      true,
      null,
      null,
      10,
      undefined,
      [],
    )
    connection.registerTableWindow('settings', controller)
    let extent = 91.5
    const capture = vi.fn((request: TableGeometryCaptureRequest) => ({
      records: request.records.map((measurement) => ({
        measurement,
        extent: { main: extent, work: 0, detail: 0, gap: 0, total: extent },
        workGap: 0,
      })),
    }))
    const registration = controller.geometry.register(capture)
    return {
      connection,
      scopes,
      pages,
      controller,
      capture,
      registration,
      destroy: () => {
        extent = 0
      },
    }
  }

  it('prepares before a synchronous entity subscriber destroys the old representation', () => {
    const f = fixture()
    const stop = subscribeSignal(
      f.scopes.entitySignal({ type: 'user', id: 7 }),
      () => {
        expect(f.registration.record('a', 'wide')?.live?.total).toBe(91.5)
        f.destroy()
      },
    )
    expect(
      f.pages.ingestPageResponse('main', {
        entities: { user: { id: 7, name: 'new' } },
      }),
    ).toBe(true)
    expect(f.capture).toHaveBeenCalledTimes(1)
    expect(
      f.scopes.entitySignal({ type: 'user', id: 7 }).get()?.fields['name'],
    ).toBe('new')
    expect(f.pages.pageLoading.get()).toBe(false)
    stop()
    f.pages.unsubscribe()
    f.controller.dispose()
  })

  it('keeps the current-page and missing-scope guards ahead of preparation', () => {
    const f = fixture()
    const ticket = f.registration.measurement('a', 'wide')
    expect(
      f.pages.ingestPageResponse('other', {
        entities: { user: { id: 7, name: 'foreign' } },
      }),
    ).toBe(false)
    expect(
      f.scopes.entitySignal({ type: 'user', id: 7 }).get()?.fields['name'],
    ).toBe('old')
    f.scopes.dropPage()
    expect(f.pages.ingestPageResponse('main', {})).toBe(false)
    expect(f.capture).not.toHaveBeenCalled()
    expect(f.registration.measurement('a', 'wide')).toEqual(ticket)
    f.pages.unsubscribe()
    f.controller.dispose()
  })

  it('unwinds a throwing normalizer and prepares the next direct ingress again', () => {
    const f = fixture()
    expect(() =>
      f.pages.ingestPageResponse('main', {
        entities: { user: { id: 7 } },
        data: { user: 'collision' },
      }),
    ).toThrow("Payload key 'user' is both an entity slot and a data key")
    expect(f.capture).toHaveBeenCalledTimes(1)
    expect(f.registration.record('a', 'wide')?.live).toBeNull()
    expect(
      f.pages.ingestPageResponse('main', {
        entities: { user: { id: 7, name: 'next' } },
      }),
    ).toBe(true)
    expect(f.capture).toHaveBeenCalledTimes(2)
    f.pages.unsubscribe()
    f.controller.dispose()
  })

  it.each([401, 403, 404])(
    'preserves page error recovery and loading for HTTP %s inside the boundary',
    (httpCode) => {
      const f = fixture()
      f.pages.handleSubscriptionError({
        page: 'main',
        httpCode,
        errorCode: 'denied',
        title: 'Denied',
        message: 'Denied',
      })
      expect(
        f.pages.ingestPageResponse('main', { data: { value: 'accepted' } }),
      ).toBe(true)
      expect(f.pages.pageError.get()?.httpCode ?? null).toBe(
        httpCode === 401 ? 401 : null,
      )
      expect(f.scopes.page()?.data.signal('value').get()).toBe('accepted')
      expect(f.pages.pageLoading.get()).toBe(false)
      expect(f.capture).toHaveBeenCalledTimes(1)
      f.pages.unsubscribe()
      f.controller.dispose()
    },
  )
})
