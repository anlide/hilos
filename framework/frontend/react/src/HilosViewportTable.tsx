// HilosViewportTable — the thin React view over the SERVER-WINDOWED
// TableViewportController. Search, sort, and paging change the viewport
// descriptor and are sent to the backend (NO local filtering); live changes
// arrive as pending and are resolved with the Apply button. A removed row
// renders as a placeholder in its slot — the layout never collapses. It holds
// NO table logic (multiframework-core.md): the controller owns the descriptor,
// pending, and Apply. Body cells come from the `row` render prop; the
// placeholder, header, paging, and the pending bar stay framework-owned.
// (Distinct from HilosTable, the client-side view.) A table whose page DECLARED a
// frame draws the bar and the footer from that declaration instead
// (HilosTableBar, HilosTableFooter), and takes its columns, its name, and the words
// it says when empty from there too; a table whose page declared nothing is drawn
// from its props, the older of the two epochs, which the framework's log pages
// still take. Bootstrap classes only.
import { useContext, useId } from 'react'
import type { ReactNode } from 'react'
import type {
  HilosTableColumn,
  TableSort,
  TableViewportController,
} from '@hilos/core'

import { HilosTableBar } from './HilosTableBar.js'
import { HilosTableFooter } from './HilosTableFooter.js'
import { HilosPageHeadingIdContext } from './hilosPageHeadingContext.js'
import { useSignal } from './useSignal.js'

/** Props for {@link HilosViewportTable}. */
export interface HilosViewportTableProps<R> {
  /** The headless server-windowed controller driving rows, descriptor, and pending. */
  controller: TableViewportController<R>
  /**
   * Column declarations for the header (labels and sort controls) of a table whose
   * page declared no frame; a declared table takes its columns from the declaration
   * and is not handed these.
   */
  columns?: HilosTableColumn[]
  /** Render the cells of one row; the returned `<td>`s fill the row. */
  row: (row: R, rowKey: string) => ReactNode
  /** Accessible name for the table, rendered as a visually-hidden caption. */
  label?: string
  /** Show the search box above the table. */
  searchable?: boolean
  /** Placeholder for the search box. */
  searchPlaceholder?: string
  /** Message shown when there are no rows and the page declared no empty state. */
  emptyText?: string
  /** Message shown while the first window is still loading. */
  loadingText?: string
  /** Replace the empty-state cell content. */
  empty?: ReactNode
  /** Label shown in a removed row's placeholder slot. */
  placeholderText?: string
  /**
   * The `data-id` the table's root carries, for a page that draws more than one of them:
   * the default is the shared handle, and a second table on the same page names itself so
   * the two can be told apart from outside.
   */
  dataId?: string
}

// A row with an unapplied pending change gets a subtle, theme-aware tint that
// stands out from the zebra striping: amber for a waiting move, red for a
// waiting removal. Bootstrap's contextual row classes carry their own dark-mode
// variants, so they adapt to the active theme with no custom style layer.
const PENDING_ROW_CLASS: Record<'move' | 'remove', string> = {
  move: 'table-warning',
  remove: 'table-danger',
}

/**
 * The framework-owned table chrome over a headless {@link TableViewportController}.
 *
 * @param props The controller, columns, row renderer, and label / search / empty / placeholder config.
 */
