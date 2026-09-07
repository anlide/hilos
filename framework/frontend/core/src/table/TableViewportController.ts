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

import {
  type TableAnchor,
  type TableAnchorDirection,
  type TableViewportDescriptor,
} from '../connection/HilosConnection.js'
import { type TableRow } from '../state/TableRowsStore.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
  type WritableSignal,
} from '../state/signal.js'
import {
  type HilosTableBody,
  type HilosTableFilterView,
  type HilosTableFooter,
  type HilosTableFrame,
  type HilosTableFrameState,
} from './tableFrame.js'
/** Sort direction for the active sort field. */
export type SortDirection = 'asc' | 'desc'

/** One component of the active order: which field, which direction. */
export interface TableSort {
  readonly field: string
  readonly direction: SortDirection
}

/**
 * The order a window runs in: its components in the sequence they apply, the
 * first one deciding. An order of one component is a click on a column header;
 * an order of more is one the table declared, picked whole.
 */
export type TableSortOrder = readonly TableSort[]

/** Generic filter-map key the search box writes; matches the backend `FILTER_KEY_SEARCH`. */
const SEARCH_FILTER_KEY = 'search'

/**
 * Whether two orders are the same state of the table — the same fields in the
 * same directions in the same sequence. An absent order (a table opened without
 * an initial one) is a state of its own, equal only to another absent order.
 *
 * @param one The state to compare.
 * @param other The state to compare it with.
 * @returns True when both run by the same components, or both are absent.
 */
function isSameOrder(
  one: TableSortOrder | undefined,
  other: TableSortOrder | undefined,
): boolean {
  if (one === undefined || other === undefined) {
    return one === other
  }

  return (
    one.length === other.length &&
    one.every(
      (component, index) =>
        component.field === other[index]?.field &&
        component.direction === other[index]?.direction,
    )
  )
}

/**
 * One live pending row change scoped to the connection's window, normalized from
 * a `table_viewport_delta` signal (the row already reduced to references):
 *
 * - `row_updated` — a shown row's content changed; carries the new row;
 * - `row_removed` — a shown row was deleted or left the set; carries the reason;
 * - `row_stale` — which of a shown row's sources stopped being kept up to date;
 *   carries the new list and nothing else.
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
  | {
      readonly kind: 'row_stale'
      readonly rowKey: string
      /** The row's slot keys that stopped being kept up to date; empty means current again. */
      readonly staleSources: readonly string[]
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
 * by the subscription wiring, since nothing on it depends on the row type `R`.
 * A pending row delta gates on apply(); a count update and a tail append are live.
 * It also answers the one question asked of a table rather than told to it — which
 * window it is holding — because the wiring is what registers the table with the
 * connection that reports its window on the next page subscribe.
 */
export interface TableWindowSink {
  ingestWindow(
    rows: readonly TableRow[],
    totalCount: number,
    totalExact: boolean,
    firstAnchor: TableAnchor | null,
    lastAnchor: TableAnchor | null,
    limit: number,
  ): void
  ingestSubscriptionWindow(
    rows: readonly TableRow[],
    totalCount: number,
    totalExact: boolean,
    firstAnchor: TableAnchor | null,
    lastAnchor: TableAnchor | null,
    limit: number,
    sort: TableSortOrder | undefined,
  ): void
  ingestDelta(delta: TableViewportDelta): void
  descriptor(): TableViewportDescriptor | null
  ingestCount(totalCount: number, totalExact: boolean): void
  ingestAppend(row: TableRow, totalCount: number, totalExact: boolean): void
  ingestOwnCreate(
    row: TableRow,
    position: number,
    totalCount: number,
    totalExact: boolean,
    requestId?: string | null,
  ): void
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
  /** Initial filter map; empty by default. */
  initialFilter?: Record<string, unknown>
  /**
   * Orders of more than one column this table declares, in the sequence the
   * "Order" menu offers them. The backend holds a composite order against the
   * very same list, so an order absent from here is one no window will be
   * served in.
   *
   * SCAFFOLD: no table declares one yet, and no view reads them — the "Order"
   * menu that offers them is HIL-802 (Vue) and HIL-811 (React, Angular).
   */
  declaredOrders?: readonly TableSortOrder[]
  /**
   * What this table's page declares about its frame — title, search, filters,
   * main action, columns, bulk actions, empty state. Optional because a table
   * that declares nothing still has a frame state to read: the footer and the
   * body follow from the window, not from the declaration.
   *
   * SCAFFOLD: no table declares one yet, and no view reads it — the bar and the
   * footer drawn from it are HIL-801 (Vue) and HIL-810 (React, Angular), and the
   * five framework pages move onto it in HIL-819. Until then a view keeps taking
   * its columns, label, and empty text as props.
   */
  frame?: HilosTableFrame
}

