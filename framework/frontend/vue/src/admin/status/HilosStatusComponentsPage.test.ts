import { DOMWrapper, mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosPages, type TableRow } from '@hilos/core/base'

import HilosStatusComponentsPage from './HilosStatusComponentsPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { statusHarness } from '../../../test/statusFixtures.js'

const cleanups: (() => void)[] = []
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) cleanup()
})
const states = [
  'operational',
  'checking',
  'partial_outage',
  'major_outage',
  'maintenance',
  'unknown',
] as const
const rows = states.map((state, index) => ({
  rowKey: `component-${index}`,
  slots: {
    component: {
      componentKey: `component-${index}`,
      name: `Component ${index}`,
      description: `Description ${index}`,
      agentType: `agent${index}`,
      state,
    },
  },
}))
async function page() {
  const h = statusHarness(HilosPages.STATUS_COMPONENTS)
  cleanups.push(h.dispose)
  const wrapper = mount(HilosStatusComponentsPage, {
    props: { context: markRaw(h.context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: h.router } },
  })
  cleanups.push(() => wrapper.unmount())
  await nextTick()
  const window = (nextRows: readonly TableRow[] = rows, filter = {}) =>
    h.emit('projectSignal', {
      type: 'page_response',
      data: {
        page: 'hilos_status_components',
        payload: {
          windows: {
            hilosStatusComponents: {
              rows: nextRows,
              totalCount: nextRows.length,
              totalExact: true,
              firstAnchor: null,
              lastAnchor: null,
              limit: 25,
              sort: null,
              filter,
            },
          },
        },
      },
    })
  return { ...h, wrapper, window }
}

