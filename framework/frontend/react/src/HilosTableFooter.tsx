// HilosTableFooter — the strip below a table: which rows of the set are on screen,
// how many there are, and the pager. It computes NOTHING about the set — the core
// hands over the numbers and the view builds the sentence in its own language
// (tableFrame.ts, HilosTableFooter); the one thing it works out is which page
// numbers fit in the row, which is a question about the width of a strip and
// belongs to nobody else. Page numbers stand there exactly while the count is
// exact: a page is a place counted from an end of the set, and a count stopped at
// its ceiling has no such end (mockups/components/table section 8). On an empty
// window it draws nothing at all: "0 of 0" says nothing, and the body of the table
// is what speaks about emptiness. Internal to the React view layer on purpose: it
// is not exported from index.ts, for the reason the bar is not. The React port of
// the Vue reference (vue/src/HilosTableFooter.vue), under the same names and words.
import type { TableViewportController } from '@hilos/core'

import { useSignal } from './useSignal.js'

/**
 * One slot of the pager: a page to go to, or the pages passed over between two
 * of them.
 */
type PagerSlot = { kind: 'page'; number: number } | { kind: 'gap' }

/**
 * How many slots the pager holds — five numbers and the two stretches passed
 * over between them. A set that fits in that many pages is drawn whole instead:
 * skipping over pages there would save no room and only hide where the reader
 * can go.
 */
const PAGER_SLOTS = 7

/**
 * The pager's slots: the first page, the last one, and the current page with its
 * two neighbors — the places a reader can reach in one step. Two held numbers
 * with a single page between them keep that page rather than an ellipsis
 * standing in for it, which would take the same room and offer less.
 *
 * @param page The zero-based page the window stands on.
 * @param pageCount How many pages the set holds, or null while the count is not
 *   exact.
 */
function pagerSlots(
  page: number,
  pageCount: number | null,
): readonly PagerSlot[] {
  if (pageCount === null) {
    return []
  }
  if (pageCount <= PAGER_SLOTS) {
    return Array.from({ length: pageCount }, (_unused, index) => ({
      kind: 'page',
      number: index + 1,
    }))
  }

  const current = page + 1
  const held = [...new Set([1, current - 1, current, current + 1, pageCount])]
    .filter((number) => number >= 1 && number <= pageCount)
    .sort((one, other) => one - other)

  return held.flatMap<PagerSlot>((number, index) => {
    const previous = held[index - 1]
    if (previous === undefined || number - previous === 1) {
      return [{ kind: 'page', number }]
    }

    return [
      number - previous === 2
        ? { kind: 'page', number: number - 1 }
        : { kind: 'gap' },
      { kind: 'page', number },
    ]
  })
}

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

  const pages = pagerSlots(footer.page, footer.pageCount)

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
          aria-label={pages.length > 0 ? 'Previous page' : undefined}
          data-id="hilos-table-prev"
          onClick={() => controller.prevPage()}
        >
          <i
            className={`bi bi-chevron-left${pages.length === 0 ? ' me-1' : ''}`}
            aria-hidden="true"
          />
          {pages.length === 0 ? 'Previous' : null}
        </button>
        {pages.map((slot, index) =>
          slot.kind === 'gap' ? (
            <span
              key={`gap-${index}`}
              className="btn btn-outline-secondary disabled"
              aria-hidden="true"
            >
              …
            </span>
          ) : slot.number !== footer.page + 1 ? (
            <button
              key={`page-${slot.number}`}
              type="button"
              className="btn btn-outline-secondary"
              aria-label={`Page ${slot.number}`}
              data-id={`hilos-table-page-${slot.number}`}
              onClick={() => controller.setPage(slot.number - 1)}
            >
              {slot.number}
            </button>
          ) : (
            <button
              key={`page-${slot.number}`}
              type="button"
              className="btn btn-outline-secondary active"
              aria-current="page"
              disabled
              data-id={`hilos-table-page-${slot.number}`}
            >
              {slot.number}
            </button>
          ),
        )}
        <button
          type="button"
          className="btn btn-outline-secondary"
          disabled={!footer.hasNextPage}
          aria-label={pages.length > 0 ? 'Next page' : undefined}
          data-id="hilos-table-next"
          onClick={() => controller.nextPage()}
        >
          {pages.length === 0 ? 'Next' : null}
          <i
            className={`bi bi-chevron-right${pages.length === 0 ? ' ms-1' : ''}`}
            aria-hidden="true"
          />
        </button>
      </div>
    </div>
  )
}