export class TableViewportController<R> implements TableWindowSink {
  private readonly filterSignal: WritableSignal<Record<string, unknown>>

  private readonly orderSignal: WritableSignal<TableSortOrder | undefined>

  private readonly pageSignal = createSignal(0)

  /** Place the window is asked from, or null for the edge {@link anchorDirection} points away from. */
  private anchor: TableAnchor | null = null

  /** Side of the anchor the window is asked from. */
  private anchorDirection: TableAnchorDirection = 'after'

  /** Page a jump asks for, or null while the window is paged by anchor. */
  private pageIndex: number | null = null

  /** Place the first row of the delivered window sits at, or null while it is empty. */
  private firstAnchor: TableAnchor | null = null

  /** Place the last row of the delivered window sits at, or null while it is empty. */
  private lastAnchor: TableAnchor | null = null

  private readonly windowSignal = createSignal<readonly TableRow[]>([])

  private readonly totalCountSignal = createSignal(0)

  /** Whether the total is the size of the set rather than the ceiling the count stopped at. */
  private readonly totalExactSignal = createSignal(true)

  /** False until the first window arrives — lets the view tell "loading" from "empty". */
  private readonly loadedSignal = createSignal(false)

  private readonly placeholderKeysSignal = createSignal<ReadonlySet<string>>(
    new Set(),
  )

  private readonly pendingCountSignal = createSignal(0)

  /** Request id of the last own-create ingested, or null when it was not tracked. */
  private readonly ownCreateRequestIdSignal = createSignal<string | null>(null)

  private readonly searchSignal: ReadonlySignal<string>

  /**
   * Window size, as the last window that arrived said it was.
   *
   * The size is declared by the table on the backend and travels on every window, so the
   * controller reads it rather than holding an opinion of its own: two declarations of one
   * quantity is how a footer comes to count rows a window never had. It is a signal because
   * the page count and the footer are computed from it.
   */
  private readonly pageSizeSignal = createSignal(1)

  /**
   * The order the table opened in, as the first window that arrived said it was.
   *
   * "Home" for the sort cycle and for a reset — which used to be a client-side option and is
   * now whatever the backend declared. Read off the first window rather than off every one:
   * later windows carry the order the reader chose, and coming home to that would leave no
   * way home at all.
   */
  private openingOrder: TableSortOrder | undefined = undefined

  /** Whether {@link openingOrder} has been read off a window yet. */
  private openingOrderKnown = false

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

  /**
   * Whether {@link totalCount} is the size of the set rather than the ceiling it stopped at.
   *
   * The backend counts a windowed query only up to a ceiling, so past it the total is that
   * ceiling and reads as "at least this many". Everything a reader gets from the total —
   * the page count, the numbered pages, the end of the set — follows from this too.
   */
  readonly totalExact: ReadonlySignal<boolean>

  /** Number of pages under the window size; at least 1, and null while the total is not exact. */
  readonly pageCount: ReadonlySignal<number | null>

  /**
   * Whether there is a page after this one to go to.
   *
   * With an exact total this is the page number against the page count. Without one there
   * is no last page to compare against, so the answer is read off the window itself: a
   * window filled to its size has rows behind it, and a short one is the end of the set.
   */
  readonly hasNextPage: ReadonlySignal<boolean>

  /** Count of accumulated pending changes (the badge); 0 when there is nothing to apply. */
  readonly pendingCount: ReadonlySignal<number>

  /** False until the first window has been ingested — the view shows "loading" rather than "empty". */
  readonly loaded: ReadonlySignal<boolean>

  /** The readable frame state, built once from the declaration and the window signals. */
  private readonly frameState: HilosTableFrameState