describe('HilosStatusComponentsPage with the real viewport renderer', () => {
  it('renders the shared section head, active Components route and named table without a mount fetch', async () => {
    const { wrapper, window, sent, viewports, registered } = await page()
    expect(sent).toEqual([])
    expect(viewports).toEqual([])
    expect(registered.size).toBe(1)
    window()
    await nextTick()
    expect(wrapper.findAll('h1').map((h) => h.text())).toEqual(['Status'])
    const tabs = wrapper.get('nav[aria-label="Status pages"]')
    expect(
      tabs.findAll('a').map((a) => [a.text(), a.attributes('aria-current')]),
    ).toEqual([
      ['Overview', undefined],
      ['Components', 'page'],
    ])
    const table = wrapper.get('table')
    expect(table.attributes('aria-labelledby')).toBe(
      wrapper.get('h1').attributes('id'),
    )
    expect(table.findAll('tbody tr').map((row) => row.text())).toEqual(
      states.map((_, i) => expect.stringContaining(`Component ${i}`)),
    )
    for (const label of [
      'Operational',
      'Checking',
      'Partial outage',
      'Major outage',
      'Maintenance',
      'Unknown',
    ])
      expect(table.text()).toContain(label)
    expect(table.text()).toContain('Description 0')
    expect(table.text()).toContain('agent0')
    expect(table.find('[aria-sort]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-table-main-action"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-table-live-slot"]').exists()).toBe(
      true,
    )
  })
  it('sends search and state filters through the standard controls, preserving server authority and empty-search copy', async () => {
    const { wrapper, window, viewports } = await page()
    window()
    await nextTick()
    const search = wrapper.get('[data-id="hilos-table-search"]')
    expect(search.attributes('aria-label')).toBe(
      'Search by name, description or agent…',
    )
    await search.setValue('agent0')
    expect(viewports.at(-1)).toEqual([
      'hilos_status_components',
      'hilosStatusComponents',
      expect.objectContaining({ filter: { search: 'agent0' }, limit: 25 }),
    ])
    // The browser has requested a search; only a server window chooses its rows.
    window([], { search: 'agent0' })
    await nextTick()
    expect(wrapper.text()).toContain('Nothing found')
    expect(wrapper.text()).not.toContain('All systems operational')
    const filter = wrapper.get('[data-id="hilos-table-filter-state"]')
    await filter.get('[data-id="hilos-dropdown-toggle"]').trigger('click')
    const menu = new DOMWrapper(document.body).get(
      '[data-id="hilos-dropdown-menu"]',
    )
    expect(menu.text()).toContain('Unknown')
    await menu.get('[data-id="hilos-dropdown-option-5"]').trigger('click')
    expect(viewports.at(-1)).toEqual([
      'hilos_status_components',
      'hilosStatusComponents',
      expect.objectContaining({
        filter: { search: 'agent0', state: 'unknown' },
        sort: null,
        limit: 25,
      }),
    ])
  })
  it('updates a visible value immediately and keeps membership drift behind the real Apply strip', async () => {
    const { wrapper, window, emit, viewports } = await page()
    window()
    await nextTick()
    emit('tableViewportDelta', {
      data: {
        page: 'hilos_status_components',
        tableKey: 'hilosStatusComponents',
        kind: 'row_updated',
        rowKey: 'component-0',
        row: {
          rowKey: 'component-0',
          slots: {
            component: { ...rows[0]!.slots.component, state: 'maintenance' },
          },
        },
      },
    })
    await nextTick()
    expect(
      wrapper
        .get('table [data-id="hilos-status-component-state-component-0"]')
        .text(),
    ).toBe('Maintenance')
    emit('tableViewportDelta', {
      data: {
        page: 'hilos_status_components',
        tableKey: 'hilosStatusComponents',
        kind: 'row_removed',
        rowKey: 'component-1',
        reason: 'left_set',
      },
    })
    await nextTick()
    expect(wrapper.get('table').text()).toContain('Component 1')
    const apply = wrapper.get('[data-id="hilos-table-apply"]')
    expect((apply.element as HTMLButtonElement).disabled).toBe(false)
    await apply.trigger('click')
    expect(
      wrapper
        .get('table')
        .find('[data-id="hilos-status-component-component-1"]')
        .exists(),
    ).toBe(false)
    expect(wrapper.find('[data-id="hilos-table-apply"]').exists()).toBe(false)
    expect(viewports).toEqual([])
  })

  it('renders metadata-only staleness as neutral Unknown under a state filter without consuming Apply', async () => {
    const { wrapper, window, emit, viewports } = await page()
    const slot = Object.freeze({ ...rows[0]!.slots.component })
    const raw = Object.freeze({
      rowKey: 'component-0',
      slots: Object.freeze({ component: slot }),
    })
    const nextRows = [
      raw,
      {
        rowKey: 'component-1',
        slots: {
          component: { ...rows[1]!.slots.component, state: 'operational' },
        },
      },
    ]
    window(nextRows)
    await nextTick()
    const filter = wrapper.get('[data-id="hilos-table-filter-state"]')
    await filter.get('[data-id="hilos-dropdown-toggle"]').trigger('click')
    await new DOMWrapper(document.body)
      .get('[data-id="hilos-dropdown-option-0"]')
      .trigger('click')
    expect(viewports.at(-1)).toEqual([
      'hilos_status_components',
      'hilosStatusComponents',
      expect.objectContaining({ filter: { state: 'operational' } }),
    ])
    window(nextRows, { state: 'operational' })
    await nextTick()
    const state = () =>
      wrapper.get('table [data-id="hilos-status-component-state-component-0"]')
    expect(state().text()).toBe('Operational')
    expect(state().classes()).toContain('text-bg-success')
    const requests = [...viewports]
    emit('tableViewportDelta', {
      data: {
        page: 'hilos_status_components',
        tableKey: 'hilosStatusComponents',
        kind: 'row_removed',
        rowKey: 'component-0',
        reason: 'left_set',
      },
    })
    await nextTick()
    for (const [staleSources, label] of [
      [['component'], 'Unknown'],
      [[], 'Operational'],
      [['unrelated'], 'Operational'],
      [['component'], 'Unknown'],
    ] as const) {
      emit('tableViewportDelta', {
        data: {
          page: 'hilos_status_components',
          tableKey: 'hilosStatusComponents',
          kind: 'row_stale',
          rowKey: 'component-0',
          staleSources,
        },
      })
      await nextTick()
      expect(state().text()).toBe(label)
      expect(state().classes()).toEqual(
        expect.arrayContaining(
          label === 'Unknown'
            ? ['bg-body-secondary', 'text-body-emphasis']
            : ['text-bg-success'],
        ),
      )
      if (label === 'Unknown')
        expect(state().classes()).not.toContain('text-bg-success')
      expect(
        wrapper
          .get('table')
          .findAll('tbody tr')
          .map((row) =>
            row
              .get('[data-id^="hilos-status-component-state-"]')
              .attributes('data-id'),
          ),
      ).toEqual([
        'hilos-status-component-state-component-0',
        'hilos-status-component-state-component-1',
      ])
      expect(wrapper.get('[data-id="hilos-table-pending"]').text()).toBe('1')
      expect(
        wrapper
          .get('table [data-id="hilos-table-pending-remove-component-0"]')
          .text(),
      ).toBe('Will leave')
      expect(
        (
          wrapper.get('[data-id="hilos-table-apply"]')
            .element as HTMLButtonElement
        ).disabled,
      ).toBe(false)
      expect(viewports).toEqual(requests)
      expect(raw.slots.component).toBe(slot)
      expect(slot.state).toBe('operational')
    }
    await wrapper.get('[data-id="hilos-table-apply"]').trigger('click')
    expect(
      wrapper
        .get('table')
        .find('[data-id="hilos-status-component-component-0"]')
        .exists(),
    ).toBe(false)
    expect(wrapper.find('[data-id="hilos-table-apply"]').exists()).toBe(false)
  })

  it.each([
    ['operational', 'Operational'],
    ['partial_outage', 'Partial outage'],
  ])(
    'renders a stale first window as Unknown and restores authoritative %s on freshness',
    async (state, label) => {
      const { wrapper, window, emit } = await page()
      const slot = Object.freeze({ ...rows[0]!.slots.component, state })
      window([
        {
          rowKey: 'component-0',
          slots: Object.freeze({ component: slot }),
          staleSources: ['component'],
        },
      ])
      await nextTick()
      const badge = () =>
        wrapper.get(
          'table [data-id="hilos-status-component-state-component-0"]',
        )
      expect(badge().text()).toBe('Unknown')
      expect(badge().classes()).toEqual(
        expect.arrayContaining(['bg-body-secondary', 'text-body-emphasis']),
      )
      emit('tableViewportDelta', {
        data: {
          page: 'hilos_status_components',
          tableKey: 'hilosStatusComponents',
          kind: 'row_stale',
          rowKey: 'component-0',
          staleSources: [],
        },
      })
      await nextTick()
      expect(badge().text()).toBe(label)
      expect(slot.state).toBe(state)
    },
  )

  it('releases the table registration and listeners on unmount', async () => {
    const { wrapper, registered } = await page()
    wrapper.unmount()
    expect(registered.size).toBe(0)
  })
})
