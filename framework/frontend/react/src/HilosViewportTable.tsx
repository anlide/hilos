// HilosViewportTable — the thin React view over the SERVER-WINDOWED
// TableViewportController. Search, sort, and paging change the viewport
// descriptor and are sent to the backend (NO local filtering); live changes
// arrive as pending and are resolved with the Apply button. A removed row
// renders as a placeholder in its slot — the layout never collapses. It holds
// NO table logic (multiframework-core.md): the controller owns the descriptor,
// pending, and Apply. Body cells come from the `row` render prop; the
// placeholder, header, paging, and the pending bar stay framework-owned.
// (Distinct from HilosTable, the client-side view.) Bootstrap classes only.
import type { ReactNode } from 'react'
import type { HilosTableColumn, TableViewportController } from '@hilos/core'

import { useSignal } from './useSignal.js'

/** Props for {@link HilosViewportTable}. */
export interface HilosViewportTableProps<R> {
  /** The headless server-windowed controller driving rows, descriptor, and pending. */
  controller: TableViewportController<R>
  /** Column declarations for the header (labels and sort controls). */
  columns: HilosTableColumn[]
  /** Render the cells of one row; the returned `<td>`s fill the row. */
  row: (row: R, rowKey: string) => ReactNode
  /** Accessible name for the table, rendered as a visually-hidden caption. */
  label?: string
  /** Show the search box above the table. */
  searchable?: boolean
  /** Placeholder for the search box. */
  searchPlaceholder?: string
  /** Message shown when there are no rows. */
  emptyText?: string
  /** Message shown while the first window is still loading. */
  loadingText?: string
  /** Replace the empty-state cell content. */
  empty?: ReactNode
  /** Label shown in a removed row's placeholder slot. */
  placeholderText?: string
}

// A row with an unapplied pending change gets a subtle, theme-aware tint that
// stands out from the zebra striping: amber for a waiting update, red for a
// waiting removal. Bootstrap's contextual row classes carry their own dark-mode
// variants, so they adapt to the active theme with no custom style layer.
const PENDING_ROW_CLASS: Record<'update' | 'remove', string> = {
  update: 'table-warning',
  remove: 'table-danger',
}

/**
 * The framework-owned table chrome over a headless {@link TableViewportController}.
 *
 * @param props The controller, columns, row renderer, and label / search / empty / placeholder config.
 */
