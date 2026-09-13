import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { TableViewportController } from '@hilos/core'
import type {
  HilosTableFilter,
  HilosTableFilterView,
  TableViewportDescriptor,
} from '@hilos/core'

import HilosTableFilterControl from './HilosTableFilterControl.vue'

const COLUMNS = [{ key: 'name', label: 'Name' }]

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
) {
  return mount(HilosTableFilterControl, { props: { view, controller } })
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
    const wrapper = mountControl(controller, view())

    const options = wrapper.findAll('[data-id^="hilos-dropdown-option-"]')
    expect(options.map((option) => option.text())).toEqual([
      'Any',
      'Full',
      'Partial',
    ])
  })

  it('names the "no choice" option as the filter declared it', () => {
    const { controller, view } = makeController(KIND_FILTER_NAMED_ANY)
    const wrapper = mountControl(controller, view())

    expect(wrapper.find('[data-id="hilos-dropdown-option--1"]').text()).toBe(
      'All kinds',
    )
  })

  it('sends the DECLARED value of the option picked, keeping its type', async () => {
    const { controller, sent, view } = makeController(KIND_FILTER)
    const wrapper = mountControl(controller, view())

    await wrapper.find('[data-id="hilos-dropdown-option-1"]').trigger('click')

    expect(sent).toHaveLength(1)
    expect(sent.at(-1)?.filter).toEqual({ kind: 2 })
  })

  it('drops the key when the "no choice" option is picked', async () => {
    const { controller, sent, view } = makeController(KIND_FILTER)
    const wrapper = mountControl(controller, view())
    await wrapper.find('[data-id="hilos-dropdown-option-0"]').trigger('click')

    const next = mountControl(controller, view())
    await next.find('[data-id="hilos-dropdown-option--1"]').trigger('click')

    expect(sent.at(-1)?.filter).not.toHaveProperty('kind')
  })

  it('reads the picked option back as "<label>: <option>"', () => {
    const { controller, view } = makeController(KIND_FILTER, { kind: 'full' })
    const wrapper = mountControl(controller, view())

    expect(wrapper.find('[data-id="hilos-dropdown-toggle"]').text()).toBe(
      'Kind: Full',
    )
  })

  it('reads as the "no choice" option while the filter holds no value', () => {
    const { controller, view } = makeController(KIND_FILTER)
    const wrapper = mountControl(controller, view())

    expect(wrapper.find('[data-id="hilos-dropdown-toggle"]').text()).toBe(
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
    const wrapper = mountControl(controller, view())

    expect(wrapper.findAll('[data-id^="hilos-table-facet-"]')).toHaveLength(0)
    expect(
      wrapper
        .findAll('[data-id^="hilos-dropdown-option-"]')
        .map((option) => option.text()),
    ).toEqual(['Any', 'Full', 'Partial'])
  })

  it('writes each option its number, the ceiling as "500+" and an empty one as 0', () => {
    const { controller, view } = makeController(KIND_FILTER)
    controller.ingestFacetCounts(COUNTS)
    const wrapper = mountControl(controller, view())

    expect(wrapper.find('[data-id="hilos-table-facet-kind-any"]').text()).toBe(
      '500+',
    )
    expect(wrapper.find('[data-id="hilos-table-facet-kind-full"]').text()).toBe(
      '412',
    )
    expect(wrapper.find('[data-id="hilos-table-facet-kind-2"]').text()).toBe(
      '0',
    )
    expect(
      wrapper.find('[data-id="hilos-dropdown-option-0"] .text-truncate').text(),
    ).toBe('Full')
  })

  it('still sends the declared value of the option picked', async () => {
    const { controller, sent, view } = makeController(KIND_FILTER)
    controller.ingestFacetCounts(COUNTS)
    const wrapper = mountControl(controller, view())

    await wrapper.find('[data-id="hilos-dropdown-option-1"]').trigger('click')

    expect(sent.at(-1)?.filter).toEqual({ kind: 2 })
  })

  it('mutes the number of every option but the one picked', () => {
    const { controller, view } = makeController(KIND_FILTER, { kind: 'full' })
    controller.ingestFacetCounts(COUNTS)
    const wrapper = mountControl(controller, view())

    expect(
      wrapper.find('[data-id="hilos-table-facet-kind-full"]').classes(),
    ).not.toContain('text-body-secondary')
    expect(
      wrapper.find('[data-id="hilos-table-facet-kind-any"]').classes(),
    ).toContain('text-body-secondary')
  })
})

describe('HilosTableFilterControl, date range', () => {
  it('sends BOTH bounds in one window change when only one is touched', async () => {
    const { controller, sent, view } = makeController(PERIOD_FILTER, {
      to: '2026-08-31',
    })
    const wrapper = mountControl(controller, view())

    await wrapper
      .find('[data-id="hilos-table-filter-from-from"]')
      .setValue('2026-08-01')

    expect(sent).toHaveLength(1)
    expect(sent.at(-1)?.filter).toEqual({
      from: '2026-08-01',
      to: '2026-08-31',
    })
  })

  it('clears both bounds with one window change', async () => {
    const { controller, sent, view } = makeController(PERIOD_FILTER, {
      from: '2026-08-01',
      to: '2026-08-31',
    })
    const wrapper = mountControl(controller, view())

    await wrapper
      .find('[data-id="hilos-table-filter-from-clear"]')
      .trigger('click')

    expect(sent).toHaveLength(1)
    expect(sent.at(-1)?.filter).toEqual({})
  })

  it('says which of the four shapes the range is in', () => {
    const both = makeController(PERIOD_FILTER, {
      from: '2026-08-01',
      to: '2026-08-31',
    })
    const fromOnly = makeController(PERIOD_FILTER, { from: '2026-08-01' })
    const toOnly = makeController(PERIOD_FILTER, { to: '2026-08-31' })
    const neither = makeController(PERIOD_FILTER)

    const read = (made: ReturnType<typeof makeController>): string =>
      mountControl(made.controller, made.view())
        .find('[data-id="hilos-table-filter-from"]')
        .text()

    expect(read(both)).toBe('Period: 2026-08-01 – 2026-08-31')
    expect(read(fromOnly)).toBe('Period: from 2026-08-01')
    expect(read(toOnly)).toBe('Period: until 2026-08-31')
    expect(read(neither)).toBe('Period')
  })
})

describe('HilosTableFilterControl, toggle', () => {
  it('writes the declared value when switched on', async () => {
    const { controller, sent, view } = makeController(FAILED_FILTER)
    const wrapper = mountControl(controller, view())

    await wrapper.find('[data-id="hilos-table-filter-state"]').setValue(true)

    expect(sent.at(-1)?.filter).toEqual({ state: 'failed' })
  })

  it('drops the key when switched off', async () => {
    const { controller, sent, view } = makeController(FAILED_FILTER, {
      state: 'failed',
    })
    const wrapper = mountControl(controller, view())
    expect(
      (
        wrapper.find('[data-id="hilos-table-filter-state"]')
          .element as HTMLInputElement
      ).checked,
    ).toBe(true)

    await wrapper.find('[data-id="hilos-table-filter-state"]').setValue(false)

    expect(sent.at(-1)?.filter).not.toHaveProperty('state')
  })
})