export function HilosViewportTable<R>({
  controller,
  columns = [],
  row,
  label,
  searchable = false,
  searchPlaceholder = 'Search…',
  emptyText = 'No rows.',
  loadingText = 'Loading…',
  empty,
  placeholderText = 'Removed',
  dataId = 'hilos-viewport-table',
}: HilosViewportTableProps<R>) {
  const rows = useSignal(controller.rows)
  const search = useSignal(controller.search)
  const order = useSignal(controller.order)
  const page = useSignal(controller.page)
  const pageCount = useSignal(controller.pageCount)
  const totalCount = useSignal(controller.totalCount)
  const totalExact = useSignal(controller.totalExact)
  const hasNextPage = useSignal(controller.hasNextPage)
  const pendingCount = useSignal(controller.pendingCount)
  const loaded = useSignal(controller.loaded)
  // The declaration does not change over the life of a table, so it is read once
  // rather than wrapped in a signal (tableFrame.ts, HilosTableFrameState).
  const declaration = controller.frame.declaration
  // The columns the table is drawn from: the declaration's own where there is one,
  // and the prop for a table whose page declared nothing. The header and every cell
  // spanning the whole row count this one list, so they cannot disagree on its width.
  const frameColumns: readonly HilosTableColumn[] =
    declaration?.columns ?? columns
  const titleId = useId()
  // What names a declared table: its own title when it declared one, and otherwise
  // the heading of the page it stands on, which already names it. Undefined for a
  // table that declared neither and stands outside an admin page — it has no name to
  // take.
  const pageHeadingId = useContext(HilosPageHeadingIdContext)
  const nameId = declaration?.title ? titleId : pageHeadingId
  // What a declared table says when it has no rows is the headline its page declared.
  // Only the headline: the hint under it, the main action beside it, and the
  // framework's own "Nothing found" under a search belong to an empty state this view
  // does not draw yet.
  const emptyWords = declaration?.empty?.title ?? emptyText
  // A table whose count stopped at its ceiling has no page count to compare against, and
  // the footer is what such a table still needs: it is the only place saying there is more.
  const paginated = pageCount === null || pageCount > 1
  // The total reads as "at least this many" when the count stopped at its ceiling, which is
  // what the trailing plus says.
  const countLabel = totalExact ? `${totalCount} total` : `${totalCount}+ total`

  // The arrow a header carries: every column the order runs by gets one, because
  // an order of two columns is sorted by both of them and a single arrow would
  // name one of the two as the whole answer.
  function sortComponent(key: string): TableSort | undefined {
    return order?.find((component) => component.field === key)
  }

  function sortIcon(key: string): string {
    const component = sortComponent(key)
    if (component === undefined) {
      return 'bi-arrow-down-up text-muted'
    }

    return component.direction === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down'
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
    const component = sortComponent(column.key)
    if (component === undefined) {
      return 'none'
    }

    return component.direction === 'asc' ? 'ascending' : 'descending'
  }

  return (
    <div data-id={dataId}>
      {declaration ? (
        <HilosTableBar controller={controller} titleId={titleId} />
      ) : null}

      {/* The bar a table draws from its props, for a table whose page declared no
          frame — such as the framework's log pages. Its Apply control stays for a
          declared table too: the room of live messages that carries Apply in Vue is
          not ported yet (HIL-811…818), and without it pending changes would pile up
          with no way to show them. */}
      {(!declaration && searchable) || pendingCount > 0 ? (
        <div className="d-flex justify-content-between align-items-center gap-2 mb-3">
          {!declaration && searchable ? (
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
        <table
          className="table table-striped table-hover align-middle mb-0"
          aria-labelledby={declaration ? nameId : undefined}
        >
          {/* A declared table already shows its name as a heading, and a hidden
              caption repeating it would name the table twice. */}
          {!declaration && label ? (
            <caption className="visually-hidden">{label}</caption>
          ) : null}
          <thead>
            <tr>
              {frameColumns.map((column) => (
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
                    colSpan={frameColumns.length}
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
                  colSpan={frameColumns.length}
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
                    (empty ?? emptyWords)
                  )}
                </td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </div>

      {declaration ? <HilosTableFooter controller={controller} /> : null}

      {/* The footer a table draws from its own comparisons, for the same tables
          as the bar above. */}
      {!declaration && paginated ? (
        <div className="d-flex justify-content-between align-items-center mt-3">
          <span className="text-muted small" data-id="hilos-table-count">
            {countLabel}
          </span>
          <div className="btn-group" role="group" aria-label="Pagination">
            <button
              type="button"
              className="btn btn-outline-secondary btn-sm"
              disabled={page === 0}
              data-id="hilos-table-prev"
              onClick={() => controller.prevPage()}
            >
              Previous
            </button>
            {pageCount === null ? null : (
              <span
                className="btn btn-sm disabled"
                aria-label={`Page ${page + 1} of ${pageCount}`}
                data-id="hilos-table-page"
              >
                {page + 1} / {pageCount}
              </span>
            )}
            <button
              type="button"
              className="btn btn-outline-secondary btn-sm"
              disabled={!hasNextPage}
              data-id="hilos-table-next"
              onClick={() => controller.nextPage()}
            >
              Next
            </button>
          </div>
        </div>
      ) : null}
    </div>
  )
}
