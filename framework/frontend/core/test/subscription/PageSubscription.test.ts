import { describe, expect, it } from 'vitest'
import { PageSubscription } from '../../src/subscription/PageSubscription.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { type EntityRef } from '../../src/state/EntityStore.js'
import { type ConnectionState } from '../../src/connection/HilosConnection.js'
import { type TableRow } from '../../src/state/TableRowsStore.js'
import { type TableViewportDelta } from '../../src/table/TableViewportController.js'

/** A connection double recording sent frames and replaying state changes. */
function fakeConnection(initialState: ConnectionState = 'connected') {
  const listeners: Array<(state: ConnectionState) => void> = []
  const sent: Array<Record<string, unknown>> = []

  return {
    state: initialState,
    sent,
    setState(state: ConnectionState) {
      this.state = state
      for (const listener of listeners) {
        listener(state)
      }
    },
    send(text: string): boolean {
      if (this.state !== 'connected') {
        return false
      }
      sent.push(JSON.parse(text) as Record<string, unknown>)

      return true
    },
    on(_event: 'state', listener: (state: ConnectionState) => void) {
      listeners.push(listener)

      return () => {}
    },
  }
}

/** A table controller double recording the windows and deltas routed to it. */
function fakeSink() {
  const windows: Array<{ rows: readonly TableRow[]; totalCount: number }> = []
  const deltas: TableViewportDelta[] = []

  return {
    windows,
    deltas,
    ingestWindow(rows: readonly TableRow[], totalCount: number): void {
      windows.push({ rows, totalCount })
    },
    ingestDelta(delta: TableViewportDelta): void {
      deltas.push(delta)
    },
  }
}

describe('PageSubscription', () => {
  it('subscribing while connected sends one page_subscribe frame', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = new PageSubscription(connection, scopes)

    pages.subscribe('main')

    expect(connection.sent).toEqual([
      { type: 'page_subscribe', page: 'main', params: {} },
    ])
    expect(pages.pageKey()).toBe('main')
    expect(scopes.page()?.key).toBe('main')
  })

  it('subscribing while disconnected sends on the next connected transition', () => {
    const connection = fakeConnection('disconnected')
    const pages = new PageSubscription(connection, new ScopeManager())

    pages.subscribe('main', { tab: 'open' })
    expect(connection.sent).toEqual([])

    connection.setState('connecting')
    connection.setState('connected')
    expect(connection.sent).toEqual([
      { type: 'page_subscribe', page: 'main', params: { tab: 'open' } },
    ])
  })

  it('re-sends the current subscription after a reconnect', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.subscribe('main')

    connection.setState('reconnecting')
    connection.setState('connected')

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
    pages.subscribe('main')
    pages.subscribe('profile')

    const applied = pages.ingestPageResponse('main', {
      data: { title: 'Lobby' },
    })

    expect(applied).toBe(false)
    expect(scopes.page()?.data.signal('title').get()).toBeUndefined()
  })

  it('routes a table window to a registered controller, normalizing rows', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const pages = new PageSubscription(connection, scopes)
    pages.subscribe('main')
    const sink = fakeSink()
    pages.registerTable('settings', sink)

    const routed = pages.ingestTableWindow(
      'main',
      'settings',
      [{ rowKey: 'a', slots: { user: { id: 7, name: 'Ada' } } }],
      12,
    )

    expect(routed).toBe(true)
    expect(sink.windows).toHaveLength(1)
    expect(sink.windows[0]?.totalCount).toBe(12)
    expect(sink.windows[0]?.rows[0]).toEqual({
      rowKey: 'a',
      slots: { user: { type: 'user', id: 7 } },
    })
    const ref = sink.windows[0]?.rows[0]?.slots['user'] as EntityRef
    expect(scopes.entitySignal(ref).get()?.fields['name']).toBe('Ada')
  })

  it('routes a row_updated delta to the registered controller', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.subscribe('main')
    const sink = fakeSink()
    pages.registerTable('settings', sink)

    const routed = pages.ingestTableDelta({
      page: 'main',
      tableKey: 'settings',
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { value: 'x' } },
    })

    expect(routed).toBe(true)
    expect(sink.deltas[0]).toEqual({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { value: 'x' } },
    })
  })

  it('drops a table window for a page that is not current', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.subscribe('main')
    pages.registerTable('settings', fakeSink())

    expect(pages.ingestTableWindow('other', 'settings', [], 0)).toBe(false)
  })

  it('drops a table window when no controller is registered', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.subscribe('main')

    expect(pages.ingestTableWindow('main', 'settings', [], 0)).toBe(false)
  })

  it('clears registered tables when the page changes', () => {
    const connection = fakeConnection()
    const pages = new PageSubscription(connection, new ScopeManager())
    pages.subscribe('main')
    pages.registerTable('settings', fakeSink())
    pages.subscribe('profile')

    expect(pages.ingestTableWindow('profile', 'settings', [], 0)).toBe(false)
  })
})
