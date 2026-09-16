import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, fireEvent, render } from '@testing-library/react'
import { TableViewportController } from '@hilos/core'
import type {
  HilosTableFilter,
  HilosTableFilterView,
  TableViewportDescriptor,
} from '@hilos/core'

import { HilosTableFilterControl } from '../src/HilosTableFilterControl.js'

// The React port of vue/src/HilosTableFilterControl.test.ts, under the same case
// names: the three views of one control answer to one list of behaviors.

const COLUMNS = [{ key: 'name', label: 'Name' }]

afterEach(() => cleanup())

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function allById(prefix: string): HTMLElement[] {
  return Array.from(document.querySelectorAll(`[data-id^="${prefix}"]`))
}

// The control renders one filter view and writes into the controller, so the
// tests build a real controller over the same declaration and read back the
// filter map of the window it sends.
function makeController(
  filter: HilosTableFilter,
  initialFilter?: Record<string, unknown>,
): {
  controller: TableViewportController<unknown>
  sent: TableViewportDescriptor[]
  view: () => HilosTableFilterView
} {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<unknown>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: (descriptor) => sent.push(descriptor),
    initialFilter,
    frame: { title: 'Deliveries', filters: [filter], columns: COLUMNS },
  })

  return {
    controller,
    sent,
    view: () => controller.frame.filters.get()[0] as HilosTableFilterView,
  }
}

function renderControl(
  controller: TableViewportController<unknown>,
  view: HilosTableFilterView,
  placement: 'bar' | 'modal' = 'bar',
) {
  return render(
    <HilosTableFilterControl
      view={view}
      controller={controller}
      placement={placement}
    />,
  )
}

const KIND_FILTER: HilosTableFilter = {
  kind: 'select',
  key: 'kind',
  label: 'Kind',
  options: () => [
    { value: 'full', label: 'Full' },
    { value: 2, label: 'Partial' },
  ],
}

const KIND_FILTER_NAMED_ANY: HilosTableFilter = {
  kind: 'select',
  key: 'kind',
  label: 'Kind',
  anyLabel: 'All kinds',
  options: () => [{ value: 'full', label: 'Full' }],
}

const PERIOD_FILTER: HilosTableFilter = {
  kind: 'date_range',
  fromKey: 'from',
  toKey: 'to',
  label: 'Period',
}

const FAILED_FILTER: HilosTableFilter = {
  kind: 'toggle',
  key: 'state',
  label: 'Failed only',
  on: 'failed',
}

describe('HilosTableFilterControl, select', () => {
  it('offers the declared options under a "no choice" one', () => {
    const { controller, view } = makeController(KIND_FILTER)
    renderControl(controller, view())

    expect(
      allById('hilos-dropdown-option-').map((option) => option.textContent),
    ).toEqual(['Any', 'Full', 'Partial'])
  })

  it('names the "no choice" option as the filter declared it', () => {
    const { controller, view } = makeController(KIND_FILTER_NAMED_ANY)
    renderControl(controller, view())

    expect(byId('hilos-dropdown-option--1')?.textContent).toBe('All kinds')
  })

  it('sends the DECLARED value of the option picked, keeping its type', () => {
    const { controller, sent, view } = makeController(KIND_FILTER)
    renderControl(controller, view())

    fireEvent.click(byId('hilos-dropdown-option-1') as HTMLElement)

    expect(sent).toHaveLength(1)
    expect(sent.at(-1)?.filter).toEqual({ kind: 2 })
  })

  it('drops the key when the "no choice" option is picked', () => {
    const { controller, sent, view } = makeController(KIND_FILTER)
    const first = renderControl(controller, view())
    fireEvent.click(byId('hilos-dropdown-option-0') as HTMLElement)
    first.unmount()

    renderControl(controller, view())
    fireEvent.click(byId('hilos-dropdown-option--1') as HTMLElement)

    expect(sent.at(-1)?.filter).not.toHaveProperty('kind')
  })

  it('reads the picked option back as "<label>: <option>"', () => {
    const { controller, view } = makeController(KIND_FILTER, { kind: 'full' })
    renderControl(controller, view())

    expect(byId('hilos-dropdown-toggle')?.textContent).toBe('Kind: Full')
  })

  it('reads as the "no choice" option while the filter holds no value', () => {
    const { controller, view } = makeController(KIND_FILTER)
    renderControl(controller, view())

    expect(byId('hilos-dropdown-toggle')?.textContent).toBe('Kind: Any')
  })
})

