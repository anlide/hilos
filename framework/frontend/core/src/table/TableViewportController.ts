// The headless SERVER-WINDOWED table controller (table-subscription.md). Unlike
// the client-side TableController — which filters/sorts/paginates all delivered
// rows locally — here the window comes from the backend: the view's intents
// (search / sort / paginate) change the viewport descriptor and are sent to the
// server, which replies a table_window snapshot the controller displays.
//
// Edits and removals of the SHOWN rows arrive as table_viewport_delta and DO NOT
// auto-apply: they accumulate as PENDING so the table never rearranges under the
// user's hands. The user resolves them with apply() — updates land in place and a
// removed row becomes a placeholder in its slot (the layout never collapses, no
// row is pulled from the next page). Two live signals bypass the pending gate
// because they disrupt nothing: table_viewport_count updates the total/page count
// (navigation metadata), and table_viewport_append adds a row at the tail when the
// window is the last page with room. An explicit window change discards pending
// instead, since the new window the server returns is authoritative. The
// controller owns no rendering and no DOM.

import { type TableViewportDescriptor } from '../connection/HilosConnection.js'
import { type TableRow } from '../state/TableRowsStore.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
  type WritableSignal,
} from '../state/signal.js'
/** Sort direction for the active sort field. */
export type SortDirection = 'asc' | 'desc'

/** The active sort: which field, which direction. */
export interface TableSort {
  readonly field: string
  readonly direction: SortDirection
}

/** Generic filter-map key the search box writes; matches the backend `FILTER_KEY_SEARCH`. */
const SEARCH_FILTER_KEY = 'search'

/**
 * Whether two sort states are the same state of the table — an absent sort (a
 * table opened without an initial sort) is a state of its own, equal only to
 * another absent sort.
 *
 * @param one The state to compare.
 * @param other The state to compare it with.
 * @returns True when both name the same field and direction, or both are absent.
 */
function isSameSort(
  one: TableSort | undefined,
  other: TableSort | undefined,
): boolean {
  return one?.field === other?.field && one?.direction === other?.direction
}

/**
 * One live pending row change scoped to the connection's window, normalized from
 * a `table_viewport_delta` signal (the row already reduced to references):
 *
 * - `row_updated` — a shown row's content changed; carries the new row;
 * - `row_removed` — a shown row was deleted or left the set; carries the reason.
 *
 * Count and append changes are live, not pending, and arrive through their own
 * sink methods ({@link TableWindowSink.ingestCount} / `ingestAppend`).
 */
export type TableViewportDelta =
  | {
      readonly kind: 'row_updated'
      readonly rowKey: string
      readonly row: TableRow
      /** The backend declared this change live: apply it now, never gate it. */
      readonly live?: boolean
      /** The backend tagged this receiver as the change's author: apply it now, resolving any queued pending. */
      readonly own?: boolean
    }
  | {
      readonly kind: 'row_removed'
      readonly rowKey: string
      readonly reason: string
      /** The backend declared this change live: apply it now, never gate it. */
      readonly live?: boolean
      /** The backend tagged this receiver as the change's author: apply it now, resolving any queued pending. */
      readonly own?: boolean
    }

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
 * changes into — implemented by {@link TableViewportController} and held untyped
 * by the subscription wiring, since no ingest method depends on the row type `R`.
 * A pending row delta gates on apply(); a count update and a tail append are live.
 */
export interface TableWindowSink {
  ingestWindow(rows: readonly TableRow[], totalCount: number): void
  ingestDelta(delta: TableViewportDelta): void
  ingestCount(totalCount: number): void
  ingestAppend(row: TableRow, totalCount: number): void
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

  private readonly searchSignal: ReadonlySignal<string>

  private readonly pageSize: number

  /** Pending content updates by row key — applied in place on apply(). */
  private readonly pendingUpdates = new Map<string, TableRow>()

  /** Pending removals by row key (value is the reason) — become placeholders on apply(). */
  private readonly pendingRemoved = new Map<string, string>()

  /** Per-row pending kind ('update' | 'remove') driving the row highlight; rebuilt on every pending change. */
  private readonly pendingKindSignal = createSignal<
    ReadonlyMap<string, 'update' | 'remove'>
  >(new Map())

  /** The displayed rows resolved to view-models — what the view renders. */
  readonly rows: ReadonlySignal<readonly TableViewportRow<R>[]>

  /** Total rows matching the filter, as the last applied window/change reported. */
  readonly totalCount: ReadonlySignal<number>

  /** Number of pages under the window size; at least 1. */
  readonly pageCount: ReadonlySignal<number>

  /** Count of accumulated pending changes (the badge); 0 when there is nothing to apply. */
  readonly pendingCount: ReadonlySignal<number>

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
   * Set one domain filter-map entry and return to the first page, discard pending
   * (the new window is authoritative), then request the new window. A null /
   * undefined / empty-string value clears the entry, so a "no filter" option maps
   * to dropping the key rather than sending an empty value the backend must
   * special-case. The free-text search box has its own {@link setSearch}; this
   * drives the domain filters a page renders as its own controls (channel, status,
   * period, …), which ride the same open filter map to the backend query.
   *
   * @param key The filter-map key (matches the backend TableQueryDTO filter key).
   * @param value The new value, or null/undefined/'' to clear the key.
   */
  setFilter(key: string, value: unknown): void {
    const filter = { ...this.filterSignal.get() }
    if (value === null || value === undefined || value === '') {
      delete filter[key]
    } else {
      filter[key] = value
    }
    this.filterSignal.set(filter)
    this.pageSignal.set(0)
    this.changeWindow()
  }

