// The per-table viewport binder: wires ONE server-windowed table to the
// connection by its (page, tableKey) address. A table's controller only ever
// sees the windows, refusals, deltas, counts, appends, own-creates, announcements, their
// withdrawals and progress bars addressed to it — there is no central
// switchboard holding every table and handing each its data
// (table-subscription.md). The binder subscribes the connection's table_window
// / table_window_refused / table_viewport_delta / table_viewport_count /
// table_viewport_append / table_viewport_own_create / table_viewport_announce /
// table_viewport_unannounce / table_progress / table_facet_counts signals,
// drops everything not addressed to this table or whose page is no longer
// current, normalizes the rows into the page scope, and feeds the sink. The
// returned unbind drops every subscription on the view's unmount.

import { type HilosConnection } from '../connection/HilosConnection.js'
import { SIGNAL_TYPE_PAGE_RESPONSE } from '../protocol/constants.js'
import { type TableViewportDeltaSignalData } from '../protocol/envelope.js'
import {
  type PageResponseWire,
  type TableProgressWire,
} from '../protocol/scopePayload.js'
import {
  normalizeTableRow,
  type NormalizerOptions,
} from '../state/normalizer.js'
import { type Scope, type ScopeManager } from '../state/ScopeManager.js'
import {
  type TableSortOrder,
  type TableViewportDelta,
  type TableWindowSink,
} from '../table/TableViewportController.js'
import { type HilosTableProgressFrame } from '../table/tableProgress.js'

/** A table's address on the wire: the page it belongs to and its table key. */
export interface TableViewportAddress {
  readonly page: string
  readonly tableKey: string
}

/**
 * Bind one table's window / delta stream to its controller, scoped to the
 * table's `(page, tableKey)` address. A signal for another table, or for this
 * page once it is no longer the current page scope (the stale-signal guard), is
 * dropped; a matching one has its rows normalized into the page scope and is fed
 * to the sink. Call the returned unbind on the view's unmount.
 *
 * @param connection The connection whose table signals are routed.
 * @param scopes The scope manager owning the page scope rows normalize into.
 * @param address The table's `(page, tableKey)` address.
 * @param sink The table controller receiving the window and deltas.
 * @param options Binding-local entity-type overrides for the rows' slots.
 * @return An unbind that drops both subscriptions.
 */
