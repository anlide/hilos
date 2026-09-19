// HilosTableEmptyState — the two states the body of a table says in words: the
// set is empty and nothing filters it ("data is not here yet"), or it is empty
// under a search or a filter ("Nothing found"). Which of the two holds is decided
// by the core (tableFrame.ts, HilosTableBody); this view only draws it, and the
// table draws it in both of its branches, wide and narrow. The first state speaks
// the page's own words — the title and hint of its declared empty state, and its
// main action, the same button the bar offers — while the second is the
// framework's and names what was searched for, because resetting is only an offer
// when the reader can see what will be reset (mockups/components/table section
// 10). Internal to the React view layer on purpose: it is not exported from
// index.ts, for the reason the bar is not. The React port of the Vue reference
// (vue/src/HilosTableEmptyState.vue), under the same names and words.
import type { ReactNode } from 'react'
import type { HilosTableFilterView, TableViewportController } from '@hilos/core'

import { useSignal } from './useSignal.js'

/** Props for {@link HilosTableEmptyState}. */
export interface HilosTableEmptyStateProps<R> {
  /** The headless server-windowed controller the state reads and resets. */
  controller: TableViewportController<R>
  /** Which of the two worded states of the body to draw. */
  kind: 'empty' | 'empty_filtered'
  /** The page's own words, standing when it declared no empty state. */
  children?: ReactNode
}

/**
 * How one active filter reads in the sentence — the forms the control in the bar
 * already reads in (HilosTableFilterControl.tsx): a dropdown names its option, a
 * date range leaves out a bound that is not there, a toggle is its own label. The
 * options are read at drawing time, so an option gone from the list is named by
 * its value.
 *
 * @param view The active filter to name.
 */
function filterTerm(view: HilosTableFilterView): string {
  const filter = view.filter
  if (filter.kind === 'select') {
    const option = filter
      .options()
      .find((declared) => declared.value === view.value)

    return `${filter.label}: ${option === undefined ? String(view.value) : option.label}`
  }
  if (filter.kind === 'date_range') {
    const { from, to } = view.value as { from?: unknown; to?: unknown }
    if (from !== undefined && to !== undefined) {
      return `${filter.label}: ${String(from)} – ${String(to)}`
    }
    if (from !== undefined) {
      return `${filter.label}: from ${String(from)}`
    }
    if (to !== undefined) {
      return `${filter.label}: until ${String(to)}`
    }
  }

  return filter.label
}

/**
 * One worded state of the body of a table.
 *
 * @param props The controller, the state to draw, and the page's own words.
 */
export function HilosTableEmptyState<R>({
  controller,
  kind,
  children,
}: HilosTableEmptyStateProps<R>) {
  // What the page declared about the frame, or null when it declared nothing. It
  // does not change over the life of a table, so it is read without a signal.
  const declaration = controller.frame.declaration

  const search = useSignal(controller.search)
  const filters = useSignal(controller.frame.filters)

  if (kind === 'empty') {
    const empty = declaration?.empty
    const mainAction = declaration?.mainAction

    return (
      <div
        className="text-center py-4"
        role="status"
        data-id="hilos-table-empty"
      >
        {empty ? (
          <>
            <i
              className="bi bi-inbox fs-1 text-body-secondary mb-2 d-block"
              aria-hidden="true"
            />
            <div
              className={`fw-semibold small ${empty.hint ? 'mb-1' : 'mb-3'}`}
              data-id="hilos-table-empty-title"
            >
              {empty.title}
            </div>
            {empty.hint ? (
              <p
                className="small text-body-secondary mb-3"
                data-id="hilos-table-empty-hint"
              >
                {empty.hint}
              </p>
            ) : null}
          </>
        ) : (
          // A table with no declared empty state still says what its page passed
          // in the empty or the emptyText prop: the framework's log pages hand
          // their words over that way, and no leaf moves them onto the
          // declaration yet.
          <div className={`text-muted${mainAction ? ' mb-3' : ''}`}>
            {children}
          </div>
        )}
        {mainAction ? (
          <button
            type="button"
            className="btn btn-sm btn-primary"
            data-id="hilos-table-empty-action"
            onClick={() => mainAction.press()}
          >
            {mainAction.label}
          </button>
        ) : null}
      </div>
    )
  }

  // The query and the filters are named together: the reset below takes both off
  // with one press, and a sentence promising less than the button does would be
  // the wrong thing to read before pressing it.
  const parts: string[] = []
  if (search !== '') {
    parts.push(`“${search}”`)
  }
  for (const view of filters) {
    if (view.active) {
      parts.push(filterTerm(view))
    }
  }

  return (
    <div
      className="text-center py-4"
      role="status"
      data-id="hilos-table-no-matches"
    >
      <i
        className="bi bi-search fs-1 text-body-secondary mb-2 d-block"
        aria-hidden="true"
      />
      <div className="fw-semibold small mb-1">Nothing found</div>
      <p
        className="small text-body-secondary mb-3 text-break"
        data-id="hilos-table-no-matches-terms"
      >
        {`No rows match ${parts.join(' · ')}`}
      </p>
      <button
        type="button"
        className="btn btn-sm btn-outline-secondary"
        data-id="hilos-table-no-matches-reset"
        onClick={() => controller.resetFilters()}
      >
        Reset filters
      </button>
    </div>
  )
}
