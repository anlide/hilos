// The headless SERVER-WINDOWED table controller (table-subscription.md). Unlike
// the client-side TableController — which filters/sorts/paginates all delivered
// rows locally — here the window comes from the backend: the view's intents
// (search / sort / paginate) change the viewport descriptor and are sent to the
// server, which replies a table_window snapshot the controller displays.
//
// Live changes to the shown rows arrive as table_viewport_delta and DO NOT
// auto-apply: they accumulate as PENDING so the table never rearranges under the
// user's hands. The user resolves them with apply() — updates land in place, a
// removed row becomes a placeholder in its slot (the layout never collapses and
// no row is pulled from the next page), and a list-change refreshes the count.
// An explicit window change discards pending instead, since the new window the
// server returns is authoritative. The controller owns no rendering and no DOM.

import { type TableViewportDescriptor } from '../connection/HilosConnection.js'
import { type TableRow } from '../state/TableRowsStore.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
  type WritableSignal,
} from '../state/signal.js'
import { type SortDirection, type TableSort } from './TableController.js'

/** Generic filter-map key the search box writes; matches the backend `FILTER_KEY_SEARCH`. */
const SEARCH_FILTER_KEY = 'search'

/**
 * One live pending change scoped to the connection's window, normalized from a
 * `table_viewport_delta` signal (the row already reduced to references):
 *
 * - `row_updated` — a shown row's content changed; carries the new row;
 * - `row_removed` — a shown row was deleted or left the set; carries the reason;
 * - `set_changed` — membership/total changed under the filter; carries the total.
 */
export type TableViewportDelta =
  | {
      readonly kind: 'row_updated'
      readonly rowKey: string
      readonly row: TableRow
    }
  | {
      readonly kind: 'row_removed'
      readonly rowKey: string
      readonly reason: string
    }
  | { readonly kind: 'set_changed'; readonly totalCount: number }

/** A displayed row: its key, the resolved view-model, and whether it is a removed placeholder. */
export interface TableViewportRow<R> {
  readonly rowKey: string
  /** The resolved view-model, or `null` when the row is a removed placeholder. */
  readonly row: R | null
  /** True when an applied removal replaced the row with a placeholder in its slot. */
  readonly placeholder: boolean
  /** The kind of unapplied pending change waiting on this row, or null when none. */
  readonly pending: 'update' | 'remove' | null
}

/**
 * The sink the subscription wiring feeds a table's window snapshot and live
 * deltas into — implemented by {@link TableViewportController} and held untyped
 * by the subscription registry, since neither ingest method depends on the row
 * type `R`.
 */
export interface TableWindowSink {
  ingestWindow(rows: readonly TableRow[], totalCount: number): void
  ingestDelta(delta: TableViewportDelta): void
}

export interface TableViewportControllerOptions<R> {
  /**
   * Resolve one window row into its view-model. Called inside a computed signal,
   * so reading entity references through a scope selector makes the resolved rows
   * track entity updates reactively (the window rows already hold `EntityRef`s).
   */
  resolve: (row: TableRow) => R
  /**
   * Send the current viewport descriptor to the backend — typically
   * `HilosConnection.sendTableViewport` bound to this table's page and key. The
   * server answers with a `table_window` the controller ingests.
   */
  sendViewport: (descriptor: TableViewportDescriptor) => void
  /** Window size (rows per page); the descriptor's `limit`. Clamped to at least 1. */
  pageSize: number
  /** Initial filter map; empty by default. */
  initialFilter?: Record<string, unknown>
  /** Initial sort; none by default (the backend's arrival order). */
  initialSort?: TableSort
}

export class TableViewportController<R> implements TableWindowSink {
  private readonly filterSignal: WritableSignal<Record<string, unknown>>

  private readonly sortSignal: WritableSignal<TableSort | undefined>

  private readonly pageSignal = createSignal(0)

  private readonly windowSignal = createSignal<readonly TableRow[]>([])

  private readonly totalCountSignal = createSignal(0)

  /** False until the first window arrives — lets the view tell "loading" from "empty". */
  private readonly loadedSignal = createSignal(false)

  private readonly placeholderKeysSignal = createSignal<ReadonlySet<string>>(
    new Set(),
  )

  private readonly pendingCountSignal = createSignal(0)

