import { describe, expect, it, vi } from 'vitest'
import { applyServerTime } from '../../src/session/serverClock.js'
import {
  bindTimeZoneState,
  hilosTimeZone,
} from '../../src/timeZone/timeZoneState.js'
import {
  bindPageScope,
  PAGE_SIGNAL_SCHEMAS,
  pageSubscriptionFor,
} from '../../src/subscription/bindPageScope.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import {
  SIGNAL_TYPE_PAGE_RESPONSE,
  SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR,
} from '../../src/protocol/constants.js'
import {
  HilosConnection,
  type ConnectionState,
} from '../../src/connection/HilosConnection.js'
import { type ProjectSignal } from '../../src/index.js'
import { bindTableViewport } from '../../src/subscription/bindTableViewport.js'
import { normalizeTableRow } from '../../src/state/normalizer.js'
import { subscribeSignal } from '../../src/state/signal.js'
import { type TableRow } from '../../src/state/TableRowsStore.js'
import { TableViewportController } from '../../src/table/TableViewportController.js'
import { type TableGeometryCaptureRequest } from '../../src/table/tableViewportGeometry.js'

/**
 * A connection double recording sent frames and replaying project signals. It
 * is the slice bindPageScope touches — the manager's `state`/`send`/`on('state')`
 * plus `on('projectSignal')` — cast to the full connection type.
 */
function fakeConnection() {
  const projectListeners: Array<(signal: ProjectSignal) => void> = []

  return {
    state: 'connected' as ConnectionState,
    reconnectDragging: false,
    sent: [] as Array<Record<string, unknown>>,
    send(text: string): boolean {
      this.sent.push(JSON.parse(text) as Record<string, unknown>)

      return true
    },
    // The manager drops the held page frames on every page change; this fake
    // buffers nothing, so there is nothing to drop.
    forgetPageFrames(): void {},
    tableWindowDescriptors: () => ({}),
    emitSession(timeZone: string): void {
      for (const listener of projectListeners) {
        listener({
          kind: 'project',
          type: 'handshake_response',
          data: { entities: { currentUser: { id: 7, timeZone } } },
          envelope: {},
        } as ProjectSignal)
      }
    },
    on(event: string, listener: (payload: never) => void): () => void {
      // The manager subscribes `state` in its constructor; this fake never
      // replays transitions, so only `projectSignal` listeners are recorded.
      if (event === 'projectSignal') {
        projectListeners.push(listener as (signal: ProjectSignal) => void)
      }

      return () => {}
    },
    emitPageResponse(page: string, payload: Record<string, unknown>): void {
      const signal = {
        kind: 'project',
        type: SIGNAL_TYPE_PAGE_RESPONSE,
        data: { page, payload },
        envelope: {},
      } as unknown as ProjectSignal
      for (const listener of projectListeners) {
        listener(signal)
      }
    },
    emitSubscriptionError(
      page: string,
      httpCode: number,
      errorCode: string,
      message: string,
    ): void {
      const signal = {
        kind: 'project',
        type: SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR,
        data: { page, httpCode, errorCode, title: 'Server title', message },
        envelope: {},
      } as unknown as ProjectSignal
      for (const listener of projectListeners) {
        listener(signal)
      }
    },
  }
}