  constructor(private readonly options: TableViewportControllerOptions<R>) {
    this.filterSignal = createSignal<Record<string, unknown>>({
      ...(options.initialFilter ?? {}),
    })
    this.orderSignal = createSignal<TableSortOrder | undefined>(undefined)
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
    this.totalExact = this.totalExactSignal
    this.pageCount = computedSignal(() =>
      this.totalExactSignal.get()
        ? Math.max(
            1,
            Math.ceil(this.totalCountSignal.get() / this.pageSizeSignal.get()),
          )
        : null,
    )
    this.hasNextPage = computedSignal(() => {
      const pageCount = this.pageCount.get()

      return pageCount === null
        ? this.windowSignal.get().length >= this.pageSizeSignal.get()
        : this.pageSignal.get() < pageCount - 1
    })
    this.pendingCount = this.pendingCountSignal
    this.loaded = this.loadedSignal
    const declaration = options.frame ?? null
    const filterViews = computedSignal<readonly HilosTableFilterView[]>(() => {
      const filter = this.filterSignal.get()

      return (declaration?.filters ?? []).map((declared) => {
        if (declared.kind === 'date_range') {
          const from = filter[declared.fromKey]
          const to = filter[declared.toKey]

          return {
            filter: declared,
            value: { from, to },
            active: from !== undefined || to !== undefined,
          }
        }

        return {
          filter: declared,
          value: filter[declared.key],
          active: filter[declared.key] !== undefined,
        }
      })
    })
    const activeFilterCount = computedSignal(
      () => filterViews.get().filter((view) => view.active).length,
    )
    this.frameState = {
      declaration,
      filters: filterViews,
      activeFilterCount,
      footer: computedSignal<HilosTableFooter>(() => {
        const shown = this.windowSignal.get().length
        const page = this.pageSignal.get()
        const firstRow = shown === 0 ? 0 : page * this.pageSizeSignal.get() + 1

        return {
          firstRow,
          lastRow: shown === 0 ? 0 : firstRow + shown - 1,
          totalCount: this.totalCountSignal.get(),
          totalExact: this.totalExactSignal.get(),
          page,
          pageCount: this.pageCount.get(),
          hasPreviousPage: page > 0,
          hasNextPage: this.hasNextPage.get(),
        }
      }),
      body: computedSignal<HilosTableBody>(() => {
        if (!this.loadedSignal.get()) {
          return 'loading'
        }
        if (this.windowSignal.get().length > 0) {
          return 'rows'
        }

        return this.searchSignal.get() !== '' || activeFilterCount.get() > 0
          ? 'empty_filtered'
          : 'empty'
      }),
    }
  }

  /** The current search query (empty string when unset). */
  get search(): ReadonlySignal<string> {
    return this.searchSignal
  }

  /** The active order, or `undefined` when unsorted. */
  get order(): ReadonlySignal<TableSortOrder | undefined> {
    return this.orderSignal
  }

  /**
   * The orders of more than one column this table declares, in menu sequence.
   *
   * SCAFFOLD: read by the "Order" menu, which is HIL-802 and HIL-811.
   */
  get orders(): readonly TableSortOrder[] {
    return this.options.declaredOrders ?? []
  }

  /**
   * The frame state a view renders the bar and the footer from: what the page
   * declared, and the parts that follow from the window. A table that declared
   * no frame still has one to read — `declaration` is then null.
   *
   * SCAFFOLD: read by the bar and the footer, which are HIL-801 (Vue) and
   * HIL-810 (React, Angular).
   */
  get frame(): HilosTableFrameState {
    return this.frameState
  }

  /** The current zero-based page index. */
  get page(): ReadonlySignal<number> {
    return this.pageSignal
  }

  /**
   * The action behind the last of this receiver's own creates to land in the
   * window, or null when none has or the write was not tracked. A surface reads it
   * to recognize the result of a press it is still showing as in flight.
   */
  get ownCreateRequestId(): ReadonlySignal<string | null> {
    return this.ownCreateRequestIdSignal
  }

  /**
   * Ask the backend for this table's window again, unchanged.
   *
   * Not how a table opens — the first window arrives with the page's own answer since
   * HIL-642 — but how a page refreshes one that nothing else would refresh: an action whose
   * result the table cannot learn about live, such as a retry on the delivery journal, which
   * has no deltas of its own. A table on a live source never needs this.
   */
  refresh(): void {
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
    this.resetAddress()
    this.changeWindow()
  }

