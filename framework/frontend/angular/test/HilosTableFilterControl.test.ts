// The Angular port of vue/src/HilosTableFilterControl.test.ts, under the same case
// names the Vue reference and the React port run. Every case mounts a host rather
// than the component itself: the inputs are signal inputs, and a host binding
// them is how the control is used.
import { Component } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { describe, expect, it } from 'vitest'
import { TableViewportController } from '@hilos/core'
import type {
  HilosTableFilter,
  HilosTableFilterView,
  TableViewportDescriptor,
} from '@hilos/core'

import { HilosTableFilterControl } from '../src/HilosTableFilterControl.js'

const COLUMNS = [{ key: 'name', label: 'Name' }]

/** A host binding the three inputs a case hands over. */
@Component({
  selector: 'test-table-filter-control-host',
  imports: [HilosTableFilterControl],
  template: `
    <hilos-table-filter-control
      [view]="view"
      [controller]="controller"
      [placement]="placement"
    />
  `,
})
class FilterControlHost {
  view!: HilosTableFilterView
  controller!: TableViewportController<unknown>
  placement: 'bar' | 'modal' = 'bar'
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

function mountControl(
  controller: TableViewportController<unknown>,
  view: HilosTableFilterView,
  placement: 'bar' | 'modal' = 'bar',
): ComponentFixture<FilterControlHost> {
  const fixture = TestBed.createComponent(FilterControlHost)
  fixture.componentInstance.controller = controller
  fixture.componentInstance.view = view
  fixture.componentInstance.placement = placement
  fixture.detectChanges()

  return fixture
}

function byId(
  fixture: ComponentFixture<unknown>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

function texts(fixture: ComponentFixture<unknown>, prefix: string): string[] {
  return Array.from(
    (fixture.nativeElement as HTMLElement).querySelectorAll(
      `[data-id^="${prefix}"]`,
    ),
  ).map((element) => element.textContent?.trim() ?? '')
}

function setInput(element: HTMLElement | null, value: string): void {
  const field = element as HTMLInputElement
  field.value = value
  field.dispatchEvent(new Event('input'))
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
    const fixture = mountControl(controller, view())

    expect(texts(fixture, 'hilos-dropdown-option-')).toEqual([
      'Any',
      'Full',
      'Partial',
    ])
  })

  it('names the "no choice" option as the filter declared it', () => {
    const { controller, view } = makeController(KIND_FILTER_NAMED_ANY)
    const fixture = mountControl(controller, view())

    expect(byId(fixture, 'hilos-dropdown-option--1')?.textContent?.trim()).toBe(
      'All kinds',
    )
  })

  it('sends the DECLARED value of the option picked, keeping its type', () => {
    const { controller, sent, view } = makeController(KIND_FILTER)
    const fixture = mountControl(controller, view())

    byId(fixture, 'hilos-dropdown-option-1')?.click()

    expect(sent).toHaveLength(1)
    expect(sent.at(-1)?.filter).toEqual({ kind: 2 })
  })

  it('drops the key when the "no choice" option is picked', () => {
    const { controller, sent, view } = makeController(KIND_FILTER)
    const fixture = mountControl(controller, view())
    byId(fixture, 'hilos-dropdown-option-0')?.click()
    fixture.destroy()

    const next = mountControl(controller, view())
    byId(next, 'hilos-dropdown-option--1')?.click()

    expect(sent.at(-1)?.filter).not.toHaveProperty('kind')
  })

  it('reads the picked option back as "<label>: <option>"', () => {
    const { controller, view } = makeController(KIND_FILTER, { kind: 'full' })
    const fixture = mountControl(controller, view())

    expect(byId(fixture, 'hilos-dropdown-toggle')?.textContent?.trim()).toBe(
      'Kind: Full',
    )
  })

  it('reads as the "no choice" option while the filter holds no value', () => {
    const { controller, view } = makeController(KIND_FILTER)
    const fixture = mountControl(controller, view())

    expect(byId(fixture, 'hilos-dropdown-toggle')?.textContent?.trim()).toBe(
      'Kind: Any',
    )
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
    const fixture = mountControl(controller, view())

    expect(texts(fixture, 'hilos-table-facet-')).toHaveLength(0)
    expect(texts(fixture, 'hilos-dropdown-option-')).toEqual([
      'Any',
      'Full',
      'Partial',
    ])
  })

