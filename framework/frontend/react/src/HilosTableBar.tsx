// HilosTableBar — the strip above a table, drawn from what the page DECLARED
// (HilosTableFrame) and never from props of its own: the title and subtitle, the
// search box, the declared filters, and the one main action pinned right. It holds
// NO table logic — the controller owns the descriptor, and every control here is a
// call into it (multiframework-core.md). Internal to the React view layer on
// purpose: it is not exported from index.ts, because a bar has no meaning away from
// the table it sits on (mockups/components/table section 7). The React port of the
// Vue reference (vue/src/HilosTableBar.vue), under the same names and words.
import { useState } from 'react'
import type { HilosTableFilterView, TableViewportController } from '@hilos/core'

import { HilosModal } from './HilosModal.js'
import { HilosTableFilterControl } from './HilosTableFilterControl.js'
import { useSignal } from './useSignal.js'

/** Props for {@link HilosTableBar}. */
export interface HilosTableBarProps<R> {
  /** The headless server-windowed controller the bar reads and drives. */
  controller: TableViewportController<R>
  /**
   * The id the title carries, so the table below can name itself with
   * aria-labelledby: the accessible name of the table is the very heading a
   * sighted person reads, and the id has to be minted where both of them can
   * see it.
   */
  titleId: string
}

// A date range is one control over two keys, and it is listed under the lower
// one — the same word the control answers to from outside.
function filterKey(view: HilosTableFilterView): string {
  return view.filter.kind === 'date_range'
    ? view.filter.fromKey
    : view.filter.key
}

/**
 * The declared strip above a table.
 *
 * @param props The controller and the id the title carries.
 */
export function HilosTableBar<R>({
  controller,
  titleId,
}: HilosTableBarProps<R>) {
  // The declaration does not change over the life of a table, so its parts are
  // read once rather than wrapped in signals (tableFrame.ts, HilosTableFrameState).
  const declaration = controller.frame.declaration
  const title = declaration?.title
  const subtitle = declaration?.subtitle
  const searchBox = declaration?.search
  const mainAction = declaration?.mainAction

  // The search placeholder doubles as the field's accessible name: the field
  // carries no visible label, and two different strings would name it twice.
  const searchPlaceholder = searchBox?.placeholder ?? 'Search…'

  const search = useSignal(controller.search)
  const filters = useSignal(controller.frame.filters)
  const activeFilterCount = useSignal(controller.frame.activeFilterCount)

  // Narrow screens put the filters in a modal rather than an offcanvas of the
  // SDK's own: the SDK ships Bootstrap's CSS and not its JS, so an offcanvas would
  // be a second way of showing a surface over the screen — one every other view
  // layer would then have to repeat.
  const [filtersOpen, setFiltersOpen] = useState(false)

  // The badge counts the declared filters holding a value; the search box is not
  // one of them and has its own field (tableFrame.ts, activeFilterCount).
  const filterCountLabel =
    activeFilterCount === 1 ? '1 filter' : `${activeFilterCount} filters`

  return (
    <div>
      {/* No declared title, no heading: the page heading above names the table
          then, and an empty h2 would be a heading with nothing to say. */}
      {title ? (
        <div className="mb-2">
          <h2 id={titleId} className="h6 mb-0" data-id="hilos-table-title">
            {title}
          </h2>
          {subtitle ? (
            <p
              className="small text-body-secondary mb-0"
              data-id="hilos-table-subtitle"
            >
              {subtitle}
            </p>
          ) : null}
        </div>
      ) : null}

      {searchBox || filters.length > 0 || mainAction ? (
        <div className="d-flex flex-wrap align-items-center gap-2 mb-3">
          {searchBox ? (
            <div className="input-group input-group-sm w-auto flex-grow-1 flex-md-grow-0">
              <span className="input-group-text">
                <i className="bi bi-search" aria-hidden="true" />
              </span>
              <input
                type="search"
                className="form-control"
                placeholder={searchPlaceholder}
                aria-label={searchPlaceholder}
                value={search}
                data-id="hilos-table-search"
                onChange={(event) => controller.setSearch(event.target.value)}
              />
              {search !== '' ? (
                <button
                  type="button"
                  className="btn btn-outline-secondary"
                  aria-label="Clear search"
                  data-id="hilos-table-search-clear"
                  onClick={() => controller.setSearch('')}
                >
                  <i className="bi bi-x-lg" aria-hidden="true" />
                </button>
              ) : null}
            </div>
          ) : null}

          {/* Below md the controls leave the bar for the modal below, and the
              button that opens it takes their place. The search does not go with
              them: it is the main way to narrow a set, and hiding it behind a
              button would hide exactly what the table was opened for. */}
          {filters.length > 0 ? (
            <div className="d-none d-md-flex flex-wrap align-items-center gap-2">
              {filters.map((view) => (
                <HilosTableFilterControl
                  key={filterKey(view)}
                  view={view}
                  controller={controller}
                  placement="bar"
                />
              ))}
            </div>
          ) : null}

          {activeFilterCount > 0 ? (
            <span
              className="badge text-bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle"
              data-id="hilos-table-filter-badge"
            >
              {filterCountLabel}
              <button
                type="button"
                className="btn-close ms-1"
                aria-label="Reset filters"
                data-id="hilos-table-filter-reset"
                onClick={() => controller.resetFilters()}
              />
            </span>
          ) : null}

          {/* The button says only its word: the number of filters holding a
              value is said once, on the badge above, which stays on a narrow
              screen because the reset lives nowhere else. */}
          {filters.length > 0 ? (
            <button
              type="button"
              className="btn btn-sm btn-outline-secondary d-md-none"
              data-id="hilos-table-filters-open"
              onClick={() => setFiltersOpen(true)}
            >
              Filters
            </button>
          ) : null}

          {mainAction ? (
            <button
              type="button"
              className="btn btn-sm btn-primary ms-auto"
              data-id="hilos-table-main-action"
              onClick={() => mainAction.press()}
            >
              {mainAction.label}
            </button>
          ) : null}
        </div>
      ) : null}

      {/* Under the same condition as the button that opens it: a table with no
          filters has nothing to show here, and a dialog no button can reach
          would be one more owner of the page's scroll lock for nobody. */}
      {filters.length > 0 ? (
        <HilosModal
          open={filtersOpen}
          title="Filters"
          initialFocus="inner"
          onClose={() => setFiltersOpen(false)}
          actions={({ requestClose }) => (
            <button
              type="button"
              className="btn btn-primary"
              data-id="hilos-table-filters-done"
              onClick={() => requestClose()}
            >
              Done
            </button>
          )}
        >
          <div className="d-flex flex-column gap-3">
            {filters.map((view) => (
              <HilosTableFilterControl
                key={filterKey(view)}
                view={view}
                controller={controller}
                placement="modal"
              />
            ))}
          </div>
        </HilosModal>
      ) : null}
    </div>
  )
}
