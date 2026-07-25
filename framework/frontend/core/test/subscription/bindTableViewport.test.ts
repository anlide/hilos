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
  type TableViewportAppendSignal,
  type TableViewportCountSignal,
  type TableViewportDeltaSignal,
  type TableWindowSignal,
} from '../../src/protocol/parseSignal.js'

/** A connection double emitting the four table signals, with real unsubscribe. */
function fakeConnection() {
  const windowListeners = new Set<(signal: TableWindowSignal) => void>()
  const deltaListeners = new Set<(signal: TableViewportDeltaSignal) => void>()
  const countListeners = new Set<(signal: TableViewportCountSignal) => void>()
  const appendListeners = new Set<(signal: TableViewportAppendSignal) => void>()

  return {
    on(event: string, listener: (signal: never) => void): () => void {
      switch (event) {
        case 'tableWindow': {
          const typed = listener as unknown as (
            signal: TableWindowSignal,
          ) => void
          windowListeners.add(typed)

          return () => windowListeners.delete(typed)
        }
        case 'tableViewportCount': {
          const typed = listener as unknown as (
            signal: TableViewportCountSignal,
          ) => void
          countListeners.add(typed)

          return () => countListeners.delete(typed)
        }
        case 'tableViewportAppend': {
          const typed = listener as unknown as (
            signal: TableViewportAppendSignal,
          ) => void
          appendListeners.add(typed)

          return () => appendListeners.delete(typed)
        }
        default: {
          const typed = listener as unknown as (
            signal: TableViewportDeltaSignal,
          ) => void
          deltaListeners.add(typed)

          return () => deltaListeners.delete(typed)
        }
      }
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
    emitCount(data: TableViewportCountSignal['data']): void {
      for (const listener of countListeners) {
        listener({ data } as unknown as TableViewportCountSignal)
      }
    },
    emitAppend(data: TableViewportAppendSignal['data']): void {
      for (const listener of appendListeners) {
        listener({ data } as unknown as TableViewportAppendSignal)
      }
    },
  }
}

/** A controller double recording the windows, deltas, counts and appends fed to it. */
function fakeSink(): TableWindowSink & {
  windows: Array<{ rows: readonly TableRow[]; totalCount: number }>
  deltas: TableViewportDelta[]
  counts: number[]
  appends: Array<{ row: TableRow; totalCount: number }>
} {
  const windows: Array<{ rows: readonly TableRow[]; totalCount: number }> = []
  const deltas: TableViewportDelta[] = []
  const counts: number[] = []
  const appends: Array<{ row: TableRow; totalCount: number }> = []

  return {
    windows,
    deltas,
    counts,
    appends,
    ingestWindow(rows, totalCount): void {
      windows.push({ rows, totalCount })
    },
    ingestDelta(delta): void {
      deltas.push(delta)
    },
    ingestCount(totalCount): void {
      counts.push(totalCount)
    },
    ingestAppend(row, totalCount): void {
      appends.push({ row, totalCount })
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

    // A delta without the backend's `live` marker keeps the pending gate.
    expect(sink.deltas[0]).toEqual({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { value: 'x' } },
      live: false,
    })
  })

  it('carries the backend live marker through to the sink', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitDelta({
      page: 'main',
      tableKey: 'settings',
      kind: 'row_removed',
      rowKey: 'progress',
      reason: 'deleted',
      live: true,
    })

    expect(sink.deltas[0]).toEqual({
      kind: 'row_removed',
      rowKey: 'progress',
      reason: 'deleted',
      live: true,
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
    connection.emitCount({
      page: 'main',
      tableKey: 'settings',
      totalCount: 5,
      pageCount: 1,
    })
    connection.emitAppend({
      page: 'main',
      tableKey: 'settings',
      row: { rowKey: 'a', slots: {} },
      totalCount: 1,
      pageCount: 1,
    })

    expect(sink.windows).toHaveLength(0)
    expect(sink.deltas).toHaveLength(0)
    expect(sink.counts).toHaveLength(0)
    expect(sink.appends).toHaveLength(0)
  })

  it('routes a count addressed to the table', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitCount({
      page: 'main',
      tableKey: 'settings',
      totalCount: 9,
      pageCount: 1,
    })

    expect(sink.counts).toEqual([9])
  })

  it('routes an append addressed to the table, normalizing the row', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitAppend({
      page: 'main',
      tableKey: 'settings',
      row: { rowKey: 'a', slots: { user: { id: 7, name: 'Ada' } } },
      totalCount: 13,
      pageCount: 2,
    })

    expect(sink.appends).toHaveLength(1)
    expect(sink.appends[0]?.totalCount).toBe(13)
    expect(sink.appends[0]?.row).toEqual({
      rowKey: 'a',
      slots: { user: { type: 'user', id: 7 } },
    })
  })
})
