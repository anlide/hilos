// HilosTable — the thin React view over the core TableController. It renders the
// header (with per-column sort controls), the search box, the rows, and the
// pagination; it holds NO table logic — search, sort, and paging are the
// controller's (multiframework-core.md). Body cells come from the `row` render
// prop so the consumer keeps full control of each cell (links, badges, actions);
// the header, sorting, and paging stay framework-owned. An empty result renders
// `empty` or a default message. Bootstrap classes only.
import type { ReactNode } from 'react'
import type { HilosTableColumn, TableController } from '@hilos/core'

import { useSignal } from './useSignal.js'

/** Props for {@link HilosTable}. */
export interface HilosTableProps<R> {
  /** The headless controller driving rows, search, sort, and paging. */
  controller: TableController<R>
  /** Column declarations for the header (labels and sort controls). */
  columns: HilosTableColumn[]
  /** Render the cells of one row; the returned `<td>`s fill the row. */
  row: (row: R, rowKey: string) => ReactNode
  /** Show the search box above the table. */
  searchable?: boolean
  /** Placeholder for the search box. */
  searchPlaceholder?: string
  /** Message shown when there are no rows. */
  emptyText?: string
  /** Replace the empty-state cell content. */
  empty?: ReactNode
}

/**
 * The framework-owned table chrome over a headless {@link TableController}.
 *
 * @param props The controller, columns, row renderer, and search / empty config.
 */
export function HilosTable<R>({
  controller,
  columns,
  row,
  searchable = false,
  searchPlaceholder = 'Search…',
  emptyText = 'No rows.',
  empty,
}: HilosTableProps<R>) {
  const rows = useSignal(controller.pageRows)
  const search = useSignal(controller.search)
  const sort = useSignal(controller.sort)
  const page = useSignal(controller.page)
  const pageCount = useSignal(controller.pageCount)
  const totalCount = useSignal(controller.totalCount)
  const pageSize = useSignal(controller.pageSize)
  const paginated = pageSize > 0 && pageCount > 1

  function sortIcon(key: string): string {
    if (sort?.field !== key) {
      return 'bi-arrow-down-up text-muted'
    }

    return sort.direction === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down'
  }

  return (
    <div data-id="hilos-table">
      {searchable ? (
        <div className="mb-3">
          <input
            type="search"
            className="form-control"
            placeholder={searchPlaceholder}
            value={search}
            data-id="hilos-table-search"
            onChange={(event) => controller.setSearch(event.target.value)}
          />
        </div>
      ) : null}

      <div className="table-responsive">
        <table className="table table-hover align-middle mb-0">
          <thead>
            <tr>
              {columns.map((column) => (
                <th key={column.key} scope="col" className={column.headerClass}>
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
              <tr key={view.rowKey} data-id={`hilos-table-row-${view.rowKey}`}>
                {row(view.row, view.rowKey)}
              </tr>
            ))}
            {rows.length === 0 ? (
              <tr>
                <td
                  colSpan={columns.length}
                  className="text-center text-muted py-4"
                >
                  {empty ?? emptyText}
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
            <span className="btn btn-sm disabled" data-id="hilos-table-page">
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