  /**
   * Set one domain filter-map entry and return to the first page, discard pending
   * (the new window is authoritative), then request the new window. A null /
   * undefined / empty-string value clears the entry, so a "no filter" option maps
   * to dropping the key rather than sending an empty value the backend must
   * special-case. The free-text search box has its own {@link setSearch}; this
   * drives the domain filters a page renders as its own controls (channel, status,
   * period, …), which ride the same open filter map to the backend query. It is
   * the one-entry case of {@link setFilters}, so the clearing rule lives in one
   * place.
   *
   * @param key The filter-map key (matches the backend TableQueryDTO filter key).
   * @param value The new value, or null/undefined/'' to clear the key.
   */
  setFilter(key: string, value: unknown): void {
    this.setFilters({ [key]: value })
  }

  /**
   * Set SEVERAL domain filter-map entries in one window change, then behave
   * exactly as {@link setFilter} does — the clearing rule is the same and lives
   * here, and the entries not named are left as they are.
   *
   * One control over two keys is what needs this: a date range writes both of
   * its bounds, and setting them one at a time would send two windows and show a
   * window filtered by a start with no end in between.
   *
   * @param values The filter-map entries to write; a null/undefined/'' value clears its key.
   */
  setFilters(values: Record<string, unknown>): void {
    const filter = { ...this.filterSignal.get() }
    for (const [key, value] of Object.entries(values)) {
      if (value === null || value === undefined || value === '') {
        delete filter[key]
      } else {
        filter[key] = value
      }
    }
    this.filterSignal.set(filter)
    this.resetAddress()
    this.changeWindow()
  }

  /**
   * Return the filters to the ones the table opened with and request that
   * window — what the "Reset filters" control of the "Nothing found" state does.
   *
   * The table opens with `initialFilter`, and that is what a reset comes back
   * to rather than an empty map: a route preset arrives that way, so resetting
   * to empty would turn the log of one channel into the log of all of them. The
   * search box is cleared along with the filters, being an entry of the very
   * same map — and "Nothing found" names the query and the filters together.
   */
  resetFilters(): void {
    this.filterSignal.set({ ...(this.options.initialFilter ?? {}) })
    this.resetAddress()
    this.changeWindow()
  }

  /**
   * Sort by a field — a cycle of three states on the clicked field: ascending,
   * descending, then the order the table opened with (no initial order means the
   * backend's own order). Which state comes next is read from the state on
   * display, not from a click count: a state already on display is skipped, so
   * the column the table opened sorted by has no dead click, and clicking any
   * other column starts its cycle at ascending. Return to the first page, then
   * request the new window.
   *
   * A click always leaves an order of one column, so it is also how a composite
   * order is left: the mockup gives the header that job, and a click that added
   * a column to the order would be the free builder the backend refuses to serve.
   *
   * @param field The field key to sort by.
   */
  setSort(field: string): void {
    const current = this.orderSignal.get()
    const cycle: readonly (TableSortOrder | undefined)[] = [
      [{ field, direction: 'asc' }],
      [{ field, direction: 'desc' }],
      this.openingOrder,
    ]
    const shown = cycle.findIndex((state) => isSameOrder(state, current))
    const next = cycle.findIndex(
      (state, position) => position > shown && !isSameOrder(state, current),
    )
    const state = next < 0 ? cycle[0] : cycle[next]
    if (isSameOrder(state, this.openingOrder)) {
      // The cycle came home, and coming home is one operation however it was
      // asked for — through the last click of the cycle here, or through the
      // reset a page offers on its own.
      this.resetOrder()

      return
    }
    this.orderSignal.set(state)
    this.resetAddress()
    this.changeWindow()
  }

  /**
   * Run the window in one of the orders the table declares. The order is taken
   * whole — the reader picks it from the list rather than assembling it — and the
   * backend holds it against that same list before any of it reaches a query.
   * Return to the first page, then request the new window.
   *
   * SCAFFOLD: called by the "Order" menu, which is HIL-802 and HIL-811.
   *
   * @param order The order to run the window in.
   */
  setOrder(order: TableSortOrder): void {
    this.orderSignal.set(order)
    this.resetAddress()
    this.changeWindow()
  }

  /**
   * Return to the order the table opened with — for a table without an initial
   * one that is no order at all, the backend's own — return to the first page,
   * then request the new window. The last click of a column's cycle arrives
   * here; a page is free to call it from a reset control of its own.
   */
  resetOrder(): void {
    this.orderSignal.set(this.openingOrder)
    this.resetAddress()
    this.changeWindow()
  }

