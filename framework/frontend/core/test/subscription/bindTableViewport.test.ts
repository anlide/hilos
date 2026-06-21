import { describe, expect, it } from 'vitest'
import { bindTableViewport } from '../../src/subscription/bindTableViewport.js'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { type EntityRef } from '../../src/state/EntityStore.js'
import { type TableRow } from '../../src/state/TableRowsStore.js'
import {
  type TableViewportDelta,
  type TableWindowSink,
} from '../../src/table/TableViewportController.js'
import {
  type TableViewportDeltaSignal,
  type TableWindowSignal,
} from '../../src/protocol/parseSignal.js'

/** A connection double emitting table_window / table_viewport_delta, with real unsubscribe. */
function fakeConnection() {
  const windowListeners = new Set<(signal: TableWindowSignal) => void>()
  const deltaListeners = new Set<(signal: TableViewportDeltaSignal) => void>()

  return {
    on(event: string, listener: (signal: never) => void): () => void {
      if (event === 'tableWindow') {
        const typed = listener as unknown as (signal: TableWindowSignal) => void
        windowListeners.add(typed)

        return () => windowListeners.delete(typed)
      }
      const typed = listener as unknown as (
        signal: TableViewportDeltaSignal,
      ) => void
      deltaListeners.add(typed)

      return () => deltaListeners.delete(typed)
    },
    emitWindow(data: TableWindowSignal['data']): void {
      for (const listener of windowListeners) {
        listener({ data } as unknown as TableWindowSignal)
      }
    },
    emitDelta(data: TableViewportDeltaSignal['data']): void {
      for (const listener of deltaListeners) {
        listener({ data } as unknown as TableViewportDeltaSignal)
      }
    },
  }
}

/** A controller double recording the windows and deltas fed to it. */
function fakeSink(): TableWindowSink & {
  windows: Array<{ rows: readonly TableRow[]; totalCount: number }>
  deltas: TableViewportDelta[]
} {
  const windows: Array<{ rows: readonly TableRow[]; totalCount: number }> = []
  const deltas: TableViewportDelta[] = []

  return {
    windows,
    deltas,
    ingestWindow(rows, totalCount): void {
      windows.push({ rows, totalCount })
    },
    ingestDelta(delta): void {
      deltas.push(delta)
    },
  }
}

const ADDRESS = { page: 'main', tableKey: 'settings' }

function bind(
  connection: ReturnType<typeof fakeConnection>,
  scopes: ScopeManager,
  sink: TableWindowSink,
): () => void {
  return bindTableViewport(
    connection as unknown as HilosConnection,
    scopes,
    ADDRESS,
    sink,
  )
}

describe('bindTableViewport', () => {
  it('routes a window addressed to the table, normalizing rows into the page scope', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitWindow({
      page: 'main',
      tableKey: 'settings',
      rows: [{ rowKey: 'a', slots: { user: { id: 7, name: 'Ada' } } }],
      totalCount: 12,
      offset: 0,
      limit: 10,
    })

    expect(sink.windows).toHaveLength(1)
    expect(sink.windows[0]?.totalCount).toBe(12)
    expect(sink.windows[0]?.rows[0]).toEqual({
      rowKey: 'a',
      slots: { user: { type: 'user', id: 7 } },
    })
    const ref = sink.windows[0]?.rows[0]?.slots['user'] as EntityRef
    expect(scopes.entitySignal(ref).get()?.fields['name']).toBe('Ada')
  })

  it('routes a row_updated delta to the sink', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitDelta({
      page: 'main',
      tableKey: 'settings',
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { value: 'x' } },
    })

    expect(sink.deltas[0]).toEqual({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { value: 'x' } },
    })
  })

  it('drops a window addressed to another table', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitWindow({
      page: 'main',
      tableKey: 'other',
      rows: [],
      totalCount: 0,
      offset: 0,
      limit: 10,
    })

    expect(sink.windows).toHaveLength(0)
  })

  it('drops a window addressed to another page', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitWindow({
      page: 'other',
      tableKey: 'settings',
      rows: [],
      totalCount: 0,
      offset: 0,
      limit: 10,
    })

    expect(sink.windows).toHaveLength(0)
  })

  it('drops a window once the page scope is no longer the table page', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)
    // Navigate away: the current page scope is now 'profile'.
    scopes.openPage('profile')

    connection.emitWindow({
      page: 'main',
      tableKey: 'settings',
      rows: [],
      totalCount: 0,
      offset: 0,
      limit: 10,
    })

    expect(sink.windows).toHaveLength(0)
  })

  it('stops routing after unbind', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    const unbind = bind(connection, scopes, sink)

    unbind()
    connection.emitWindow({
      page: 'main',
      tableKey: 'settings',
      rows: [],
      totalCount: 0,
      offset: 0,
      limit: 10,
    })
    connection.emitDelta({
      page: 'main',
      tableKey: 'settings',
      kind: 'set_changed',
      totalCount: 5,
    })

    expect(sink.windows).toHaveLength(0)
    expect(sink.deltas).toHaveLength(0)
  })
})
