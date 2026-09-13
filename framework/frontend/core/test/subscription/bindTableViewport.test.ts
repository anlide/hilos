import { describe, expect, it } from 'vitest'
import { bindTableViewport } from '../../src/subscription/bindTableViewport.js'
import {
  type HilosConnection,
  type TableAnchor,
  type TableWindowDescriptorSource,
} from '../../src/connection/HilosConnection.js'
import { SIGNAL_TYPE_PAGE_RESPONSE } from '../../src/protocol/constants.js'
import { type PageResponseWire } from '../../src/protocol/scopePayload.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { type EntityRef } from '../../src/state/EntityStore.js'
import { type TableRow } from '../../src/state/TableRowsStore.js'
import {
  type TableAnnouncePlacement,
  type TableFacetCountsByFilter,
  type TableSortOrder,
  type TableViewportDelta,
  type TableWindowSink,
} from '../../src/table/TableViewportController.js'
import { type HilosTableBulkReport } from '../../src/table/tableBulk.js'
import { type HilosTableProgressFrame } from '../../src/table/tableProgress.js'
import {
  type TableBulkReportSignal,
  type TableFacetCountsSignal,
  type TableProgressSignal,
  type TableViewportAnnounceSignal,
  type TableViewportAppendSignal,
  type TableViewportCountSignal,
  type TableViewportOwnCreateSignal,
  type TableViewportDeltaSignal,
  type TableWindowSignal,
  type ProjectSignal,
} from '../../src/protocol/parseSignal.js'