  /**
   * Sort by a field — a cycle of three states on the clicked field: ascending,
   * descending, then the sort the table opened with (no initial sort means the
   * backend's own order). Which state comes next is read from the state on
   * display, not from a click count: a state already on display is skipped, so
   * the column the table opened sorted by has no dead click, and clicking any
   * other column starts its cycle at ascending. Return to the first page, then
   * request the new window.
   *
   * @param field The field key to sort by.
   */
  setSort(field: string): void {
    const current = this.sortSignal.get()
    const cycle: readonly (TableSort | undefined)[] = [
      { field, direction: 'asc' },
      { field, direction: 'desc' },
      this.options.initialSort,
    ]
    const shown = cycle.findIndex((state) => isSameSort(state, current))
    const next = cycle.findIndex(
      (state, position) => position > shown && !isSameSort(state, current),
    )
    const state = next < 0 ? cycle[0] : cycle[next]
    if (isSameSort(state, this.options.initialSort)) {
      // The cycle came home, and coming home is one operation however it was
      // asked for — through the last click of the cycle here, or through the
      // reset a page offers on its own.
      this.resetSort()

      return
    }
    this.sortSignal.set(state)
    this.pageSignal.set(0)
    this.changeWindow()
  }

  /**
   * Return to the sort the table opened with — for a table without an initial
   * sort that is no sort at all, the backend's own order — return to the first
   * page, then request the new window. The last click of a column's cycle
   * arrives here; a page is free to call it from a reset control of its own.
   */
  resetSort(): void {
    this.sortSignal.set(this.options.initialSort)
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
   * Ingest a live count update (`table_viewport_count`): set the total — and thus
   * the page count — at once. The count is navigation metadata, not row content,
   * so it is never gated as pending; the pager reflects the real total at once,
   * even while a removed row still shows as a placeholder.
   *
   * @param totalCount Total rows matching the filter.
   */
  ingestCount(totalCount: number): void {
    this.totalCountSignal.set(Math.max(0, totalCount))
  }

  /**
   * Ingest a live tail append (`table_viewport_append`): add the new row at the
   * END of the window and set the total. Sent only when this window is the last
   * page with room, so the row fits without pushing any shown row out — there is
   * nothing to gate and it applies at once. The row is already normalized to refs.
   *
   * @param row The new row to append, in reference form.
   * @param totalCount Total rows matching the filter.
   */
  ingestAppend(row: TableRow, totalCount: number): void {
    this.windowSignal.set([...this.windowSignal.get(), row])
    this.totalCountSignal.set(Math.max(0, totalCount))
  }

  /**
   * Accumulate one live row delta as pending — never applied automatically. The
   * delta is kept only when its row is in the current window (anchored by row-id).
   * A backend-tagged own-change is the exception: the server marks the delta `own`
   * for the connection that authored it, and it applies immediately via
   * {@link applyOwnDelta}.
   *
   * @param delta The normalized viewport delta.
   */
  ingestDelta(delta: TableViewportDelta): void {
    if (delta.live === true) {
      this.applyLiveDelta(delta)

      return
    }
    if (
      delta.own === true &&
      (delta.kind === 'row_updated' || delta.kind === 'row_removed')
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
    }
    this.refreshPendingSignals()
  }

  /**
   * Apply a backend-declared live change at once, gate or no gate.
   *
   * A live row is a status the table shows *about* work — an in-progress row, a
   * progress bar — not content the reader is studying, so the frozen-viewport rule
   * does not apply to it: an update lands in place and a removal takes the row out
   * of the window entirely rather than leaving a placeholder in its slot, because a
   * status that ended has nothing to hold a place for. The count that accompanies
   * the change is already live.
   *
   * @param delta The live delta (row_updated or row_removed).
   */
  private applyLiveDelta(delta: TableViewportDelta): void {
    this.pendingUpdates.delete(delta.rowKey)
    this.pendingRemoved.delete(delta.rowKey)

    if (delta.kind === 'row_updated') {
      if (this.isInWindow(delta.rowKey)) {
        this.windowSignal.set(
          this.windowSignal
            .get()
            .map((row) => (row.rowKey === delta.rowKey ? delta.row : row)),
        )
      }
    } else {
      this.windowSignal.set(
        this.windowSignal.get().filter((row) => row.rowKey !== delta.rowKey),
      )
      const placeholders = new Set(this.placeholderKeysSignal.get())
      if (placeholders.delete(delta.rowKey)) {
        this.placeholderKeysSignal.set(placeholders)
      }
    }

    this.refreshPendingSignals()
  }

  /**
   * Apply a backend-tagged own-change at once, resolving everything accumulated
   * for that row: this echo is the authoritative latest, so any pending update /
   * removal already queued for the same row is dropped, and the row is updated in
   * place (or replaced by a placeholder).
   *
   * @param delta The own-change echo (row_updated or row_removed).
   */
  private applyOwnDelta(
    delta: Extract<TableViewportDelta, { kind: 'row_updated' | 'row_removed' }>,
  ): void {
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
   * replace their row and removals become placeholders in their slot (the layout
   * is not collapsed and no row is pulled from another page). The window itself
   * does not move; the count is already live and not part of pending.
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
    this.clearPending()
    this.send()
  }

  private clearPending(): void {
    this.pendingUpdates.clear()
    this.pendingRemoved.clear()
    this.refreshPendingSignals()
  }

  private refreshPendingSignals(): void {
    this.pendingCountSignal.set(
      this.pendingUpdates.size + this.pendingRemoved.size,
    )
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
