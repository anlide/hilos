// The headless table controller: the framework-agnostic state machine a table
// view renders. It holds the CLIENT-side viewport — search query, sort, and the
// current page — over the page-scoped rows the normalizer maintains
// (ScopeManager.pageTableSignal), and exposes derived signals a thin per-
// framework view reads (multiframework-core.md). It owns no rendering and no DOM.
//
// In this first table-subscription step the model is snapshot + live-push: the
// raw rows update in place under the controller and the viewport is recomputed
// locally, with no pending/Apply. The server-computed viewport and the
// pending-change model from table-subscription.md are a later step (7.7) that
// slots in behind this same controller API.

import { type TableRow } from '../state/TableRowsStore.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
} from '../state/signal.js'

/** Sort direction for the active sort field. */
export type SortDirection = 'asc' | 'desc'

/** The active sort: which field, which direction. */
export interface TableSort {
  readonly field: string
  readonly direction: SortDirection
}

/** A resolved, display-ready table row: its stable key plus the view-model. */
export interface TableViewRow<R> {
  readonly rowKey: string
  readonly row: R
}

export interface TableControllerOptions<R> {
  /** The page-scoped raw rows, typically `scopes.pageTableSignal(tableKey)`. */
  source: ReadonlySignal<readonly TableRow[]>
  /**
   * Resolve one raw row into its view-model. Called inside a computed signal, so
   * reading entity references through a scope selector (e.g. `Users.signal(ref)`)
   * makes the resolved rows track entity updates reactively.
   */
  resolve: (row: TableRow) => R
  /**
   * The text a row contributes to search matching. Omit to disable search. The
   * controller lowercases both sides; return the row's searchable fields joined.
   */
  searchText?: (row: R) => string
  /**
   * The comparable value of a row under the active sort field. Omit to disable
   * sorting. `null`/`undefined` sort last regardless of direction.
   */
  sortValue?: (row: R, field: string) => string | number | null | undefined
  /** Page size; `0` (the default) means no pagination — all rows on one page. */
  pageSize?: number
  /** Initial sort; none by default (rows keep backend arrival order). */
  initialSort?: TableSort
}

export class TableController<R> {
  private readonly searchSignal = createSignal('')

  private readonly sortSignal: ReturnType<
    typeof createSignal<TableSort | undefined>
  >

  private readonly pageSignal = createSignal(0)

  private readonly pageSizeSignal: ReturnType<typeof createSignal<number>>

  /** Resolved view-model rows in backend arrival order. */
  readonly rows: ReadonlySignal<readonly TableViewRow<R>[]>

  /** Resolved rows after the search filter, before sort and pagination. */
  readonly filteredRows: ReadonlySignal<readonly TableViewRow<R>[]>

  /** Filtered rows after the active sort, before pagination. */
  readonly sortedRows: ReadonlySignal<readonly TableViewRow<R>[]>

  /** The rows actually on the current page — what the view renders. */
  readonly pageRows: ReadonlySignal<readonly TableViewRow<R>[]>

  /** Total rows after the search filter (the count pagination divides). */
  readonly totalCount: ReadonlySignal<number>

  /** Number of pages under the current page size; at least 1. */
  readonly pageCount: ReadonlySignal<number>