describe('HilosTableFilterControl, counts', () => {
  const COUNTS = {
    kind: {
      any: { count: 500, exact: false },
      options: {
        full: { count: 412, exact: true },
        '2': { count: 0, exact: true },
      },
    },
  }

  it('draws the list as it always was while no counts have arrived', () => {
    const { controller, view } = makeController(KIND_FILTER)
    renderControl(controller, view())

    expect(allById('hilos-table-facet-')).toHaveLength(0)
    expect(
      allById('hilos-dropdown-option-').map((option) => option.textContent),
    ).toEqual(['Any', 'Full', 'Partial'])
  })

  it('writes each option its number, the ceiling as "500+" and an empty one as 0', () => {
    const { controller, view } = makeController(KIND_FILTER)
    controller.ingestFacetCounts(COUNTS)
    renderControl(controller, view())

    expect(byId('hilos-table-facet-kind-any')?.textContent).toBe('500+')
    expect(byId('hilos-table-facet-kind-full')?.textContent).toBe('412')
    expect(byId('hilos-table-facet-kind-2')?.textContent).toBe('0')
    expect(
      document.querySelector(
        '[data-id="hilos-dropdown-option-0"] .text-truncate',
      )?.textContent,
    ).toBe('Full')
  })

  it('still sends the declared value of the option picked', () => {
    const { controller, sent, view } = makeController(KIND_FILTER)
    controller.ingestFacetCounts(COUNTS)
    renderControl(controller, view())

    fireEvent.click(byId('hilos-dropdown-option-1') as HTMLElement)

    expect(sent.at(-1)?.filter).toEqual({ kind: 2 })
  })

  it('mutes the number of every option but the one picked', () => {
    const { controller, view } = makeController(KIND_FILTER, { kind: 'full' })
    controller.ingestFacetCounts(COUNTS)
    renderControl(controller, view())

    expect(byId('hilos-table-facet-kind-full')?.classList).not.toContain(
      'text-body-secondary',
    )
    expect(byId('hilos-table-facet-kind-any')?.classList).toContain(
      'text-body-secondary',
    )
  })
})

describe('HilosTableFilterControl, date range', () => {
  it('sends BOTH bounds in one window change when only one is touched', () => {
    const { controller, sent, view } = makeController(PERIOD_FILTER, {
      to: '2026-08-31',
    })
    renderControl(controller, view())

    fireEvent.change(byId('hilos-table-filter-from-from') as HTMLElement, {
      target: { value: '2026-08-01' },
    })

    expect(sent).toHaveLength(1)
    expect(sent.at(-1)?.filter).toEqual({
      from: '2026-08-01',
      to: '2026-08-31',
    })
  })

  it('clears both bounds with one window change', () => {
    const { controller, sent, view } = makeController(PERIOD_FILTER, {
      from: '2026-08-01',
      to: '2026-08-31',
    })
    renderControl(controller, view())

    fireEvent.click(byId('hilos-table-filter-from-clear') as HTMLElement)

    expect(sent).toHaveLength(1)
    expect(sent.at(-1)?.filter).toEqual({})
  })

  it('says which of the four shapes the range is in', () => {
    const read = (made: ReturnType<typeof makeController>): string | null => {
      const rendered = renderControl(made.controller, made.view())
      const text = byId('hilos-table-filter-from')?.textContent ?? null
      rendered.unmount()

      return text
    }

    expect(
      read(
        makeController(PERIOD_FILTER, { from: '2026-08-01', to: '2026-08-31' }),
      ),
    ).toBe('Period: 2026-08-01 – 2026-08-31')
    expect(read(makeController(PERIOD_FILTER, { from: '2026-08-01' }))).toBe(
      'Period: from 2026-08-01',
    )
    expect(read(makeController(PERIOD_FILTER, { to: '2026-08-31' }))).toBe(
      'Period: until 2026-08-31',
    )
    expect(read(makeController(PERIOD_FILTER))).toBe('Period')
  })
})

describe('HilosTableFilterControl, toggle', () => {
  it('writes the declared value when switched on', () => {
    const { controller, sent, view } = makeController(FAILED_FILTER)
    renderControl(controller, view())

    fireEvent.click(byId('hilos-table-filter-state') as HTMLElement)

    expect(sent.at(-1)?.filter).toEqual({ state: 'failed' })
  })

  it('drops the key when switched off', () => {
    const { controller, sent, view } = makeController(FAILED_FILTER, {
      state: 'failed',
    })
    renderControl(controller, view())
    const toggle = byId('hilos-table-filter-state') as HTMLInputElement
    expect(toggle.checked).toBe(true)

    fireEvent.click(toggle)

    expect(sent.at(-1)?.filter).not.toHaveProperty('state')
  })
})

describe('HilosTableFilterControl, placement', () => {
  it('suffixes every name of the copy drawn in the modal', () => {
    const period = makeController(PERIOD_FILTER)
    renderControl(period.controller, period.view(), 'modal')
    const kind = makeController(KIND_FILTER)
    kind.controller.ingestFacetCounts({
      kind: { any: { count: 3, exact: true }, options: {} },
    })
    renderControl(kind.controller, kind.view(), 'modal')

    for (const name of [
      'hilos-table-filter-from-modal',
      'hilos-table-filter-from-modal-from',
      'hilos-table-filter-from-modal-to',
      'hilos-table-filter-from-modal-clear',
    ]) {
      expect(byId(name)).not.toBeNull()
    }
    expect(byId('hilos-table-filter-from')).toBeNull()
    expect(byId('hilos-table-filter-kind-modal')).not.toBeNull()
    expect(byId('hilos-table-facet-kind-any-modal')).not.toBeNull()
  })
})