  /**
   * Jump to a zero-based page, clamped into `[0, pageCount - 1]`, then request that
   * page's window. This is the one address an anchor cannot express: page seven has no
   * anchor until somebody has shown it, so the server is asked for it by number and
   * counts to it from whichever end of the set is nearer.
   *
   * Use {@link nextPage} and {@link prevPage} for the neighbours — their boundary is
   * already in hand, and asking for them by number would count rows for nothing.
   *
   * Does nothing while the total is not exact: a page number is a place counted from an
   * end of the set, and without the size of the set there is no such place to jump to.
   *
   * @param page The requested zero-based page index.
   */
  setPage(page: number): void {
    const pageCount = this.pageCount.get()
    if (pageCount === null) {
      return
    }
    const target = Math.min(Math.max(0, page), Math.max(0, pageCount - 1))
    this.pageSignal.set(target)
    this.anchor = null
    this.anchorDirection = 'after'
    this.pageIndex = target
    this.changeWindow()
  }

  /**
   * Go to the next page — the rows after the window's last one — then request it. Does
   * nothing on the last page, where there is nothing after the window to ask for, and
   * nothing on an empty window: with no boundary in hand there is nothing to page from,
   * and moving the number anyway would show one page while asking for another.
   *
   * Whether there is a next page is {@link hasNextPage}, which is also what the view
   * disables the control by — one rule, read in one place.
   */
  nextPage(): void {
    if (this.lastAnchor === null || !this.hasNextPage.get()) {
      return
    }
    this.anchor = this.lastAnchor
    this.anchorDirection = 'after'
    this.pageIndex = null
    this.pageSignal.set(this.pageSignal.get() + 1)
    this.changeWindow()
  }

  /**
   * Go to the previous page — the rows before the window's first one — then request it.
   * Does nothing on the first page, where there is nothing before the window to ask for,
   * and nothing on an empty window, for the reason {@link nextPage} gives.
   */
  prevPage(): void {
    if (this.firstAnchor === null || this.pageSignal.get() <= 0) {
      return
    }
    this.anchor = this.firstAnchor
    this.anchorDirection = 'before'
    this.pageIndex = null
    this.pageSignal.set(this.pageSignal.get() - 1)
    this.changeWindow()
  }

  /**
   * Ingest a window snapshot from the backend (`table_window`): replace the
   * displayed rows and the total count, and drop any leftover pending and
   * placeholders — the fresh window is authoritative. Called by the subscription
   * wiring; the rows are already normalized to references.
   *
   * A window is also the only thing that makes an inexact total exact again: it is the one
   * moment the set is read, so a window that stopped at the ceiling last time may well come
   * back with a number this time, and the other way round.
   *
   * @param rows The window's rows, in display order.
   * @param totalCount Total rows matching the filter.
   * @param totalExact Whether that total is the size of the set rather than the ceiling it stopped at.
   * @param firstAnchor Place the first row sits at, or null when the window is empty.
   * @param lastAnchor Place the last row sits at, or null when the window is empty.
   * @param limit How many rows the window carries — the size the backend served it at.
   */
  ingestWindow(
    rows: readonly TableRow[],
    totalCount: number,
    totalExact: boolean,
    firstAnchor: TableAnchor | null,
    lastAnchor: TableAnchor | null,
    limit: number,
  ): void {
    this.windowSignal.set(rows.slice())
    this.totalCountSignal.set(Math.max(0, totalCount))
    this.totalExactSignal.set(totalExact)
    this.firstAnchor = firstAnchor
    this.lastAnchor = lastAnchor
    this.pageSizeSignal.set(Math.max(1, Math.trunc(limit)))
    this.placeholderKeysSignal.set(new Set())
    this.loadedSignal.set(true)
    this.clearPending()
  }