  constructor(private readonly options: TableControllerOptions<R>) {
    this.sortSignal = createSignal<TableSort | undefined>(options.initialSort)
    this.pageSizeSignal = createSignal<number>(options.pageSize ?? 0)

    this.rows = computedSignal(() =>
      options.source.get().map((raw) => ({
        rowKey: raw.rowKey,
        row: options.resolve(raw),
      })),
    )
    this.filteredRows = computedSignal(() => this.computeFiltered())
    this.sortedRows = computedSignal(() => this.computeSorted())
    this.totalCount = computedSignal(() => this.filteredRows.get().length)
    this.pageCount = computedSignal(() => {
      const size = this.pageSizeSignal.get()
      if (size <= 0) {
        return 1
      }

      return Math.max(1, Math.ceil(this.totalCount.get() / size))
    })
    this.pageRows = computedSignal(() => this.computePage())
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

  /** The current page size; `0` means no pagination. */
  get pageSize(): ReadonlySignal<number> {
    return this.pageSizeSignal
  }

  /**
   * Set the search query and return to the first page (the result set is
   * recomputed, so the old page index no longer means anything).
   *
   * @param query The raw search text.
   */
  setSearch(query: string): void {
    this.searchSignal.set(query)
    this.pageSignal.set(0)
  }

  /**
   * Sort by a field: the first click sorts ascending, clicking the active field
   * again flips the direction. Returns to the first page.
   *
   * @param field The field key to sort by.
   */
  setSort(field: string): void {
    const current = this.sortSignal.get()
    const direction: SortDirection =
      current?.field === field && current.direction === 'asc' ? 'desc' : 'asc'
    this.sortSignal.set({ field, direction })
    this.pageSignal.set(0)
  }

  /** Clear the active sort, restoring backend arrival order. */
  clearSort(): void {
    this.sortSignal.set(undefined)
    this.pageSignal.set(0)
  }

  /**
   * Go to a zero-based page; clamped into `[0, pageCount - 1]` so an out-of-range
   * request lands on a real page.
   *
   * @param page The requested zero-based page index.
   */
  setPage(page: number): void {
    const last = this.pageCount.get() - 1
    this.pageSignal.set(Math.min(Math.max(0, page), Math.max(0, last)))
  }

  /**
   * Change the page size and return to the first page. `0` disables pagination.
   *
   * @param size The new page size.
   */
  setPageSize(size: number): void {
    this.pageSizeSignal.set(Math.max(0, size))
    this.pageSignal.set(0)
  }

  private computeFiltered(): readonly TableViewRow<R>[] {
    const query = this.searchSignal.get().trim().toLowerCase()
    const searchText = this.options.searchText
    if (query === '' || !searchText) {
      return this.rows.get()
    }

    return this.rows
      .get()
      .filter((view) => searchText(view.row).toLowerCase().includes(query))
  }

  private computeSorted(): readonly TableViewRow<R>[] {
    const sort = this.sortSignal.get()
    const sortValue = this.options.sortValue
    const filtered = this.filteredRows.get()
    if (!sort || !sortValue) {
      return filtered
    }

    const factor = sort.direction === 'asc' ? 1 : -1

    return filtered.slice().sort((left, right) => {
      const leftValue = sortValue(left.row, sort.field)
      const rightValue = sortValue(right.row, sort.field)
      const leftMissing = leftValue == null
      const rightMissing = rightValue == null
      if (leftMissing || rightMissing) {
        // Missing values sort last in both directions, never flipped by factor.
        return leftMissing === rightMissing ? 0 : leftMissing ? 1 : -1
      }

      return factor * compareValues(leftValue, rightValue)
    })
  }

  private computePage(): readonly TableViewRow<R>[] {
    const size = this.pageSizeSignal.get()
    const sorted = this.sortedRows.get()
    if (size <= 0) {
      return sorted
    }
    const last = this.pageCount.get() - 1
    const page = Math.min(this.pageSignal.get(), Math.max(0, last))
    const start = page * size

    return sorted.slice(start, start + size)
  }
}

/**
 * Compare two present sort values: numbers numerically, everything else by
 * locale string. Missing values sort last and are handled by the caller, not
 * here, so direction never flips them.
 *
 * @param left The left value (never null/undefined).
 * @param right The right value (never null/undefined).
 * @return Negative, zero, or positive per the ascending comparison.
 */
function compareValues(left: string | number, right: string | number): number {
  if (typeof left === 'number' && typeof right === 'number') {
    return left - right
  }

  return String(left).localeCompare(String(right))
}
