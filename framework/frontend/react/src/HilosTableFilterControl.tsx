// HilosTableFilterControl — one declared filter of a table's bar, in whichever of
// the three shapes the page declared it: a dropdown, a date range, a toggle. The
// set of shapes is closed (tableFrame.ts, HilosTableFilter), so each of them has
// exactly one way of being drawn and the branch on `kind` lives in this one place.
// Every change is a call into the controller — a filter is never applied on the
// client — and a date range sends BOTH of its bounds in one window change, because
// two calls would show a window filtered by a start with no end. Internal to the
// React view layer on purpose: it is not exported from index.ts, for the reason
// the bar is not. The React port of the Vue reference
// (vue/src/HilosTableFilterControl.vue), under the same names and words.
import { useEffect, useId, useRef, useState } from 'react'
import type {
  HilosTableFacetCount,
  HilosTableFilterView,
  TableViewportController,
} from '@hilos/core'

import { HilosDropdown } from './HilosDropdown.js'
import type { HilosDropdownOption } from './hilosDropdown.js'

/** Props for {@link HilosTableFilterControl}. */
export interface HilosTableFilterControlProps<R> {
  /** The declared filter together with the value it currently holds. */
  view: HilosTableFilterView
  /** The headless server-windowed controller every change is written into. */
  controller: TableViewportController<R>
  /**
   * Where this copy of the control is drawn. The bar and the filters modal both
   * hold one at the same time, so the copy in the modal answers to its own names;
   * there is no default, because one would quietly give the two copies the same
   * name again.
   */
  placement: 'bar' | 'modal'
}

// The value of an option is declared `unknown`, and the dropdown is typed
// `string | number` — so what travels through the primitive is the option's
// PLACE in the declared list, and the declared value itself is read back out of
// that list. Casting the value to a string would lose its type on the way back.
const NO_CHOICE = -1

/**
 * One declared filter, drawn in the shape its declaration names.
 *
 * @param props The filter view, the controller it writes into, and where it is drawn.
 */