  it('writes each option its number, the ceiling as "500+" and an empty one as 0', () => {
    const { controller, view } = makeController(KIND_FILTER)
    controller.ingestFacetCounts(COUNTS)
    const fixture = mountControl(controller, view())

    expect(byId(fixture, 'hilos-table-facet-kind-any')?.textContent).toBe(
      '500+',
    )
    expect(byId(fixture, 'hilos-table-facet-kind-full')?.textContent).toBe(
      '412',
    )
    expect(byId(fixture, 'hilos-table-facet-kind-2')?.textContent).toBe('0')
    expect(
      byId(fixture, 'hilos-dropdown-option-0')?.querySelector('.text-truncate')
        ?.textContent,
    ).toBe('Full')
  })

  it('still sends the declared value of the option picked', () => {
    const { controller, sent, view } = makeController(KIND_FILTER)
    controller.ingestFacetCounts(COUNTS)
    const fixture = mountControl(controller, view())

    byId(fixture, 'hilos-dropdown-option-1')?.click()

    expect(sent.at(-1)?.filter).toEqual({ kind: 2 })
  })

  it('mutes the number of every option but the one picked', () => {
    const { controller, view } = makeController(KIND_FILTER, { kind: 'full' })
    controller.ingestFacetCounts(COUNTS)
    const fixture = mountControl(controller, view())

    expect(
      byId(fixture, 'hilos-table-facet-kind-full')?.classList.contains(
        'text-body-secondary',
      ),
    ).toBe(false)
    expect(
      byId(fixture, 'hilos-table-facet-kind-any')?.classList.contains(
        'text-body-secondary',
      ),
    ).toBe(true)
  })
})

describe('HilosTableFilterControl, date range', () => {
  it('sends BOTH bounds in one window change when only one is touched', () => {
    const { controller, sent, view } = makeController(PERIOD_FILTER, {
      to: '2026-08-31',
    })
    const fixture = mountControl(controller, view())

    setInput(byId(fixture, 'hilos-table-filter-from-from'), '2026-08-01')

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
    const fixture = mountControl(controller, view())

    byId(fixture, 'hilos-table-filter-from-clear')?.click()

    expect(sent).toHaveLength(1)
    expect(sent.at(-1)?.filter).toEqual({})
  })

  it('says which of the four shapes the range is in', () => {
    const read = (
      made: ReturnType<typeof makeController>,
    ): string | undefined =>
      byId(
        mountControl(made.controller, made.view()),
        'hilos-table-filter-from',
      )?.textContent?.trim()

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
    const fixture = mountControl(controller, view())

    byId(fixture, 'hilos-table-filter-state')?.click()

    expect(sent.at(-1)?.filter).toEqual({ state: 'failed' })
  })

  it('drops the key when switched off', () => {
    const { controller, sent, view } = makeController(FAILED_FILTER, {
      state: 'failed',
    })
    const fixture = mountControl(controller, view())
    const toggle = byId(fixture, 'hilos-table-filter-state') as HTMLInputElement
    expect(toggle.checked).toBe(true)

    toggle.click()

    expect(sent.at(-1)?.filter).not.toHaveProperty('state')
  })
})

describe('HilosTableFilterControl, placement', () => {
  it('suffixes every name of the copy drawn in the modal', () => {
    const period = makeController(PERIOD_FILTER)
    const range = mountControl(period.controller, period.view(), 'modal')
    const kind = makeController(KIND_FILTER)
    kind.controller.ingestFacetCounts({
      kind: { any: { count: 3, exact: true }, options: {} },
    })
    const select = mountControl(kind.controller, kind.view(), 'modal')

    for (const name of [
      'hilos-table-filter-from-modal',
      'hilos-table-filter-from-modal-from',
      'hilos-table-filter-from-modal-to',
      'hilos-table-filter-from-modal-clear',
    ]) {
      expect(byId(range, name)).not.toBeNull()
    }
    expect(byId(range, 'hilos-table-filter-from')).toBeNull()
    expect(byId(select, 'hilos-table-filter-kind-modal')).not.toBeNull()
    expect(byId(select, 'hilos-table-facet-kind-any-modal')).not.toBeNull()
  })
})
