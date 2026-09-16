// HilosTableFooter — the strip below a table: which rows of the set are on screen,
// how many there are, and the two steps of the pager. It computes NOTHING of its
// own — the core hands over the numbers and the view builds the sentence in its own
// language (tableFrame.ts, HilosTableFooter). On an empty window it draws nothing
// at all: "0 of 0" says nothing, and the body of the table is what speaks about
// emptiness. Internal to the React view layer on purpose: it is not exported from
// index.ts, for the reason the bar is not. The React port of the Vue reference
// (vue/src/HilosTableFooter.vue), under the same names and words.
import type { TableViewportController } from '@hilos/core'

import { useSignal } from './useSignal.js'

/** Props for {@link HilosTableFooter}. */
export interface HilosTableFooterProps<R> {
  /** The headless server-windowed controller the footer reads and pages. */
  controller: TableViewportController<R>
}

/**
 * The declared strip below a table.
 *
 * @param props The controller the footer reads and pages.
 */
export function HilosTableFooter<R>({ controller }: HilosTableFooterProps<R>) {
  const footer = useSignal(controller.frame.footer)

  if (footer.firstRow === 0) {
    return null
  }

  // The total reads as "at least this many" when the count stopped at its ceiling,
  // which is what the trailing plus says.
  const countLabel = `${footer.firstRow} – ${footer.lastRow} of ${footer.totalCount}${
    footer.totalExact ? '' : '+'
  }`

  return (
    <div className="d-flex flex-wrap align-items-center gap-2 mt-3">
      <span className="small text-body-secondary" data-id="hilos-table-count">
        {countLabel}
      </span>
      <div
        className="ms-auto btn-group btn-group-sm"
        role="group"
        aria-label="Pagination"
      >
        <button
          type="button"
          className="btn btn-outline-secondary"
          disabled={!footer.hasPreviousPage}
          data-id="hilos-table-prev"
          onClick={() => controller.prevPage()}
        >
          <i className="bi bi-chevron-left me-1" aria-hidden="true" />
          Previous
        </button>
        <button
          type="button"
          className="btn btn-outline-secondary"
          disabled={!footer.hasNextPage}
          data-id="hilos-table-next"
          onClick={() => controller.nextPage()}
        >
          Next
          <i className="bi bi-chevron-right ms-1" aria-hidden="true" />
        </button>
      </div>
    </div>
  )
}