export function bindTableViewport(
  connection: HilosConnection,
  scopes: ScopeManager,
  address: TableViewportAddress,
  sink: TableWindowSink,
  options: NormalizerOptions = {},
): () => void {
  // The page scope only when it is still the table's page — a window racing in
  // after a navigation away must not normalize into the new page's scope.
  const currentScope = (): Scope | undefined => {
    const scope = scopes.page()

    return scope?.key === address.page ? scope : undefined
  }

  const unsubscribeWindow = connection.on('tableWindow', (signal) => {
    const data = signal.data
    if (data.tableKey !== address.tableKey || data.page !== address.page) {
      return
    }
    const scope = currentScope()
    if (!scope) {
      return
    }
    sink.ingestWindow(
      data.rows.map((row) => normalizeTableRow(scope, row, options)),
      data.totalCount,
      data.totalExact,
      data.firstAnchor,
      data.lastAnchor,
      data.limit,
      data.rowsBefore ?? null,
    )
  })

  const unsubscribeWindowRefused = connection.on(
    'tableWindowRefused',
    (signal) => {
      const data = signal.data
      if (data.tableKey !== address.tableKey || data.page !== address.page) {
        return
      }
      const scope = currentScope()
      if (!scope) {
        return
      }
      sink.ingestRefusal(data.errorCode)
    },
  )

  // The sixth road into the same sink, and the one a cold entry arrives by: the page's own
  // answer carries the first window of every table it declares, so nothing is asked for it
  // and nothing waits a round trip for it (HIL-642). A frame for another page, or one whose
  // payload has no section for this table, is dropped by the same test as the other five.
  const unsubscribePageWindow = connection.on('projectSignal', (signal) => {
    if (signal.type !== SIGNAL_TYPE_PAGE_RESPONSE) {
      return
    }
    // Validated against pageResponseSchema at the parse boundary; this cast is the
    // declared typed selector for that schema's output, as in bindPageScope.
    const data = signal.data as PageResponseWire
    if (data.page !== address.page) {
      return
    }
    const window = data.payload?.windows?.[address.tableKey]
    if (window !== undefined) {
      const scope = currentScope()
      if (!scope) {
        return
      }
      sink.ingestSubscriptionWindow(
        window.rows.map((row) => normalizeTableRow(scope, row, options)),
        window.totalCount,
        window.totalExact,
        window.firstAnchor,
        window.lastAnchor,
        window.limit,
        toSortOrder(window.sort),
        toProgressFrames(window.progress),
        window.rowsBefore ?? null,
      )

      return
    }
    const refusal = data.payload?.refusedWindows?.[address.tableKey]
    if (refusal === undefined) {
      return
    }
    const scope = currentScope()
    if (!scope) {
      return
    }
    sink.ingestRefusal(refusal.errorCode)
  })

  // The connection reports this window on its next page subscribe, so a tab coming back
  // after a broken socket comes back to the window that was on the screen. It asks the
  // controller at the moment the frame goes out rather than keeping a copy taken here.
  connection.registerTableWindow(address.tableKey, sink)

  const unsubscribeDelta = connection.on('tableViewportDelta', (signal) => {
    const data = signal.data
    if (data.tableKey !== address.tableKey || data.page !== address.page) {
      return
    }
    const scope = currentScope()
    if (!scope) {
      return
    }
    const delta = toViewportDelta(scope, data, options)
    if (delta) {
      sink.ingestDelta(delta)
    }
  })

  const unsubscribeCount = connection.on('tableViewportCount', (signal) => {
    const data = signal.data
    if (data.tableKey !== address.tableKey || data.page !== address.page) {
      return
    }
    sink.ingestCount(data.totalCount, data.totalExact)
  })

  // The counts beside the table's filter options arrive on a frame of their own, after the
  // window they describe, and name only the filters whose counts moved: the controller lays
  // them over the counts it holds.
  const unsubscribeFacetCounts = connection.on('tableFacetCounts', (signal) => {
    const data = signal.data
    if (data.tableKey !== address.tableKey || data.page !== address.page) {
      return
    }
    sink.ingestFacetCounts(data.facets)
  })

  const unsubscribeAppend = connection.on('tableViewportAppend', (signal) => {
    const data = signal.data
    if (data.tableKey !== address.tableKey || data.page !== address.page) {
      return
    }
    const scope = currentScope()
    if (!scope) {
      return
    }
    sink.ingestAppend(
      normalizeTableRow(scope, data.row, options),
      data.totalCount,
      data.totalExact,
    )
  })

  const unsubscribeOwnCreate = connection.on(
    'tableViewportOwnCreate',
    (signal) => {
      const data = signal.data
      if (data.tableKey !== address.tableKey || data.page !== address.page) {
        return
      }
      const scope = currentScope()
      if (!scope) {
        return
      }
      sink.ingestOwnCreate(
        normalizeTableRow(scope, data.row, options),
        data.position,
        data.totalCount,
        data.totalExact,
        data.requestId,
      )
    },
  )

  // No page scope is asked for here, as none is asked for by the count: the frame
  // carries no row body, so there is nothing to normalize into a store.
  const unsubscribeAnnounce = connection.on(
    'tableViewportAnnounce',
    (signal) => {
      const data = signal.data
      if (data.tableKey !== address.tableKey || data.page !== address.page) {
        return
      }
      sink.ingestAnnounce(
        data.rowKey,
        data.placement,
        data.totalCount,
        data.totalExact,
      )
    },
  )

  // Nor here: the withdrawal carries a key and nothing else.
  const unsubscribeUnannounce = connection.on(
    'tableViewportUnannounce',
    (signal) => {
      const data = signal.data
      if (data.tableKey !== address.tableKey || data.page !== address.page) {
        return
      }
      sink.ingestUnannounce(data.rowKey)
    },
  )

  // No page scope here either, and for the same reason: a bar carries no row body. What it
  // does carry is the pair (scope, rowKey), and this is where that pair is judged — a row bar
  // with no row to hang under is dropped rather than shown somewhere else, and a row key sent
  // with the other two places is simply not read. The wire schema cannot do it: every schema
  // of the protocol is loose by design and a key it does not name is legal there.
  const unsubscribeProgress = connection.on('tableProgress', (signal) => {
    const data = signal.data
    if (data.tableKey !== address.tableKey || data.page !== address.page) {
      return
    }
    if (data.scope === 'row' && data.rowKey === undefined) {
      return
    }
    sink.ingestProgress(toProgressFrame(data))
  })

  // Addressed to the connection that started the run, and only to it: the report answers a
  // press nobody else made. The address is checked here all the same, because one connection
  // can hold several tables of one page and several pages at once.
  const unsubscribeBulkReport = connection.on('tableBulkReport', (signal) => {
    const data = signal.data
    if (data.tableKey !== address.tableKey || data.page !== address.page) {
      return
    }
    sink.ingestBulkReport({
      progressKey: data.progressKey,
      touched: data.touched,
      untouched: data.untouched.map((line) => ({
        rowKey: line.rowKey,
        reason: line.reason,
      })),
      // Absent is none omitted. The server leaves the key out when every name fit, and a
      // view reading `undefined` would have to know that rule to draw the same thing.
      untouchedOmitted: data.untouchedOmitted ?? 0,
    })
  })

  return () => {
    unsubscribeWindow()
    unsubscribeWindowRefused()
    unsubscribePageWindow()
    unsubscribeDelta()
    unsubscribeCount()
    unsubscribeFacetCounts()
    unsubscribeAppend()
    unsubscribeOwnCreate()
    unsubscribeAnnounce()
    unsubscribeUnannounce()
    unsubscribeProgress()
    unsubscribeBulkReport()
    connection.unregisterTableWindow(address.tableKey)
  }
}

