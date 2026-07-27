// The per-table viewport binder: wires ONE server-windowed table to the
// connection by its (page, tableKey) address. A table's controller only ever
// sees the windows, deltas, counts, and appends addressed to it — there is no
// central switchboard holding every table and handing each its data
// (table-subscription.md). The binder subscribes the connection's table_window /
// table_viewport_delta / table_viewport_count / table_viewport_append signals,
// drops everything not addressed to this table or whose page is no longer current,
// normalizes the rows into the page scope, and feeds the sink. The returned unbind
// drops every subscription on the view's unmount.

import { type HilosConnection } from '../connection/HilosConnection.js'
import { type TableViewportDeltaSignalData } from '../protocol/envelope.js'
import {
  normalizeTableRow,
  type NormalizerOptions,
} from '../state/normalizer.js'
import { type Scope, type ScopeManager } from '../state/ScopeManager.js'
import {
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
    )
  })

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
    sink.ingestCount(data.totalCount)
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
    )
  })

  return () => {
    unsubscribeWindow()
    unsubscribeDelta()
    unsubscribeCount()
    unsubscribeAppend()
  }
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
    default:
      return null
  }
}