  /**
   * Ingest the window that arrived with the page's own subscription answer (HIL-642).
   *
   * The same window as any other, and one thing besides: it says which order it ran in. This
   * is the one frame that has to — a reply to a request echoes back an order the reader
   * chose, while a cold entry runs in the order the table declares on the backend, and
   * nothing on this side would otherwise know what that is. The first one to arrive also
   * settles where a reset of the order goes home to.
   *
   * @param rows The window's rows, in display order.
   * @param totalCount Total rows matching the filter.
   * @param totalExact Whether that total is the size of the set rather than the ceiling it stopped at.
   * @param firstAnchor Place the first row sits at, or null when the window is empty.
   * @param lastAnchor Place the last row sits at, or null when the window is empty.
   * @param limit How many rows the window carries — the size the backend served it at.
   * @param sort The order the window ran in, or undefined when it ran in none.
   */
  ingestSubscriptionWindow(
    rows: readonly TableRow[],
    totalCount: number,
    totalExact: boolean,
    firstAnchor: TableAnchor | null,
    lastAnchor: TableAnchor | null,
    limit: number,
    sort: TableSortOrder | undefined,
  ): void {
    this.orderSignal.set(sort)
    if (!this.openingOrderKnown) {
      // Where the sort cycle and a reset come home to: the order the table opened in, which
      // is the backend's declaration and never a later choice of the reader's.
      this.openingOrder = sort
      this.openingOrderKnown = true
    }
    this.ingestWindow(
      rows,
      totalCount,
      totalExact,
      firstAnchor,
      lastAnchor,
      limit,
    )
  }

  /**
   * Ingest a live count update (`table_viewport_count`): set the total — and thus
   * the page count — at once. The count is navigation metadata, not row content,
   * so it is never gated as pending; the pager reflects the real total at once,
   * even while a removed row still shows as a placeholder.
   *
   * @param totalCount Total rows matching the filter.
   * @param totalExact Whether that total is the size of the set rather than the ceiling it stopped at.
   */
  ingestCount(totalCount: number, totalExact: boolean): void {
    this.totalCountSignal.set(Math.max(0, totalCount))
    this.totalExactSignal.set(totalExact)
  }

  /**
   * Ingest a live tail append (`table_viewport_append`): add the new row at the
   * END of the window and set the total. Sent only when this window is the last
   * page with room, so the row fits without pushing any shown row out — there is
   * nothing to gate and it applies at once. The row is already normalized to refs.
   *
   * @param row The new row to append, in reference form.
   * @param totalCount Total rows matching the filter.
   * @param totalExact Whether that total is the size of the set rather than the ceiling it stopped at.
   */
  ingestAppend(row: TableRow, totalCount: number, totalExact: boolean): void {
    this.windowSignal.set([...this.windowSignal.get(), row])
    this.totalCountSignal.set(Math.max(0, totalCount))
    this.totalExactSignal.set(totalExact)
  }

  /**
   * Ingest the receiver's own new row (`table_viewport_own_create`): insert it at
   * the index the server computed and set the total. Sent only to the connection
   * that created the row, and only when the row lands inside this window, so it
   * applies at once — the author is looking at the result of its own press and has
   * nothing to gate against.
   *
   * The window keeps its size: an insert pushes the last row past the end, exactly
   * as a reload would, and that row leaves with whatever was queued against it -
   * a pending change nobody can apply any more, or a placeholder standing where a
   * row used to be. The row is already normalized to refs.
   *
   * The arriving key is cleared of whatever the window still holds under it, the
   * same way {@link applyOwnDelta} clears an own update. A create may reuse the key
   * of a row deleted in this very window: the server has forgotten that row and
   * sends the create, while here its tombstone is still standing — placeholders
   * live until the next window — and without this the key would sit in the window
   * twice, the fresh row rendering as the removed one.
   *
   * `requestId` names the action behind the row and is kept on
   * {@link ownCreateRequestId}, so a surface can tell which of its own presses this
   * row answers — an ack only ever said "accepted", and for a backup the record
   * arrives minutes later. The window itself does not read it.
   *
   * @param row The new row to insert, in reference form.
   * @param position Zero-based index the row takes in the window.
   * @param totalCount Total rows matching the filter.
   * @param totalExact Whether that total is the size of the set rather than the ceiling it stopped at.
   * @param requestId Request id of the action that created the row, when it was tracked.
   */
  ingestOwnCreate(
    row: TableRow,
    position: number,
    totalCount: number,
    totalExact: boolean,
    requestId?: string | null,
  ): void {
    this.ownCreateRequestIdSignal.set(requestId ?? null)
    const rows = this.windowSignal
      .get()
      .filter((shown) => shown.rowKey !== row.rowKey)
    rows.splice(
      Math.min(Math.max(0, Math.trunc(position)), rows.length),
      0,
      row,
    )
    const evicted = rows.splice(this.pageSizeSignal.get())
    this.windowSignal.set(rows)
    this.totalCountSignal.set(Math.max(0, totalCount))
    this.totalExactSignal.set(totalExact)

    const placeholders = new Set(this.placeholderKeysSignal.get())
    for (const gone of [...evicted, row]) {
      this.pendingUpdates.delete(gone.rowKey)
      this.pendingRemoved.delete(gone.rowKey)
      placeholders.delete(gone.rowKey)
    }
    this.placeholderKeysSignal.set(placeholders)
    this.refreshPendingSignals()
  }