/**
 * Read one bar off the wire, keeping the row key only where a bar is tied to a row.
 *
 * @param data The bar as the frame or the window section carried it.
 * @return The bar the controller takes in.
 */
function toProgressFrame(data: TableProgressWire): HilosTableProgressFrame {
  return {
    scope: data.scope,
    progressKey: data.progressKey,
    ...(data.scope === 'row' ? { rowKey: data.rowKey } : {}),
    current: data.current,
    total: data.total,
    ended: data.ended,
    detail: data.detail,
  }
}

/**
 * Read the work a subscription answer named, dropping a row bar that names no row.
 *
 * An absent list is an empty one here, and the difference matters: an empty snapshot is the
 * server saying nothing is running, which takes down whatever was left standing.
 *
 * @param progress The bars the window section carried, or undefined when it carried none.
 * @return The bars the controller takes in, in the order they arrived.
 */
function toProgressFrames(
  progress: readonly TableProgressWire[] | undefined,
): readonly HilosTableProgressFrame[] {
  return (progress ?? [])
    .filter((bar) => bar.scope !== 'row' || bar.rowKey !== undefined)
    .map(toProgressFrame)
}

/**
 * Read a wire order into the controller's, or undefined when the window ran in none.
 *
 * An empty list is no ordering rather than an order over nothing — the same reading the
 * backend gives it — so the two sides agree on what an unsorted window looks like.
 *
 * @param sort The order as the frame carried it.
 * @return The order, or undefined when the window ran in none.
 */
function toSortOrder(
  sort:
    | readonly { readonly field: string; readonly direction: 'asc' | 'desc' }[]
    | null
    | undefined,
): TableSortOrder | undefined {
  return sort === null || sort === undefined || sort.length === 0
    ? undefined
    : sort.map((component) => ({
        field: component.field,
        direction: component.direction,
      }))
}

/** Reduce a raw addressed delta to the controller's normalized delta, or null when malformed. */
function toViewportDelta(
  scope: Scope,
  data: TableViewportDeltaSignalData,
  options: NormalizerOptions,
): TableViewportDelta | null {
  switch (data.kind) {
    case 'row_updated':
      if (data.rowKey === undefined || data.row === undefined) {
        return null
      }

      return {
        kind: 'row_updated',
        rowKey: String(data.rowKey),
        row: normalizeTableRow(scope, data.row, options),
        own: data.own === true,
      }
    case 'row_moved':
      if (data.rowKey === undefined || data.row === undefined) {
        return null
      }

      return {
        kind: 'row_moved',
        rowKey: String(data.rowKey),
        row: normalizeTableRow(scope, data.row, options),
        position: typeof data.position === 'number' ? data.position : undefined,
        own: data.own === true,
      }
    case 'row_removed':
      if (data.rowKey === undefined) {
        return null
      }

      // The body rides a removal only for the receiver holding the row in focus, and only
      // while the row is alive; every other receiver, and a gone row, get the key alone.
      return {
        kind: 'row_removed',
        rowKey: String(data.rowKey),
        reason: data.reason ?? '',
        row:
          data.row === undefined
            ? undefined
            : normalizeTableRow(scope, data.row, options),
        own: data.own === true,
      }
    case 'row_stale':
      if (data.rowKey === undefined) {
        return null
      }

      // An absent list is the empty one: this kind says what the row's frozen
      // sources ARE now, and having none of them is how a thaw is spelled.
      return {
        kind: 'row_stale',
        rowKey: String(data.rowKey),
        staleSources: data.staleSources ?? [],
      }
    default:
      return null
  }
}
