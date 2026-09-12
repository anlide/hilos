import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { TableViewportController } from '@hilos/core'
import type { HilosTableFrame, TableViewportDescriptor } from '@hilos/core'

import HilosTableEmptyState from './HilosTableEmptyState.vue'

function makeController(frame?: HilosTableFrame): {
  controller: TableViewportController<unknown>
  sent: TableViewportDescriptor[]
} {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<unknown>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: (descriptor) => sent.push(descriptor),
    initialFilter: { channel: 'mail' },
    frame,
  })
  controller.ingestWindow([], 0, true, null, null, 10)

  return { controller, sent }
}

const FILTERED_FRAME: HilosTableFrame = {
  title: 'Deliveries',
  search: {},
  columns: [{ key: 'name', label: 'Name' }],
  filters: [
    {
      kind: 'select',
      key: 'status',
      label: 'Status',
      options: () => [{ value: 'failed', label: 'Failed' }],
    },
    { kind: 'date_range', fromKey: 'from', toKey: 'to', label: 'Period' },
    { kind: 'toggle', key: 'mine', label: 'Mine only', on: true },
  ],
}

describe('HilosTableEmptyState', () => {
  it('says the page own words and offers its main action', async () => {
    const press = vi.fn()
    const { controller } = makeController({
      title: 'Backups',
      columns: [{ key: 'name', label: 'Name' }],
      empty: {
        title: 'Nothing here yet',
        hint: 'Your first backup will show up here',
      },
      mainAction: { label: 'Create backup', press },
    })
    const wrapper = mount(HilosTableEmptyState, {
      props: { controller, kind: 'empty' },
    })

    const empty = wrapper.find('[data-id="hilos-table-empty"]')
    expect(empty.attributes('role')).toBe('status')
    expect(wrapper.find('[data-id="hilos-table-empty-title"]').text()).toBe(
      'Nothing here yet',
    )
    expect(wrapper.find('[data-id="hilos-table-empty-hint"]').text()).toBe(
      'Your first backup will show up here',
    )

    const action = wrapper.find('[data-id="hilos-table-empty-action"]')
    expect(action.text()).toBe('Create backup')
    await action.trigger('click')
    expect(press).toHaveBeenCalledTimes(1)
  })

  it('falls back to what the page passed in the slot when it declared no empty state', () => {
    const { controller } = makeController()
    const wrapper = mount(HilosTableEmptyState, {
      props: { controller, kind: 'empty' },
      slots: { default: () => h('span', { class: 'none-yet' }, 'No rows.') },
    })

    expect(wrapper.find('[data-id="hilos-table-empty"] .none-yet').text()).toBe(
      'No rows.',
    )
    expect(wrapper.find('[data-id="hilos-table-empty-title"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-table-empty-action"]').exists()).toBe(
      false,
    )
  })

  it('names the query and every active filter it found nothing for', async () => {
    const { controller } = makeController(FILTERED_FRAME)
    controller.setSearch('night')
    controller.setFilter('status', 'failed')
    controller.setFilters({ from: '2026-08-01', to: '2026-08-31' })
    controller.setFilter('mine', true)
    const wrapper = mount(HilosTableEmptyState, {
      props: { controller, kind: 'empty_filtered' },
    })

    const state = wrapper.find('[data-id="hilos-table-no-matches"]')
    expect(state.attributes('role')).toBe('status')
    expect(state.text()).toContain('Nothing found')
    expect(
      wrapper.find('[data-id="hilos-table-no-matches-terms"]').text(),
    ).toBe(
      'No rows match “night” · Status: Failed · Period: 2026-08-01 – 2026-08-31 · Mine only',
    )

    // A half-open range leaves the missing bound out of the sentence.
    controller.setFilters({ from: '', to: '2026-08-31' })
    await wrapper.vm.$nextTick()
    expect(
      wrapper.find('[data-id="hilos-table-no-matches-terms"]').text(),
    ).toContain('Period: until 2026-08-31')
    controller.setFilters({ from: '2026-08-01', to: '' })
    await wrapper.vm.$nextTick()
    expect(
      wrapper.find('[data-id="hilos-table-no-matches-terms"]').text(),
    ).toContain('Period: from 2026-08-01')
  })

  it('resets the search and the filters back to the ones the table opened with', async () => {
    const { controller, sent } = makeController(FILTERED_FRAME)
    controller.setSearch('night')
    controller.setFilter('status', 'failed')
    const wrapper = mount(HilosTableEmptyState, {
      props: { controller, kind: 'empty_filtered' },
    })

    await wrapper
      .find('[data-id="hilos-table-no-matches-reset"]')
      .trigger('click')

    expect(sent.at(-1)?.filter).toEqual({ channel: 'mail' })
  })
})