  private readonly listChangedSignal = createSignal(false)

  private readonly searchSignal: ReadonlySignal<string>

  private readonly pageSize: number

  /** Pending content updates by row key — applied in place on apply(). */
  private readonly pendingUpdates = new Map<string, TableRow>()

  /** Pending removals by row key (value is the reason) — become placeholders on apply(). */
  private readonly pendingRemoved = new Map<string, string>()

  /** Pending new total under the filter, or null when the set has not changed. */
  private pendingTotalCount: number | null = null

  /** Per-row pending kind ('update' | 'remove') driving the row highlight; rebuilt on every pending change. */
  private readonly pendingKindSignal = createSignal<
    ReadonlyMap<string, 'update' | 'remove'>
  >(new Map())

  /**
   * Row keys this connection is itself changing — their echoed delta applies at
   * once instead of queuing as pending (see {@link expectOwnChange}).
   */
  private readonly ownRowKeys = new Set<string>()

  /** The displayed rows resolved to view-models — what the view renders. */
  readonly rows: ReadonlySignal<readonly TableViewportRow<R>[]>

  /** Total rows matching the filter, as the last applied window/change reported. */
  readonly totalCount: ReadonlySignal<number>

  /** Number of pages under the window size; at least 1. */
  readonly pageCount: ReadonlySignal<number>

  /** Count of accumulated pending changes (the badge); 0 when there is nothing to apply. */
  readonly pendingCount: ReadonlySignal<number>

  /**
   * Whether a pending set-change is waiting under the filter. The default
   * HilosViewportTable no longer renders a banner for this — the banner shifted
   * the table layout as it came and went — so this is kept as headless state a
   * consumer can surface without that jump.
   */
  readonly listChanged: ReadonlySignal<boolean>

  /** False until the first window has been ingested — the view shows "loading" rather than "empty". */
  readonly loaded: ReadonlySignal<boolean>

  constructor(private readonly options: TableViewportControllerOptions<R>) {
    this.pageSize = Math.max(1, Math.trunc(options.pageSize))
    this.filterSignal = createSignal<Record<string, unknown>>({
      ...(options.initialFilter ?? {}),
    })
    this.sortSignal = createSignal<TableSort | undefined>(options.initialSort)
    this.searchSignal = computedSignal(() => {
      const value = this.filterSignal.get()[SEARCH_FILTER_KEY]

      return typeof value === 'string' ? value : ''
    })
    this.rows = computedSignal(() => {
      const placeholders = this.placeholderKeysSignal.get()
      const pendingKinds = this.pendingKindSignal.get()

      return this.windowSignal.get().map((raw) => {
        const placeholder = placeholders.has(raw.rowKey)

        return {
          rowKey: raw.rowKey,
          row: placeholder ? null : options.resolve(raw),
          placeholder,
          pending: placeholder ? null : (pendingKinds.get(raw.rowKey) ?? null),
        }
      })
    })
    this.totalCount = this.totalCountSignal
    this.pageCount = computedSignal(() =>
      Math.max(1, Math.ceil(this.totalCountSignal.get() / this.pageSize)),
    )
    this.pendingCount = this.pendingCountSignal
    this.listChanged = this.listChangedSignal
    this.loaded = this.loadedSignal
  }

  /** The current search query (empty string when unset). */
  get search(): ReadonlySignal<string> {
    return this.searchSignal
  }

  /** The active sort, or `undefined` when unsorted. */
  get sort(): ReadonlySignal<TableSort | undefined> {
    return this.sortSignal
  }

  /** The current zero-based page index. */
  get page(): ReadonlySignal<number> {
    return this.pageSignal
  }

  /**
   * Request the initial window — send the current descriptor so the backend
   * replies the first `table_window`. Call once the table mounts.
   */
  start(): void {
    this.send()
  }

  /**
   * Set the search filter and return to the first page, discard pending (the new
   * window is authoritative), then request the new window. An empty query drops
   * the search filter entirely.
   *
   * @param query The raw search text.
   */
  setSearch(query: string): void {
    const filter = { ...this.filterSignal.get() }
    if (query.trim() === '') {
      delete filter[SEARCH_FILTER_KEY]
    } else {
      filter[SEARCH_FILTER_KEY] = query
    }
    this.filterSignal.set(filter)
    this.pageSignal.set(0)
    this.changeWindow()
  }