describe('bindPageScope', () => {
  it('binds the real framework preparer after the session answer and captures one opening', () => {
    const connection = fakeConnection()
    const fullConnection = connection as unknown as HilosConnection
    const releaseZone = bindTimeZoneState(fullConnection)
    const now = vi
      .spyOn(Date, 'now')
      .mockReturnValue(Date.parse('2026-10-23T00:15:00Z'))
    try {
      const pages = bindPageScope(fullConnection, new ScopeManager())
      pages.subscribe('hilos_analytics')
      expect(connection.sent).toEqual([])
      connection.emitSession('America/New_York')
      applyServerTime(Date.parse('2026-10-24T01:15:00Z'))
      pages.releaseOnSession()
      const captured = {
        timeZone: 'America/New_York',
        weekStartTs: String(Date.parse('2026-10-19T04:00:00Z')),
        dayStartTs: String(Date.parse('2026-10-23T04:00:00Z')),
        hourStartTs: String(Date.parse('2026-10-24T01:00:00Z')),
      }
      expect(connection.sent).toEqual([
        { type: 'page_subscribe', page: 'hilos_analytics', params: captured },
      ])
      connection.emitSession('Asia/Tokyo')
      expect(hilosTimeZone.get()).toBe('Asia/Tokyo')
      pages.releaseOnSession()
      expect(connection.sent).toHaveLength(1)
      expect(connection.sent[0]?.params).toEqual(captured)
      pages.subscribe('hilos_analytics')
      expect(connection.sent[1]?.params).toEqual({
        timeZone: 'Asia/Tokyo',
        weekStartTs: String(Date.parse('2026-10-18T15:00:00Z')),
        dayStartTs: String(Date.parse('2026-10-23T15:00:00Z')),
        hourStartTs: String(Date.parse('2026-10-24T01:00:00Z')),
      })
      pages.subscribe('hilos_analytics_person', { userId: '7' })
      expect(connection.sent[2]).toEqual({
        type: 'page_subscribe',
        page: 'hilos_analytics_person',
        params: { userId: '7' },
      })
    } finally {
      releaseZone()
      now.mockRestore()
      applyServerTime(Date.now())
    }
  })

  it('looks up exactly the owner returned by the binding', () => {
    const connection = fakeConnection() as unknown as HilosConnection
    const pages = bindPageScope(connection, new ScopeManager())

    expect(pageSubscriptionFor(connection)).toBe(pages)
  })

  it('keeps the owners of two connections independent', () => {
    const first = fakeConnection() as unknown as HilosConnection
    const second = fakeConnection() as unknown as HilosConnection
    const firstPages = bindPageScope(first, new ScopeManager())
    const secondPages = bindPageScope(second, new ScopeManager())
    firstPages.subscribe('first')
    secondPages.subscribe('second')

    expect(pageSubscriptionFor(first)).toBe(firstPages)
    expect(pageSubscriptionFor(second)).toBe(secondPages)
    expect(pageSubscriptionFor(first).pageKey()).toBe('first')
    expect(pageSubscriptionFor(second).pageKey()).toBe('second')
  })

  it('refuses a connection that has no bound owner', () => {
    const connection = fakeConnection() as unknown as HilosConnection

    expect(() => pageSubscriptionFor(connection)).toThrow(
      'No page subscription is bound to this connection',
    )
  })

  it('exposes the page_response schema keyed for projectSchemas', () => {
    expect(PAGE_SIGNAL_SCHEMAS[SIGNAL_TYPE_PAGE_RESPONSE]).toBeDefined()
  })

  it('exposes the subscription_page_error schema keyed for projectSchemas', () => {
    expect(
      PAGE_SIGNAL_SCHEMAS[SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR],
    ).toBeDefined()
  })

  it('routes a subscription_page_error for the current page into the page error', () => {
    const connection = fakeConnection()
    const pages = bindPageScope(
      connection as unknown as HilosConnection,
      new ScopeManager(),
    )

    pages.subscribe('user', { id: '9' })
    connection.emitSubscriptionError(
      'user',
      404,
      'not_found',
      'Resource #9 not found',
    )

    expect(pages.pageError.get()?.httpCode).toBe(404)
  })

  it('returns a manager that subscribes the page over the connection', () => {
    const connection = fakeConnection()
    const pages = bindPageScope(
      connection as unknown as HilosConnection,
      new ScopeManager(),
    )
    pages.releaseOnSession()

    pages.subscribe('main')

    expect(connection.sent).toEqual([
      { type: 'page_subscribe', page: 'main', params: {} },
    ])
    expect(pages.pageKey()).toBe('main')
  })

  it('ingests a page_response for the current page into the page scope', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = bindPageScope(
      connection as unknown as HilosConnection,
      scopes,
    )

    pages.subscribe('main')
    connection.emitPageResponse('main', { data: { greeting: 'hi' } })

    expect(scopes.page()?.data.signal('greeting').get()).toBe('hi')
  })

  it('lets the windows section past the scope store, unstored', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = bindPageScope(
      connection as unknown as HilosConnection,
      scopes,
    )

    pages.subscribe('main')
    connection.emitPageResponse('main', {
      data: { greeting: 'hi' },
      windows: {
        settings: {
          rows: [{ rowKey: 'a', slots: {} }],
          sort: [],
          limit: 10,
          totalCount: 1,
          totalExact: true,
          firstAnchor: null,
          lastAnchor: null,
        },
      },
    })

    // A window is held by the table's own controller, which normalizes its rows without
    // storing them here: a copy in the scope would be a second holder of one window, and
    // the two would part company on the first delta (HIL-642).
    expect(scopes.page()?.data.signal('greeting').get()).toBe('hi')
    expect(scopes.page()?.data.signal('windows').get()).toBeUndefined()
    expect(scopes.page()?.tables.signal('settings').get()).toEqual([])
  })

  it('drops a page_response for a page other than the current one', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = bindPageScope(
      connection as unknown as HilosConnection,
      scopes,
    )

    pages.subscribe('main')
    connection.emitPageResponse('profile', { data: { greeting: 'hi' } })

    expect(scopes.page()?.data.signal('greeting').get()).toBeUndefined()
  })

  it('applies the binding entity types to every page payload', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = bindPageScope(
      connection as unknown as HilosConnection,
      scopes,
      {
        entityTypes: { author: 'user' },
      },
    )

    pages.subscribe('main')
    connection.emitPageResponse('main', {
      entities: { author: { id: 7, name: 'Ada' } },
    })

    // The slot aliased `author` resolves under the canonical `user` type.
    const ref = scopes.page()?.data.signal('author').get() as
      { type: string; id: number } | undefined
    expect(ref?.type).toBe('user')
    expect(ref?.id).toBe(7)
  })
})