/** A connection double emitting the eight table signals, with real unsubscribe. */
function fakeConnection() {
  const windowListeners = new Set<(signal: TableWindowSignal) => void>()
  const deltaListeners = new Set<(signal: TableViewportDeltaSignal) => void>()
  const countListeners = new Set<(signal: TableViewportCountSignal) => void>()
  const appendListeners = new Set<(signal: TableViewportAppendSignal) => void>()
  const ownCreateListeners = new Set<
    (signal: TableViewportOwnCreateSignal) => void
  >()
  const announceListeners = new Set<
    (signal: TableViewportAnnounceSignal) => void
  >()
  const progressListeners = new Set<(signal: TableProgressSignal) => void>()
  const bulkReportListeners = new Set<(signal: TableBulkReportSignal) => void>()
  const facetCountListeners = new Set<
    (signal: TableFacetCountsSignal) => void
  >()
  const projectListeners = new Set<(signal: ProjectSignal) => void>()
  const registered = new Map<string, TableWindowDescriptorSource>()

  return {
    registered,
    on(event: string, listener: (signal: never) => void): () => void {
      switch (event) {
        case 'projectSignal': {
          const typed = listener as unknown as (signal: ProjectSignal) => void
          projectListeners.add(typed)

          return () => projectListeners.delete(typed)
        }
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
        case 'tableViewportOwnCreate': {
          const typed = listener as unknown as (
            signal: TableViewportOwnCreateSignal,
          ) => void
          ownCreateListeners.add(typed)

          return () => ownCreateListeners.delete(typed)
        }
        case 'tableViewportAnnounce': {
          const typed = listener as unknown as (
            signal: TableViewportAnnounceSignal,
          ) => void
          announceListeners.add(typed)

          return () => announceListeners.delete(typed)
        }
        case 'tableProgress': {
          const typed = listener as unknown as (
            signal: TableProgressSignal,
          ) => void
          progressListeners.add(typed)

          return () => progressListeners.delete(typed)
        }
        case 'tableBulkReport': {
          const typed = listener as unknown as (
            signal: TableBulkReportSignal,
          ) => void
          bulkReportListeners.add(typed)

          return () => bulkReportListeners.delete(typed)
        }
        case 'tableFacetCounts': {
          const typed = listener as unknown as (
            signal: TableFacetCountsSignal,
          ) => void
          facetCountListeners.add(typed)

          return () => facetCountListeners.delete(typed)
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
    registerTableWindow(
      tableKey: string,
      source: TableWindowDescriptorSource,
    ): void {
      registered.set(tableKey, source)
    },
    unregisterTableWindow(tableKey: string): void {
      registered.delete(tableKey)
    },
    emitPageResponse(data: PageResponseWire): void {
      for (const listener of projectListeners) {
        listener({
          type: SIGNAL_TYPE_PAGE_RESPONSE,
          data,
        } as unknown as ProjectSignal)
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
    emitOwnCreate(data: TableViewportOwnCreateSignal['data']): void {
      for (const listener of ownCreateListeners) {
        listener({ data } as unknown as TableViewportOwnCreateSignal)
      }
    },
    emitAnnounce(data: TableViewportAnnounceSignal['data']): void {
      for (const listener of announceListeners) {
        listener({ data } as unknown as TableViewportAnnounceSignal)
      }
    },
    emitProgress(data: TableProgressSignal['data']): void {
      for (const listener of progressListeners) {
        listener({ data } as unknown as TableProgressSignal)
      }
    },
    emitBulkReport(data: TableBulkReportSignal['data']): void {
      for (const listener of bulkReportListeners) {
        listener({ data } as unknown as TableBulkReportSignal)
      }
    },
    emitFacetCounts(data: TableFacetCountsSignal['data']): void {
      for (const listener of facetCountListeners) {
        listener({ data } as unknown as TableFacetCountsSignal)
      }
    },
  }
}

/** A controller double recording the windows, deltas, counts, appends, own creates, announcements, bars and bulk reports fed to it. */
function fakeSink(): TableWindowSink & {
  windows: Array<{
    rows: readonly TableRow[]
    totalCount: number
    totalExact: boolean
    firstAnchor: TableAnchor | null
    lastAnchor: TableAnchor | null
    limit: number
    sort: TableSortOrder | undefined
  }>
  deltas: TableViewportDelta[]
  counts: Array<{ totalCount: number; totalExact: boolean }>
  appends: Array<{ row: TableRow; totalCount: number; totalExact: boolean }>
  ownCreates: Array<{
    row: TableRow
    position: number
    totalCount: number
    totalExact: boolean
    requestId?: string | null
  }>
  announcements: Array<{
    rowKey: string
    placement: TableAnnouncePlacement
    totalCount: number
    totalExact: boolean
  }>
  progress: HilosTableProgressFrame[]
  snapshots: Array<readonly HilosTableProgressFrame[]>
  bulkReports: HilosTableBulkReport[]
  facetCounts: TableFacetCountsByFilter[]
} {
  const windows: Array<{
    rows: readonly TableRow[]
    totalCount: number
    totalExact: boolean
    firstAnchor: TableAnchor | null
    lastAnchor: TableAnchor | null
    limit: number
    sort: TableSortOrder | undefined
  }> = []
  const deltas: TableViewportDelta[] = []
  const counts: Array<{ totalCount: number; totalExact: boolean }> = []
  const appends: Array<{
    row: TableRow
    totalCount: number
    totalExact: boolean
  }> = []
  const ownCreates: Array<{
    row: TableRow
    position: number
    totalCount: number
    totalExact: boolean
    requestId?: string | null
  }> = []
  const announcements: Array<{
    rowKey: string
    placement: TableAnnouncePlacement
    totalCount: number
    totalExact: boolean
  }> = []
  const progress: HilosTableProgressFrame[] = []
  const snapshots: Array<readonly HilosTableProgressFrame[]> = []
  const bulkReports: HilosTableBulkReport[] = []
  const facetCounts: TableFacetCountsByFilter[] = []

  return {
    windows,
    deltas,
    counts,
    appends,
    ownCreates,
    announcements,
    progress,
    snapshots,
    bulkReports,
    facetCounts,
    ingestFacetCounts(facets) {
      facetCounts.push(facets)
    },
    ingestWindow(
      rows,
      totalCount,
      totalExact,
      firstAnchor,
      lastAnchor,
      limit,
    ): void {
      windows.push({
        rows,
        totalCount,
        totalExact,
        firstAnchor,
        lastAnchor,
        limit,
        sort: undefined,
      })
    },
    ingestSubscriptionWindow(
      rows,
      totalCount,
      totalExact,
      firstAnchor,
      lastAnchor,
      limit,
      sort,
      bars,
    ): void {
      snapshots.push(bars)
      windows.push({
        rows,
        totalCount,
        totalExact,
        firstAnchor,
        lastAnchor,
        limit,
        sort,
      })
    },
    descriptor: () => null,
    ingestDelta(delta): void {
      deltas.push(delta)
    },
    ingestCount(totalCount, totalExact): void {
      counts.push({ totalCount, totalExact })
    },
    ingestAppend(row, totalCount, totalExact): void {
      appends.push({ row, totalCount, totalExact })
    },
    ingestOwnCreate(row, position, totalCount, totalExact, requestId): void {
      ownCreates.push({ row, position, totalCount, totalExact, requestId })
    },
    ingestAnnounce(rowKey, placement, totalCount, totalExact): void {
      announcements.push({ rowKey, placement, totalCount, totalExact })
    },
    ingestProgress(frame): void {
      progress.push(frame)
    },
    ingestBulkReport(report): void {
      bulkReports.push(report)
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
      totalExact: true,
      limit: 10,
      firstAnchor: { id: 1 },
      lastAnchor: { id: 10 },
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

  it('routes the window that arrives inside the page answer, order and all', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitPageResponse({
      page: 'main',
      payload: {
        windows: {
          settings: {
            rows: [{ rowKey: 'a', slots: { user: { id: 7, name: 'Ada' } } }],
            sort: [{ field: 'key', direction: 'asc' }],
            limit: 25,
            totalCount: 12,
            totalExact: true,
            firstAnchor: { key: 'a' },
            lastAnchor: { key: 'a' },
          },
        },
      },
    })

    // The cold entry: no request was made and the window is here anyway, carrying the size
    // and the order the table declares on the backend (HIL-642).
    expect(sink.windows).toHaveLength(1)
    expect(sink.windows[0]?.limit).toBe(25)
    expect(sink.windows[0]?.sort).toEqual([{ field: 'key', direction: 'asc' }])
    const ref = sink.windows[0]?.rows[0]?.slots['user'] as EntityRef
    expect(scopes.entitySignal(ref).get()?.fields['name']).toBe('Ada')
  })

  it('drops a page answer that carries no window for this table', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitPageResponse({ page: 'main', payload: { data: {} } })
    connection.emitPageResponse({
      page: 'other',
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
    })

    expect(sink.windows).toEqual([])
  })

  it('registers this table with the connection and drops it on unbind', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()

    const unbind = bind(connection, scopes, sink)

    // What the next page subscribe reports: the tab is the only side that still remembers
    // its windows once the socket has died under it.
    expect(connection.registered.get('settings')).toBe(sink)

    unbind()
    expect(connection.registered.has('settings')).toBe(false)
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

    // A delta without the backend's `own` marker keeps the pending gate.
    expect(sink.deltas[0]).toEqual({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { value: 'x' } },
      own: false,
    })
  })

  it('routes a row_moved delta with the slot the server named', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitDelta({
      page: 'main',
      tableKey: 'settings',
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { value: 'x' } },
      position: 3,
    })

    expect(sink.deltas[0]).toEqual({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { value: 'x' } },
      position: 3,
      own: false,
    })
  })

  it('reads a row_moved delta with no slot as a move with none', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitDelta({
      page: 'main',
      tableKey: 'settings',
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { value: 'x' } },
    })

    // The table could not name a place, and the absence travels as absence: a zero
    // here would move the row to the top of the window on Apply.
    expect(sink.deltas[0]).toEqual({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { value: 'x' } },
      position: undefined,
      own: false,
    })
  })

  it('carries the backend own marker through to the sink', () => {
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
      own: true,
    })

    expect(sink.deltas[0]).toEqual({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { value: 'x' } },
      own: true,
    })
  })

  it('drops a live marker a stale backend still sends', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitDelta({
      page: 'main',
      tableKey: 'settings',
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
      live: true,
    })

    // The door a `live` delta used to open is gone with the row it was cut for (HIL-820),
    // and the key travels no further than the parse: a removal is gated like any other.
    expect(sink.deltas[0]).toEqual({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
      own: false,
    })
  })

  it('reduces a freshness delta to its row key and list', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitDelta({
      page: 'main',
      tableKey: 'settings',
      kind: 'row_stale',
      rowKey: 'a',
      staleSources: ['connections'],
    })

    expect(sink.deltas[0]).toEqual({
      kind: 'row_stale',
      rowKey: 'a',
      staleSources: ['connections'],
    })
  })

  it('reads a freshness delta with no list as the empty one', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitDelta({
      page: 'main',
      tableKey: 'settings',
      kind: 'row_stale',
      rowKey: 'a',
    })

    expect(sink.deltas[0]).toEqual({
      kind: 'row_stale',
      rowKey: 'a',
      staleSources: [],
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
      totalExact: true,
      limit: 10,
      firstAnchor: { id: 1 },
      lastAnchor: { id: 10 },
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
      totalExact: true,
      limit: 10,
      firstAnchor: { id: 1 },
      lastAnchor: { id: 10 },
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
      totalExact: true,
      limit: 10,
      firstAnchor: { id: 1 },
      lastAnchor: { id: 10 },
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
      totalExact: true,
      limit: 10,
      firstAnchor: { id: 1 },
      lastAnchor: { id: 10 },
    })
    connection.emitCount({
      page: 'main',
      tableKey: 'settings',
      totalCount: 5,
      totalExact: true,
      pageCount: 1,
    })
    connection.emitAppend({
      page: 'main',
      tableKey: 'settings',
      row: { rowKey: 'a', slots: {} },
      totalCount: 1,
      totalExact: true,
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
      totalExact: true,
      pageCount: 1,
    })

    expect(sink.counts).toEqual([{ totalCount: 9, totalExact: true }])
  })

  it('routes the counts beside the filter options addressed to the table, dropping other tables', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)
    const facets = {
      channel: {
        any: { count: 500, exact: false },
        options: { email: { count: 412, exact: true } },
      },
    }

    connection.emitFacetCounts({ page: 'main', tableKey: 'other', facets })
    connection.emitFacetCounts({
      page: 'elsewhere',
      tableKey: 'settings',
      facets,
    })
    connection.emitFacetCounts({ page: 'main', tableKey: 'settings', facets })

    expect(sink.facetCounts).toEqual([facets])
  })

  it('routes an announcement addressed to the table, dropping other tables', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitAnnounce({
      page: 'main',
      tableKey: 'other',
      rowKey: 'x',
      placement: 'above',
      totalCount: 9,
      totalExact: true,
      pageCount: 1,
    })
    connection.emitAnnounce({
      page: 'main',
      tableKey: 'settings',
      rowKey: 'x',
      placement: 'above',
      totalCount: 9,
      totalExact: true,
      pageCount: 1,
    })

    expect(sink.announcements).toEqual([
      { rowKey: 'x', placement: 'above', totalCount: 9, totalExact: true },
    ])
  })

  it('routes a bar addressed to the table, dropping the ones that are not', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitProgress({
      page: 'main',
      tableKey: 'other',
      scope: 'table',
      progressKey: 'nightly',
      current: 34,
      total: 120,
    })
    connection.emitProgress({
      page: 'other',
      tableKey: 'settings',
      scope: 'table',
      progressKey: 'nightly',
      current: 34,
      total: 120,
    })
    connection.emitProgress({
      page: 'main',
      tableKey: 'settings',
      scope: 'table',
      progressKey: 'nightly',
      current: 34,
      total: 120,
      detail: { title: 'Nightly' },
    })

    expect(sink.progress).toEqual([
      {
        scope: 'table',
        progressKey: 'nightly',
        current: 34,
        total: 120,
        ended: undefined,
        detail: { title: 'Nightly' },
      },
    ])
  })

  it('routes a bulk report addressed to the table, dropping the ones that are not', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitBulkReport({
      page: 'main',
      tableKey: 'other',
      progressKey: 'bulk-1',
      touched: 3,
      untouched: [],
    })
    connection.emitBulkReport({
      page: 'other',
      tableKey: 'settings',
      progressKey: 'bulk-1',
      touched: 3,
      untouched: [],
    })
    connection.emitBulkReport({
      page: 'main',
      tableKey: 'settings',
      progressKey: 'bulk-1',
      touched: 39,
      untouched: [{ rowKey: 'r7', reason: 'It was already gone' }],
      untouchedOmitted: 4,
    })

    expect(sink.bulkReports).toEqual([
      {
        progressKey: 'bulk-1',
        touched: 39,
        untouched: [{ rowKey: 'r7', reason: 'It was already gone' }],
        untouchedOmitted: 4,
      },
    ])
  })

  it('reads a bulk report with no count of omitted names as none omitted', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitBulkReport({
      page: 'main',
      tableKey: 'settings',
      progressKey: 'bulk-2',
      touched: 40,
      untouched: [],
    })

    expect(sink.bulkReports[0]?.untouchedOmitted).toBe(0)
  })

  it('drops a row bar that names no row, and ignores a row key on the other two', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    // A row bar with no row lies about what it is tied to and has nowhere to be drawn; a
    // row key beside a table bar is simply not read. The wire schema catches neither: every
    // schema of the protocol is loose, so a key it does not name is legal there.
    connection.emitProgress({
      page: 'main',
      tableKey: 'settings',
      scope: 'row',
      progressKey: 'backup-17',
      current: 3,
      total: 11,
    })
    connection.emitProgress({
      page: 'main',
      tableKey: 'settings',
      scope: 'bulk',
      progressKey: 'delete-40',
      rowKey: 'a',
      current: 12,
      total: 40,
    })

    expect(sink.progress).toEqual([
      {
        scope: 'bulk',
        progressKey: 'delete-40',
        current: 12,
        total: 40,
        ended: undefined,
        detail: undefined,
      },
    ])
  })

  it('carries the work running on the table in with the page answer', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitPageResponse({
      page: 'main',
      payload: {
        windows: {
          settings: {
            rows: [],
            sort: [],
            limit: 25,
            totalCount: 0,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            progress: [
              {
                scope: 'row',
                progressKey: 'backup-17',
                rowKey: 'a',
                current: 3,
                total: 11,
              },
              { scope: 'row', progressKey: 'orphan', current: 1 },
            ],
          },
        },
      },
    })

    // The snapshot road and the live road are read by one rule: the row bar with no row is
    // dropped here too, rather than reaching the controller in a shape it cannot place.
    expect(sink.snapshots).toEqual([
      [
        {
          scope: 'row',
          progressKey: 'backup-17',
          rowKey: 'a',
          current: 3,
          total: 11,
          ended: undefined,
          detail: undefined,
        },
      ],
    ])
  })

  it('reads a page answer with no work on the table as an empty snapshot', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitPageResponse({
      page: 'main',
      payload: {
        windows: {
          settings: {
            rows: [],
            sort: [],
            limit: 25,
            totalCount: 0,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
          },
        },
      },
    })

    // An absent key is the server saying nothing is running, which is what takes down a bar
    // left standing by a socket that broke mid-run.
    expect(sink.snapshots).toEqual([[]])
  })

  it('drops an announcement addressed to another page', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitAnnounce({
      page: 'other',
      tableKey: 'settings',
      rowKey: 'x',
      placement: 'inside',
      totalCount: 9,
      totalExact: true,
      pageCount: 1,
    })

    expect(sink.announcements).toEqual([])
  })

  it('stops routing announcements after unbind', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    const unbind = bind(connection, scopes, sink)

    unbind()
    connection.emitAnnounce({
      page: 'main',
      tableKey: 'settings',
      rowKey: 'x',
      placement: 'above',
      totalCount: 9,
      totalExact: true,
      pageCount: 1,
    })

    expect(sink.announcements).toEqual([])
  })

  it('routes an own create addressed to the table, normalizing the row', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitOwnCreate({
      page: 'main',
      tableKey: 'settings',
      row: { rowKey: 'a', slots: { user: { id: 7, name: 'Ada' } } },
      position: 2,
      totalCount: 13,
      totalExact: true,
      pageCount: 2,
      requestId: 'req-1',
    })

    expect(sink.ownCreates).toHaveLength(1)
    expect(sink.ownCreates[0]?.position).toBe(2)
    expect(sink.ownCreates[0]?.totalCount).toBe(13)
    expect(sink.ownCreates[0]?.requestId).toBe('req-1')
    expect(sink.ownCreates[0]?.row).toEqual({
      rowKey: 'a',
      slots: { user: { type: 'user', id: 7 } },
    })
  })

  it('drops an own create addressed to another table', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    scopes.openPage('main')
    const sink = fakeSink()
    bind(connection, scopes, sink)

    connection.emitOwnCreate({
      page: 'main',
      tableKey: 'other',
      row: { rowKey: 'a', slots: {} },
      position: 0,
      totalCount: 1,
      totalExact: true,
      pageCount: 1,
      requestId: null,
    })

    expect(sink.ownCreates).toHaveLength(0)
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
      totalExact: true,
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