  /**
   * Sort by a field — first click ascending, clicking the active field again
   * flips the direction — return to the first page, then request the new window.
   *
   * @param field The field key to sort by.
   */
  setSort(field: string): void {
    const current = this.sortSignal.get()
    const direction: SortDirection =
      current?.field === field && current.direction === 'asc' ? 'desc' : 'asc'
    this.sortSignal.set({ field, direction })
    this.pageSignal.set(0)
    this.changeWindow()
  }

  /** Clear the active sort, return to the first page, then request the new window. */
  clearSort(): void {
    this.sortSignal.set(undefined)
    this.pageSignal.set(0)
    this.changeWindow()
  }

  /**
   * Go to a zero-based page, clamped into `[0, pageCount - 1]`, then request that
   * page's window.
   *
   * @param page The requested zero-based page index.
   */
  setPage(page: number): void {
    const last = this.pageCount.get() - 1
    this.pageSignal.set(Math.min(Math.max(0, page), Math.max(0, last)))
    this.changeWindow()
  }

  /**
   * Ingest a window snapshot from the backend (`table_window`): replace the
   * displayed rows and the total count, and drop any leftover pending and
   * placeholders — the fresh window is authoritative. Called by the subscription
   * wiring; the rows are already normalized to references.
   *
   * @param rows The window's rows, in display order.
   * @param totalCount Total rows matching the filter.
   */
  ingestWindow(rows: readonly TableRow[], totalCount: number): void {
    this.windowSignal.set(rows.slice())
    this.totalCountSignal.set(Math.max(0, totalCount))
    this.placeholderKeysSignal.set(new Set())
    this.loadedSignal.set(true)
    this.clearPending()
  }

  /**
   * Mark a row this connection is itself changing so its echoed delta is applied
   * at once instead of queuing as pending: the tab that made the edit picks up
   * its own change, while other tabs keep the pending gate (table-subscription.md).
   * The mark is dropped if `settled` rejects (the action failed, so no echo is
   * coming) or the window changes.
   *
   * KNOWN RACE: correlation is by row key, not by the action's id — the backend
   * does not thread the originating action through the delta fanout (see
   * BrowserContext::emitViewportDelta). If a concurrent change to the SAME row
   * lands between the edit and its echo, the marks can cross and one change is
   * left pending. Accepted as a minor bug; the precise fix is server-side tagging.
   *
   * @param rowKey The row key being changed.
   * @param settled The action's completion — rejects on failure (the tracked-action handle's `done`).
   */
  expectOwnChange(rowKey: string, settled: Promise<unknown>): void {
    this.ownRowKeys.add(rowKey)
    settled.catch(() => {
      this.ownRowKeys.delete(rowKey)
    })
  }

  /**
   * Accumulate one live delta as pending — never applied automatically. A row
   * delta is kept only when its row is in the current window (anchored by
   * row-id); a set-change is always recorded. An own-change echo for a marked
   * row is the exception: it applies immediately via {@link applyOwnDelta}.
   *
   * @param delta The normalized viewport delta.
   */
  ingestDelta(delta: TableViewportDelta): void {
    if (
      (delta.kind === 'row_updated' || delta.kind === 'row_removed') &&
      this.ownRowKeys.has(delta.rowKey)
    ) {
      this.applyOwnDelta(delta)

      return
    }
    switch (delta.kind) {
      case 'row_updated':
        if (this.isInWindow(delta.rowKey)) {
          this.pendingRemoved.delete(delta.rowKey)
          this.pendingUpdates.set(delta.rowKey, delta.row)
        }
        break
      case 'row_removed':
        if (this.isInWindow(delta.rowKey)) {
          this.pendingUpdates.delete(delta.rowKey)
          this.pendingRemoved.set(delta.rowKey, delta.reason)
        }
        break
      case 'set_changed':
        this.pendingTotalCount = delta.totalCount
        break
    }
    this.refreshPendingSignals()
  }