export function HilosViewportTable<R>({
  controller,
  columns,
  row,
  label,
  searchable = false,
  searchPlaceholder = 'Search…',
  emptyText = 'No rows.',
  loadingText = 'Loading…',
  empty,
  placeholderText = 'Removed',
}: HilosViewportTableProps<R>) {
  const rows = useSignal(controller.rows)
  const search = useSignal(controller.search)
  const sort = useSignal(controller.sort)
  const page = useSignal(controller.page)
  const pageCount = useSignal(controller.pageCount)
  const totalCount = useSignal(controller.totalCount)
  const pendingCount = useSignal(controller.pendingCount)
  const loaded = useSignal(controller.loaded)
  const paginated = pageCount > 1

  function sortIcon(key: string): string {
    if (sort?.field !== key) {
      return 'bi-arrow-down-up text-muted'
    }

    return sort.direction === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down'
  }

  // A sortable header reports its current sort state to assistive tech through
  // aria-sort: a sortable-but-unsorted column reports 'none', the active column
  // its direction, and a non-sortable column nothing at all.
  function ariaSort(
    column: HilosTableColumn,
  ): 'ascending' | 'descending' | 'none' | undefined {
    if (!column.sortable) {
      return undefined
    }
    if (sort?.field !== column.key) {
      return 'none'
    }

    return sort.direction === 'asc' ? 'ascending' : 'descending'
  }

  return (
    <div data-id="hilos-viewport-table">
      {searchable || pendingCount > 0 ? (
        <div className="d-flex justify-content-between align-items-center gap-2 mb-3">
          {searchable ? (
            <input
              type="search"
              className="form-control"
              placeholder={searchPlaceholder}
              aria-label={searchPlaceholder}
              value={search}
              data-id="hilos-table-search"
              onChange={(event) => controller.setSearch(event.target.value)}
            />
          ) : null}
          {pendingCount > 0 ? (
            <button
              type="button"
              className="btn btn-primary btn-sm text-nowrap d-inline-flex align-items-center gap-2 ms-auto"
              aria-label={`Apply ${pendingCount} pending changes`}
              data-id="hilos-table-apply"
              onClick={() => controller.apply()}
            >
              Apply changes
              <span
                className="badge text-bg-light"
                data-id="hilos-table-pending"
                aria-hidden="true"
              >
                {pendingCount}
              </span>
            </button>
          ) : null}
        </div>
      ) : null}

      <div className="table-responsive">
        <table className="table table-striped table-hover align-middle mb-0">
          {label ? (
            <caption className="visually-hidden">{label}</caption>
          ) : null}
          <thead>
            <tr>
              {columns.map((column) => (
                <th
                  key={column.key}
                  scope="col"
                  className={column.headerClass}
                  aria-sort={ariaSort(column)}
                >
                  {column.sortable ? (
                    <button
                      type="button"
                      className="btn btn-link p-0 text-reset text-decoration-none d-inline-flex align-items-center gap-1"
                      data-id={`hilos-table-sort-${column.key}`}
                      onClick={() => controller.setSort(column.key)}
                    >
                      {column.label}
                      <i
                        className={`bi ${sortIcon(column.key)}`}
                        aria-hidden="true"
                      />
                    </button>
                  ) : (
                    column.label
                  )}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((view) => (
              <tr
                key={view.rowKey}
                data-id={`hilos-table-row-${view.rowKey}`}
                className={
                  view.pending ? PENDING_ROW_CLASS[view.pending] : undefined
                }
              >
                {/* A placeholder row carries a null row; the null check also
                    narrows the type for the render prop. */}
                {view.placeholder || view.row === null ? (
                  <td
                    colSpan={columns.length}
                    className="text-center text-muted fst-italic"
                    data-id="hilos-table-placeholder"
                  >
                    {placeholderText}
                  </td>
                ) : (
                  row(view.row, view.rowKey)
                )}
              </tr>
            ))}
            {rows.length === 0 ? (
              <tr>
                <td
                  colSpan={columns.length}
                  className="text-center text-muted py-4"
                >
                  {!loaded ? (
                    <span
                      className="d-inline-flex align-items-center gap-2"
                      role="status"
                      data-id="hilos-table-loading"
                    >
                      <span
                        className="spinner-border spinner-border-sm"
                        aria-hidden="true"
                      />
                      {loadingText}
                    </span>
                  ) : (
                    (empty ?? emptyText)
                  )}
                </td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </div>

      {paginated ? (
        <div className="d-flex justify-content-between align-items-center mt-3">
          <span className="text-muted small" data-id="hilos-table-count">
            {totalCount} total
          </span>
          <div className="btn-group" role="group" aria-label="Pagination">
            <button
              type="button"
              className="btn btn-outline-secondary btn-sm"
              disabled={page === 0}
              data-id="hilos-table-prev"
              onClick={() => controller.setPage(page - 1)}
            >
              Previous
            </button>
            <span
              className="btn btn-sm disabled"
              aria-label={`Page ${page + 1} of ${pageCount}`}
              data-id="hilos-table-page"
            >
              {page + 1} / {pageCount}
            </span>
            <button
              type="button"
              className="btn btn-outline-secondary btn-sm"
              disabled={page >= pageCount - 1}
              data-id="hilos-table-next"
              onClick={() => controller.setPage(page + 1)}
            >
              Next
            </button>
          </div>
        </div>
      ) : null}
    </div>
  )
}