export function HilosTableFilterControl<R>({
  view,
  controller,
  placement,
}: HilosTableFilterControlProps<R>) {
  const filter = view.filter

  const [rangeOpen, setRangeOpen] = useState(false)
  const rangeRoot = useRef<HTMLDivElement>(null)
  const fromId = useId()
  const toId = useId()
  const toggleId = useId()

  // The SDK ships Bootstrap's CSS and not its JS, so opening and closing the panel
  // is owned here, as it is in HilosDropdown.
  useEffect(() => {
    const onDocumentClick = (event: MouseEvent): void => {
      if (
        rangeRoot.current &&
        !rangeRoot.current.contains(event.target as Node)
      ) {
        setRangeOpen(false)
      }
    }
    document.addEventListener('click', onDocumentClick)

    return () => document.removeEventListener('click', onDocumentClick)
  }, [])

  // Every handle of the modal's copy carries this at the end of the control's own
  // name, so each name in the document stays one element.
  const placementSuffix = placement === 'modal' ? '-modal' : ''

  // A date range is one control over two keys, and the lower one names it: every
  // handle of this filter has to be found by one word from outside.
  const filterKey = filter.kind === 'date_range' ? filter.fromKey : filter.key
  const dataId = `hilos-table-filter-${filterKey}${placementSuffix}`

  if (filter.kind === 'select') {
    // Read on every render rather than kept in state: options that arrive in the
    // page scope later — the channels of a delivery log — redraw the control on
    // their own, and a copy is exactly what would freeze them.
    const declaredOptions = filter.options()
    const dropdownOptions: HilosDropdownOption<number>[] = [
      { value: NO_CHOICE, label: filter.anyLabel ?? 'Any' },
      ...declaredOptions.map((option, index) => ({
        value: index,
        label: option.label,
      })),
    ]
    const selectedIndex = view.active
      ? declaredOptions.findIndex((option) => option.value === view.value)
      : NO_CHOICE
    const facets = view.facets

    const onSelect = (index: number): void => {
      controller.setFilter(
        filter.key,
        index === NO_CHOICE ? undefined : declaredOptions[index]?.value,
      )
    }

    // The number beside an option is read out of the counts by the option's place,
    // the same place the primitive carries: "no choice" answers with the set the
    // filter lifted, a declared option with its own count, found under the text of
    // its value — which is how the server keys it.
    const facetCount = (index: number): HilosTableFacetCount | undefined => {
      if (facets === null) {
        return undefined
      }
      if (index === NO_CHOICE) {
        return facets.any
      }
      const option = declaredOptions[index]

      return option === undefined
        ? undefined
        : facets.options.get(String(option.value))
    }

    // Three forms and no more: the number, "500+" where the count stopped at its
    // ceiling, and 0 — which is written, because "this leaves nothing" is the
    // point. Null where there is no count to write, and nothing is drawn there.
    const facetText = (index: number): string | null => {
      const count = facetCount(index)
      if (count === undefined) {
        return null
      }

      return count.exact ? String(count.count) : `${count.count}+`
    }

    const facetDataId = (index: number): string => {
      const option = declaredOptions[index]
      const value =
        index === NO_CHOICE || option === undefined
          ? 'any'
          : String(option.value)

      return `hilos-table-facet-${filterKey}-${value}${placementSuffix}`
    }

    return (
      <div data-id={dataId}>
        <HilosDropdown
          value={selectedIndex}
          options={dropdownOptions}
          menuAriaLabel={filter.label}
          onChange={onSelect}
          toggle={({ label }) => (
            <span className="text-truncate">
              {filter.label}: {label}
            </span>
          )}
          // Only once counts have arrived: until then, and for a table that does
          // not count, the list is the one the primitive draws, not one with
          // blanks in it. The item keeps the primitive's own shape, and the number
          // stands at the right, muted on every item but the picked one, where it
          // would not read.
          option={
            facets === null
              ? undefined
              : ({ option, selected, select }) => {
                  const text = facetText(option.value)

                  return (
                    <button
                      type="button"
                      className={`dropdown-item d-flex align-items-center justify-content-between gap-2${
                        selected ? ' active' : ''
                      }`}
                      disabled={option.disabled}
                      role="option"
                      aria-selected={selected}
                      data-id={`hilos-dropdown-option-${option.value}`}
                      onClick={select}
                    >
                      <span className="text-truncate">{option.label}</span>
                      {text === null ? null : (
                        <span
                          className={`ms-auto small flex-shrink-0${
                            selected ? '' : ' text-body-secondary'
                          }`}
                          data-id={facetDataId(option.value)}
                        >
                          {text}
                        </span>
                      )}
                    </button>
                  )
                }
          }
        />
      </div>
    )
  }

  if (filter.kind === 'date_range') {
    const value = view.value as { from?: unknown; to?: unknown } | null
    const from =
      value === null || value === undefined || value.from === undefined
        ? ''
        : String(value.from)
    const to =
      value === null || value === undefined || value.to === undefined
        ? ''
        : String(value.to)

    // Four forms, one for each way a range can be half-open — a bound that is not
    // there is left out of the sentence rather than shown as an empty side.
    let rangeLabel = filter.label
    if (from !== '' && to !== '') {
      rangeLabel = `${filter.label}: ${from} – ${to}`
    } else if (from !== '') {
      rangeLabel = `${filter.label}: from ${from}`
    } else if (to !== '') {
      rangeLabel = `${filter.label}: until ${to}`
    }

    const setBounds = (nextFrom: string, nextTo: string): void => {
      controller.setFilters({
        [filter.fromKey]: nextFrom,
        [filter.toKey]: nextTo,
      })
    }

    return (
      <div ref={rangeRoot} className="dropdown">
        <button
          type="button"
          className="btn btn-sm btn-outline-secondary"
          aria-expanded={rangeOpen}
          data-id={dataId}
          onClick={() => setRangeOpen(!rangeOpen)}
          onKeyDown={(event) => {
            if (event.key === 'Escape') {
              event.preventDefault()
              setRangeOpen(false)
            }
          }}
        >
          {rangeLabel}
        </button>
        <div className={`dropdown-menu show p-3${rangeOpen ? '' : ' d-none'}`}>
          <label htmlFor={fromId} className="form-label small mb-1">
            From
          </label>
          <input
            id={fromId}
            type="date"
            className="form-control form-control-sm mb-2"
            value={from}
            data-id={`${dataId}-from`}
            onChange={(event) => setBounds(event.target.value, to)}
          />
          <label htmlFor={toId} className="form-label small mb-1">
            To
          </label>
          <input
            id={toId}
            type="date"
            className="form-control form-control-sm mb-2"
            value={to}
            data-id={`${dataId}-to`}
            onChange={(event) => setBounds(from, event.target.value)}
          />
          <button
            type="button"
            className="btn btn-sm btn-outline-secondary w-100"
            data-id={`${dataId}-clear`}
            onClick={() => setBounds('', '')}
          >
            Clear
          </button>
        </div>
      </div>
    )
  }

  return (
    <div className="form-check form-switch mb-0">
      <input
        id={toggleId}
        className="form-check-input"
        type="checkbox"
        checked={view.active}
        data-id={dataId}
        onChange={(event) =>
          controller.setFilter(
            filter.key,
            event.target.checked ? filter.on : undefined,
          )
        }
      />
      <label htmlFor={toggleId} className="form-check-label">
        {filter.label}
      </label>
    </div>
  )
}