  /**
   * Apply an own-change echo for a marked row at once, resolving everything
   * accumulated for that row: this echo is the authoritative latest, so any
   * pending update / removal already queued for the same row is dropped, the row
   * is updated in place (or replaced by a placeholder), and the mark is consumed.
   *
   * @param delta The own-change echo (row_updated or row_removed).
   */
  private applyOwnDelta(
    delta: Extract<TableViewportDelta, { kind: 'row_updated' | 'row_removed' }>,
  ): void {
    this.ownRowKeys.delete(delta.rowKey)
    if (!this.isInWindow(delta.rowKey)) {
      return
    }
    this.pendingUpdates.delete(delta.rowKey)
    this.pendingRemoved.delete(delta.rowKey)
    const placeholders = new Set(this.placeholderKeysSignal.get())
    if (delta.kind === 'row_updated') {
      this.windowSignal.set(
        this.windowSignal
          .get()
          .map((row) => (row.rowKey === delta.rowKey ? delta.row : row)),
      )
      placeholders.delete(delta.rowKey)
    } else {
      placeholders.add(delta.rowKey)
    }
    this.placeholderKeysSignal.set(placeholders)
    this.refreshPendingSignals()
  }

  /**
   * Apply accumulated pending changes to the displayed rows in place: updates
   * replace their row, removals become placeholders in their slot (the layout is
   * not collapsed and no row is pulled from another page), and a set-change
   * refreshes the count. The window itself does not move.
   */
  apply(): void {
    if (this.pendingCountSignal.get() === 0) {
      return
    }

    if (this.pendingUpdates.size > 0) {
      this.windowSignal.set(
        this.windowSignal
          .get()
          .map((row) => this.pendingUpdates.get(row.rowKey) ?? row),
      )
    }

    if (this.pendingRemoved.size > 0) {
      const placeholders = new Set(this.placeholderKeysSignal.get())
      for (const rowKey of this.pendingRemoved.keys()) {
        placeholders.add(rowKey)
      }
      this.placeholderKeysSignal.set(placeholders)
    }

    if (this.pendingTotalCount !== null) {
      this.totalCountSignal.set(Math.max(0, this.pendingTotalCount))
    }

    this.clearPending()
  }

  /**
   * Apply all pending changes, then resolve the row's current view-model — used
   * when opening an edit / delete dialog so it reflects the latest committed
   * state. Returns null when the row is now a placeholder (its removal was
   * applied) or has left the window, so the dialog can decline to open.
   *
   * @param rowKey The row whose fresh view-model the dialog needs.
   */
  applyAndResolve(rowKey: string): R | null {
    this.apply()
    if (this.placeholderKeysSignal.get().has(rowKey)) {
      return null
    }
    const raw = this.windowSignal.get().find((row) => row.rowKey === rowKey)

    return raw ? this.options.resolve(raw) : null
  }

  private isInWindow(rowKey: string): boolean {
    return this.windowSignal.get().some((row) => row.rowKey === rowKey)
  }

  /** Discard pending and placeholders, then request the new window. */
  private changeWindow(): void {
    this.placeholderKeysSignal.set(new Set())
    this.ownRowKeys.clear()
    this.clearPending()
    this.send()
  }

  private clearPending(): void {
    this.pendingUpdates.clear()
    this.pendingRemoved.clear()
    this.pendingTotalCount = null
    this.refreshPendingSignals()
  }

  private refreshPendingSignals(): void {
    this.pendingCountSignal.set(
      this.pendingUpdates.size +
        this.pendingRemoved.size +
        (this.pendingTotalCount !== null ? 1 : 0),
    )
    this.listChangedSignal.set(this.pendingTotalCount !== null)
    const pendingKinds = new Map<string, 'update' | 'remove'>()
    for (const rowKey of this.pendingUpdates.keys()) {
      pendingKinds.set(rowKey, 'update')
    }
    for (const rowKey of this.pendingRemoved.keys()) {
      pendingKinds.set(rowKey, 'remove')
    }
    this.pendingKindSignal.set(pendingKinds)
  }

  /** The viewport descriptor for the current filter, sort, and page. */
  private descriptor(): TableViewportDescriptor {
    const sort = this.sortSignal.get()

    return {
      filter: { ...this.filterSignal.get() },
      sort: sort ? { field: sort.field, direction: sort.direction } : null,
      offset: this.pageSignal.get() * this.pageSize,
      limit: this.pageSize,
    }
  }

  private send(): void {
    this.options.sendViewport(this.descriptor())
  }
}