// Drive the real connection parser and emitter: page-scope registration precedes table binding.
describe('page and table presentation ingress', () => {
  function fixture() {
    const listeners = new Map<string, (event: { data?: unknown }) => void>()
    const connection = new HilosConnection({
      url: 'ws://test/ws',
      projectSchemas: PAGE_SIGNAL_SCHEMAS,
      webSocketFactory: () => ({
        send: () => {},
        close: () => {},
        addEventListener: (type, listener) => {
          listeners.set(type, listener)
        },
      }),
    })
    const scopes = new ScopeManager()
    const pages = bindPageScope(connection, scopes)
    pages.subscribe('main')
    const scope = scopes.page()!
    const initial = normalizeTableRow(scope, {
      rowKey: 'a',
      slots: { user: { id: 7, name: 'old' } },
    })
    const events: string[] = []
    let extent = 107.25
    const table = (tableKey: string) => {
      const controller = new TableViewportController<TableRow>({
        resolve: (row) => row,
        sendViewport: () => {},
        frame: { columns: [] },
      })
      controller.ingestSubscriptionWindow(
        [initial],
        1,
        true,
        null,
        null,
        10,
        undefined,
        [],
      )
      const capture = vi.fn((request: TableGeometryCaptureRequest) => {
        events.push(`${tableKey}:capture`)
        return {
          records: request.records.map((measurement) => ({
            measurement,
            extent: { main: extent, work: 0, detail: 0, gap: 0, total: extent },
            workGap: 0,
          })),
        }
      })
      const registration = controller.geometry.register(capture)
      return {
        controller,
        registration,
        capture,
        bind: () =>
          bindTableViewport(
            connection,
            scopes,
            { page: 'main', tableKey },
            controller,
          ),
      }
    }
    connection.connect()
    const send = (page: string, payload: Record<string, unknown>) =>
      listeners.get('message')?.({
        data: JSON.stringify({
          type: SIGNAL_TYPE_PAGE_RESPONSE,
          data: { page, payload },
        }),
      })
    const window = {
      rows: [{ rowKey: 'a', slots: { user: { id: 7, name: 'new' } } }],
      sort: [],
      limit: 10,
      totalCount: 1,
      totalExact: true,
      firstAnchor: null,
      lastAnchor: null,
    }
    return {
      connection,
      scopes,
      pages,
      scope,
      table,
      send,
      window,
      events,
      destroy: () => {
        extent = 0
      },
    }
  }

  it('captures every registered controller once before the early scope listener writes', () => {
    const f = fixture()
    const first = f.table('first')
    const second = f.table('second')
    const unbindFirst = first.bind()
    const unbindSecond = second.bind()
    let writes = 0
    const stop = subscribeSignal(
      f.scope.entities.signal({ type: 'user', id: 7 }),
      () => {
        if (writes++ === 0) {
          expect(f.events).toEqual(['first:capture', 'second:capture'])
          expect(first.registration.record('a', 'wide')?.live?.total).toBe(
            107.25,
          )
          expect(second.registration.record('a', 'wide')?.live?.total).toBe(
            107.25,
          )
        }
        f.events.push('store')
        f.destroy()
      },
    )
    f.connection.on('projectSignal', () => f.events.push('last-listener'))
    f.send('main', {
      entities: { user: { id: 7, name: 'page slot' } },
      windows: { first: f.window, second: f.window },
    })
    expect(writes).toBe(3)
    expect(first.capture).toHaveBeenCalledTimes(1)
    expect(second.capture).toHaveBeenCalledTimes(1)
    expect(f.events).toEqual([
      'first:capture',
      'second:capture',
      'store',
      'store',
      'store',
      'last-listener',
    ])
    expect(f.pages.pageLoading.get()).toBe(false)
    expect(
      f.scope.entities.signal({ type: 'user', id: 7 }).get()?.fields['name'],
    ).toBe('new')
    stop()
    unbindFirst()
    unbindSecond()
    f.pages.unsubscribe()
    first.controller.dispose()
    second.controller.dispose()
    f.connection.close()
  })

  it('prepares a late table binder replay before its first normalization', () => {
    const f = fixture()
    f.send('main', { windows: { first: f.window } })
    const first = f.table('first')
    const stop = subscribeSignal(
      f.scope.entities.signal({ type: 'user', id: 7 }),
      () => {
        expect(first.registration.record('a', 'wide')?.live?.total).toBe(107.25)
        f.destroy()
      },
    )
    const unbind = first.bind()
    expect(first.capture).toHaveBeenCalledTimes(1)
    expect(
      f.scope.entities.signal({ type: 'user', id: 7 }).get()?.fields['name'],
    ).toBe('new')
    expect(first.controller.rows.get()[0]?.rowKey).toBe('a')
    stop()
    unbind()
    f.pages.unsubscribe()
    first.controller.dispose()
    f.connection.close()
  })

  it('only prepares on foreign page frames and does not change row or window identities', () => {
    const f = fixture()
    const first = f.table('first')
    const unbind = first.bind()
    const ticket = first.registration.measurement('a', 'wide')
    f.send('other', {
      entities: { user: { id: 7, name: 'foreign' } },
      windows: { first: f.window },
    })
    expect(first.capture).toHaveBeenCalledTimes(1)
    expect(first.registration.measurement('a', 'wide')).toEqual(ticket)
    expect(first.controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { user: { type: 'user', id: 7 } },
    })
    expect(
      f.scope.entities.signal({ type: 'user', id: 7 }).get()?.fields['name'],
    ).toBe('old')
    expect(f.pages.pageLoading.get()).toBe(true)
    unbind()
    first.capture.mockClear()
    f.send('main', { data: { afterUnmount: true } })
    expect(first.capture).not.toHaveBeenCalled()
    f.pages.unsubscribe()
    first.controller.dispose()
    f.connection.close()
  })

  it('releases preparation after a throwing early listener and accepts the next frame', () => {
    const f = fixture()
    const first = f.table('first')
    const stopThrowing = f.connection.on('projectSignal', () => {
      throw new Error('listener failed')
    })
    const unbind = first.bind()
    expect(() => f.send('main', { data: { first: true } })).toThrow(
      'listener failed',
    )
    stopThrowing()
    f.send('main', { windows: { first: f.window } })
    expect(first.capture).toHaveBeenCalledTimes(2)
    expect(first.controller.rows.get()[0]?.rowKey).toBe('a')
    unbind()
    f.pages.unsubscribe()
    first.controller.dispose()
    f.connection.close()
  })

  it('retires a refused window through the controller without normalizing table rows', () => {
    const f = fixture()
    const first = f.table('first')
    const unbind = first.bind()
    f.send('main', {
      refusedWindows: { first: { errorCode: 'internal_error' } },
    })
    expect(first.capture).toHaveBeenCalledTimes(1)
    expect(first.registration.measurement('a', 'wide')).toBeNull()
    expect(first.controller.rows.get()).toEqual([])
    expect(
      f.scope.entities.signal({ type: 'user', id: 7 }).get()?.fields['name'],
    ).toBe('old')
    expect(f.pages.pageLoading.get()).toBe(false)
    unbind()
    f.pages.unsubscribe()
    first.controller.dispose()
    f.connection.close()
  })
})