  /**
   * Accumulate one live row delta as pending — never applied automatically. The
   * delta is kept only when its row is in the current window (anchored by row-id).
   * A backend-tagged own-change is the exception: the server marks the delta `own`
   * for the connection that authored it, and it applies immediately via
   * {@link applyOwnDelta}.
   *
   * A freshness delta is taken before either of those doors and passes through
   * neither: it changes no value, so there is nothing for the gate to hold, and
   * nothing queued for the row may be resolved by it.
   *
   * @param delta The normalized viewport delta.
   */
  ingestDelta(delta: TableViewportDelta): void {
    if (delta.kind === 'row_stale') {
      this.applyRowStaleness(delta.rowKey, delta.staleSources)

      return
    }
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
   * Replace one shown row's list of sources that are no longer being kept up to date.
   *
   * The row's VALUES are left exactly as they are, and nothing queued for the row is
   * resolved or dropped: this says nothing about content, and a reader who has not pressed
   * Apply has not accepted anything by learning that a source went quiet. Holding the mark
   * behind that gate was the alternative, and it is the outcome the ticket exists to
   * prevent — until the press, a frozen number would go on looking fresh.
   *
   * A queued update for the same row is re-stamped with the new list for exactly that
   * reason. The mark belongs to the row and not to one copy of it, and the queued copy was
   * built when the server sent it — pressing Apply would otherwise put a row carrying the
   * freshness of that earlier moment on screen, silently taking the mark off values that
   * are still frozen.
   *
   * A row outside the window is ignored, the way a content delta for one is.
   *
   * @param rowKey The shown row whose freshness moved.
   * @param staleSources The row's slot keys that are no longer current; empty clears the mark.
   */
  private applyRowStaleness(
    rowKey: string,
    staleSources: readonly string[],
  ): void {
    if (!this.isInWindow(rowKey)) {
      return
    }
    this.windowSignal.set(
      this.windowSignal
        .get()
        .map((row) => (row.rowKey === rowKey ? { ...row, staleSources } : row)),
    )
    const queued = this.pendingUpdates.get(rowKey)
    if (queued) {
      this.pendingUpdates.set(rowKey, { ...queued, staleSources })
    }
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

  /**
   * The window this table is holding, or null while it is holding none.
   *
   * Asked when a page subscribes: a tab coming back after a broken socket is the only side
   * that still remembers what was on the screen, so it reports its windows in that frame and
   * the backend serves them back. A controller that has never received a window has nothing
   * to report — it does not know its own size or order until one arrives — and says so; that
   * is the cold entry, and the table's own declaration answers for it.
   *
   * @return The current descriptor, or null while no window has arrived.
   */
  descriptor(): TableViewportDescriptor | null {
    return this.loadedSignal.get() ? this.currentDescriptor() : null
  }

  /** The viewport descriptor for the current filter, order, size and address. */
  private currentDescriptor(): TableViewportDescriptor {
    const order = this.orderSignal.get()

    return {
      filter: { ...this.filterSignal.get() },
      sort:
        order === undefined
          ? null
          : order.map((component) => ({
              field: component.field,
              direction: component.direction,
            })),
      limit: this.pageSizeSignal.get(),
      anchor: this.anchor,
      anchorDirection: this.anchorDirection,
      pageIndex: this.pageIndex,
    }
  }

  /**
   * Send the window back to the start of the set, which is what a new filter or sort asks
   * for: the anchors in hand belong to an ordering that no longer exists.
   */
  private resetAddress(): void {
    this.anchor = null
    this.anchorDirection = 'after'
    this.pageIndex = null
    this.pageSignal.set(0)
  }

  private send(): void {
    this.options.sendViewport(this.currentDescriptor())
  }
}
