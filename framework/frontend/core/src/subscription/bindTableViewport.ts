// The per-table viewport binder: wires ONE server-windowed table to the
// connection by its (page, tableKey) address. A table's controller only ever sees
// the windows, deltas, counts, appends, and own-creates addressed to it — there is
// no central switchboard holding every table and handing each its data
// (table-subscription.md). The binder subscribes the connection's table_window /
// table_viewport_delta / table_viewport_count / table_viewport_append /
// table_viewport_own_create signals, drops everything not addressed to this table
// or whose page is no longer current, normalizes the rows into the page scope, and
// feeds the sink. The returned unbind drops every subscription on the view's
// unmount.

import { type HilosConnection } from '../connection/HilosConnection.js'
import { SIGNAL_TYPE_PAGE_RESPONSE } from '../protocol/constants.js'
import { type TableViewportDeltaSignalData } from '../protocol/envelope.js'
import { type PageResponseWire } from '../protocol/scopePayload.js'
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
    )
  })

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
    if (window === undefined) {
      return
    }
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
    )
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

  return () => {
    unsubscribeWindow()
    unsubscribePageWindow()
    unsubscribeDelta()
    unsubscribeCount()
    unsubscribeAppend()
    unsubscribeOwnCreate()
    connection.unregisterTableWindow(address.tableKey)
  }
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
        live: data.live === true,
        own: data.own === true,
      }
    case 'row_removed':
      if (data.rowKey === undefined) {
        return null
      }

      return {
        kind: 'row_removed',
        rowKey: String(data.rowKey),
        reason: data.reason ?? '',
        live: data.live === true,
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
