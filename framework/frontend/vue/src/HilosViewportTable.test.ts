import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { h } from 'vue'
import { TableViewportController } from '@hilos/core'
import type { HilosTableColumn, TableViewportDescriptor } from '@hilos/core'

import HilosViewportTable from './HilosViewportTable.vue'

interface Row {
  name: string
}

const COLUMNS: HilosTableColumn[] = [
  { key: 'name', label: 'Name', sortable: true },
]

// A second sortable column, for the one test about an order that runs by two.
const TWO_COLUMNS: HilosTableColumn[] = [
  { key: 'name', label: 'Name', sortable: true },
  { key: 'id', label: 'Id', sortable: true },
]

// The controller is typed as unknown so the slot/controller line up with the
// generic SFC, whose `R` @vue/test-utils does not infer from the prop value.
function makeController(): {
  controller: TableViewportController<unknown>
  sent: TableViewportDescriptor[]
} {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<unknown>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: (descriptor) => sent.push(descriptor),
    pageSize: 10,
  })

  return { controller, sent }
}

function mountTable(
  controller: TableViewportController<unknown>,
  searchable = false,
  columns: HilosTableColumn[] = COLUMNS,
) {
  return mount(HilosViewportTable, {
    props: { controller, columns, searchable },
    slots: {
      row: (props: { row: unknown; rowKey: string }) =>
        h('td', { class: 'cell' }, (props.row as Row).name),
    },
  })
}

describe('HilosViewportTable', () => {
  it('renders a row per window row through the #row slot', () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [
        { rowKey: 'a', slots: { name: 'Alice' } },
        { rowKey: 'b', slots: { name: 'Bob' } },
      ],
      2,
      null,
      null,
    )
    const wrapper = mountTable(controller)

    expect(wrapper.findAll('[data-id^="hilos-table-row-"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('Alice')
  })

  it('sends a viewport when a sortable header is clicked', async () => {
    const { controller, sent } = makeController()
    const wrapper = mountTable(controller)

    await wrapper.find('[data-id="hilos-table-sort-name"]').trigger('click')

    expect(sent.at(-1)).toMatchObject({
      sort: [{ field: 'name', direction: 'asc' }],
    })
  })

  it('marks every column an order of two runs by, not just the first', async () => {
    const { controller } = makeController()
    controller.setOrder([
      { field: 'name', direction: 'desc' },
      { field: 'id', direction: 'desc' },
    ])
    const wrapper = mountTable(controller, false, TWO_COLUMNS)
    await wrapper.vm.$nextTick()

    // Sorted by both columns means an arrow on both: one arrow would name one of
    // the two as the whole answer.
    const arrows = wrapper.findAll('th i.bi')

    expect(arrows).toHaveLength(2)
    expect(arrows.every((arrow) => arrow.classes('bi-arrow-down'))).toBe(true)
  })

  it('shows the apply button with the pending count and applies in place', async () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'old' } }],
      1,
      null,
      null,
    )
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })
    const wrapper = mountTable(controller)

    expect(wrapper.find('[data-id="hilos-table-pending"]').text()).toBe('1')
    await wrapper.find('[data-id="hilos-table-apply"]').trigger('click')

    expect(wrapper.text()).toContain('new')
    expect(wrapper.find('[data-id="hilos-table-apply"]').exists()).toBe(false)
  })

  it('renders a placeholder for an applied removal', () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      null,
      null,
    )
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    const wrapper = mountTable(controller)

    expect(wrapper.find('[data-id="hilos-table-placeholder"]').exists()).toBe(
      true,
    )
    expect(wrapper.text()).not.toContain('Alice')
  })

  it('does not render a list-changed banner on a set change (no layout shift)', () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      null,
      null,
    )
    controller.ingestCount(5)
    const wrapper = mountTable(controller)

    expect(wrapper.find('[data-id="hilos-table-list-changed"]').exists()).toBe(
      false,
    )
  })

  it('names the table with a caption and reports sort state to assistive tech', async () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      null,
      null,
    )
    const wrapper = mount(HilosViewportTable, {
      props: { controller, columns: COLUMNS, label: 'Users', searchable: true },
      slots: {
        row: (props: { row: unknown; rowKey: string }) =>
          h('td', {}, (props.row as Row).name),
      },
    })

    const caption = wrapper.find('caption')
    expect(caption.text()).toBe('Users')
    expect(caption.classes()).toContain('visually-hidden')
    expect(
      wrapper.find('[data-id="hilos-table-search"]').attributes('aria-label'),
    ).toBe('Search…')

    expect(wrapper.find('th').attributes('aria-sort')).toBe('none')
    await wrapper.find('[data-id="hilos-table-sort-name"]').trigger('click')
    expect(wrapper.find('th').attributes('aria-sort')).toBe('ascending')
    await wrapper.find('[data-id="hilos-table-sort-name"]').trigger('click')
    expect(wrapper.find('th').attributes('aria-sort')).toBe('descending')
    await wrapper.find('[data-id="hilos-table-sort-name"]').trigger('click')
    expect(wrapper.find('th').attributes('aria-sort')).toBe('none')
  })
})
