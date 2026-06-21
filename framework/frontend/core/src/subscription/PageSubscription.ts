// The page-subscription manager: a connection shows 0..1 pages
// (wire-protocol.md). Subscribing a page atomically replaces the previous
// subscription — navigation is one page_subscribe, never an unsubscribe+
// subscribe pair — and every `connected` transition re-sends the current
// subscription, because a new socket is a fresh protocol exchange. The
// manager owns the page scope lifecycle and the stale-signal guard: a page
// payload is ingested only while its page is still the current subscription.

import {
  FIELD_PAGE,
  FIELD_PARAMS,
  FIELD_TYPE,
  SIGNAL_TYPE_PAGE_SUBSCRIBE,
  SIGNAL_TYPE_PAGE_UNSUBSCRIBE,
} from '../protocol/constants.js'
import { type ConnectionState } from '../connection/HilosConnection.js'
import { type TableViewportDeltaSignalData } from '../protocol/envelope.js'
import { type Scope, type ScopeManager } from '../state/ScopeManager.js'
import {
  ingest,
  normalizeTableRow,
  type NormalizerOptions,
  type ScopePayload,
  type TableRowFragment,
} from '../state/normalizer.js'
import {
  type TableViewportDelta,
  type TableWindowSink,
} from '../table/TableViewportController.js'

/**
 * The slice of HilosConnection the manager touches; a test double only has to
 * implement these three members.
 */
export interface PageSubscriptionConnection {
  readonly state: ConnectionState
  send(text: string): boolean
  on(event: 'state', listener: (state: ConnectionState) => void): () => void
}

export class PageSubscription {
  private current: {
    pageKey: string
    params: Record<string, unknown>
  } | null = null

  /** Server-windowed table controllers registered for the current page, by table key. */
  private readonly tables = new Map<string, TableWindowSink>()

  constructor(
    private readonly connection: PageSubscriptionConnection,
    private readonly scopes: ScopeManager,
  ) {
    connection.on('state', (state) => {
      if (state === 'connected') {
        this.sendSubscribe()
      }
    })
  }

  /** The currently subscribed page key; `undefined` between subscriptions. */
  pageKey(): string | undefined {
    return this.current?.pageKey
  }

  /**
   * Subscribe the page, atomically replacing the previous subscription: the
   * old page scope drops, a fresh one opens, and one page_subscribe frame
   * goes out (immediately when connected, on the next `connected` transition
   * otherwise).
   *
   * @param pageKey The page to subscribe.
   * @param params Route params for the subscription.
   */
  subscribe(pageKey: string, params: Record<string, unknown> = {}): Scope {
    this.current = { pageKey, params }
    this.tables.clear()
    const scope = this.scopes.openPage(pageKey)
    this.sendSubscribe()

    return scope
  }

  /** Leave to no page: drops the page scope and tells the backend. */
  unsubscribe(): void {
    if (!this.current) {
      return
    }
    const left = this.current.pageKey
    this.current = null
    this.tables.clear()
    this.scopes.dropPage()
    this.connection.send(
      JSON.stringify({
        [FIELD_TYPE]: SIGNAL_TYPE_PAGE_UNSUBSCRIBE,
        [FIELD_PAGE]: left,
      }),
    )
  }

  /**
   * Ingest a page's scope payload into the current page scope. A payload for
   * any other page is dropped — the late-signal guard of wire-protocol.md —
   * and the return value reports which happened.
   *
   * @param pageKey The page key the signal carried.
   * @param payload The page's scope-shaped payload.
   * @param options Binding-local entity-type overrides for this page's slots.
   */
  ingestPageResponse(
    pageKey: string,
    payload: ScopePayload,
    options: NormalizerOptions = {},
  ): boolean {
    const scope = this.scopes.page()
    if (!scope || pageKey !== this.current?.pageKey) {
      return false
    }
    ingest(scope, payload, options)

    return true
  }

  /**
   * Register a server-windowed table controller for the current page so the
   * connection's table_window / table_viewport_delta signals reach it. The view
   * registers on mount and unregisters on unmount.
   *
   * @param tableKey The table key the controller drives.
   * @param sink The controller's window/delta sink.
   */
  registerTable(tableKey: string, sink: TableWindowSink): void {
    this.tables.set(tableKey, sink)
  }

  /**
   * Unregister a table controller (view unmount).
   *
   * @param tableKey The table key to drop.
   */
  unregisterTable(tableKey: string): void {
    this.tables.delete(tableKey)
  }

  /**
   * Route a table window snapshot to its registered controller, normalizing the
   * rows into the page scope first. Dropped when the page is not current or no
   * controller is registered for the table.
   *
   * @param pageKey The page key the signal carried.
   * @param tableKey The table key the window is for.
   * @param rows The window's raw row fragments.
   * @param totalCount Total rows matching the filter.
   * @param options Binding-local entity-type overrides for the rows' slots.
   * @return Whether the window was routed.
   */
  ingestTableWindow(
    pageKey: string,
    tableKey: string,
    rows: readonly TableRowFragment[],
    totalCount: number,
    options: NormalizerOptions = {},
  ): boolean {
    const scope = this.scopes.page()
    const sink = this.tables.get(tableKey)
    if (!scope || pageKey !== this.current?.pageKey || !sink) {
      return false
    }
    sink.ingestWindow(
      rows.map((row) => normalizeTableRow(scope, row, options)),
      totalCount,
    )

    return true
  }

  /**
   * Route a live viewport delta to its registered controller, normalizing an
   * updated row into the page scope. Dropped when the page is not current, no
   * controller is registered, or the delta is malformed.
   *
   * @param data The raw table_viewport_delta payload.
   * @param options Binding-local entity-type overrides for an updated row's slots.
   * @return Whether the delta was routed.
   */
  ingestTableDelta(
    data: TableViewportDeltaSignalData,
    options: NormalizerOptions = {},
  ): boolean {
    const scope = this.scopes.page()
    const sink = this.tables.get(data.tableKey)
    if (!scope || data.page !== this.current?.pageKey || !sink) {
      return false
    }
    const delta = this.toViewportDelta(scope, data, options)
    if (!delta) {
      return false
    }
    sink.ingestDelta(delta)

    return true
  }

  private toViewportDelta(
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
        }
      case 'row_removed':
        if (data.rowKey === undefined) {
          return null
        }

        return {
          kind: 'row_removed',
          rowKey: String(data.rowKey),
          reason: data.reason ?? '',
        }
      case 'set_changed':
        return {
          kind: 'set_changed',
          totalCount: data.totalCount ?? 0,
        }
      default:
        return null
    }
  }

  private sendSubscribe(): void {
    if (!this.current) {
      return
    }
    this.connection.send(
      JSON.stringify({
        [FIELD_TYPE]: SIGNAL_TYPE_PAGE_SUBSCRIBE,
        [FIELD_PAGE]: this.current.pageKey,
        [FIELD_PARAMS]: this.current.params,
      }),
    )
  }
}
