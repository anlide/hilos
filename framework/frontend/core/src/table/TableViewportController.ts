// The headless SERVER-WINDOWED table controller (table-subscription.md). Unlike
// the client-side TableController — which filters/sorts/paginates all delivered
// rows locally — here the window comes from the backend: the view's intents
// (search / sort / paginate) change the viewport descriptor and are sent to the
// server, which replies a table_window snapshot the controller displays. The
// pending/Apply model (table_viewport_delta accumulation) layers on top of this
// in the next step; this step is the descriptor + windowed-load half. It owns no
// rendering and no DOM.

import { type TableViewportDescriptor } from '../connection/HilosConnection.js'
import { type TableRow } from '../state/TableRowsStore.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
  type WritableSignal,
} from '../state/signal.js'
import {
  type SortDirection,
  type TableSort,
  type TableViewRow,
} from './TableController.js'

/** Generic filter-map key the search box writes; matches the backend `FILTER_KEY_SEARCH`. */
const SEARCH_FILTER_KEY = 'search'

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

export class TableViewportController<R> {
  private readonly filterSignal: WritableSignal<Record<string, unknown>>

  private readonly sortSignal: WritableSignal<TableSort | undefined>

  private readonly pageSignal = createSignal(0)

  private readonly windowSignal = createSignal<readonly TableRow[]>([])

  private readonly totalCountSignal = createSignal(0)

  private readonly searchSignal: ReadonlySignal<string>

  private readonly pageSize: number

  /** The window rows resolved to view-models — what the view renders. */
  readonly rows: ReadonlySignal<readonly TableViewRow<R>[]>

  /** Total rows matching the filter, as the last window reported. */
  readonly totalCount: ReadonlySignal<number>

  /** Number of pages under the window size; at least 1. */
  readonly pageCount: ReadonlySignal<number>

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
    this.rows = computedSignal(() =>
      this.windowSignal.get().map((raw) => ({
        rowKey: raw.rowKey,
        row: options.resolve(raw),
      })),
    )
    this.totalCount = this.totalCountSignal
    this.pageCount = computedSignal(() =>
      Math.max(1, Math.ceil(this.totalCountSignal.get() / this.pageSize)),
    )
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
   * Set the search filter and return to the first page, then request the new
   * window. An empty query drops the search filter entirely.
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
    this.send()
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
    this.send()
  }

  /** Clear the active sort, return to the first page, then request the new window. */
  clearSort(): void {
    this.sortSignal.set(undefined)
    this.pageSignal.set(0)
    this.send()
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
    this.send()
  }

  /**
   * Ingest a window snapshot from the backend (`table_window`): replace the
   * displayed rows and the total count. Called by the subscription wiring; the
   * rows are already normalized to references.
   *
   * @param rows The window's rows, in display order.
   * @param totalCount Total rows matching the filter.
   */
  ingestWindow(rows: readonly TableRow[], totalCount: number): void {
    this.windowSignal.set(rows.slice())
    this.totalCountSignal.set(Math.max(0, totalCount))
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
