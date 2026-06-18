import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { h } from 'vue'
import { TableController, createSignal } from '@hilos/core'
import type { HilosTableColumn, TableRow } from '@hilos/core'

import HilosTable from './HilosTable.vue'

interface Row {
  name: string
}

const COLUMNS: HilosTableColumn[] = [
  { key: 'name', label: 'Name', sortable: true },
]

// The controller is typed as unknown so the slot/controller line up with the
// generic SFC, whose `R` @vue/test-utils does not infer from the prop value.
function makeController(pageSize = 0): TableController<unknown> {
  // Non-alphabetical arrival order so sorting is observable.
  const source = createSignal<readonly TableRow[]>([
    { rowKey: 'b', slots: { name: 'Bob' } },
    { rowKey: 'a', slots: { name: 'Alice' } },
    { rowKey: 'c', slots: { name: 'Carol' } },
  ])

  return new TableController<unknown>({
    source,
    resolve: (raw) => ({ name: String(raw.slots.name) }),
    searchText: (row) => (row as Row).name,
    sortValue: (row) => (row as Row).name,
    pageSize,
  })
}

function mountTable(controller: TableController<unknown>, searchable = false) {
  return mount(HilosTable, {
    props: { controller, columns: COLUMNS, searchable },
    slots: {
      row: (props: { row: unknown; rowKey: string }) =>
        h('td', { class: 'cell' }, (props.row as Row).name),
    },
  })
}

describe('HilosTable', () => {
  it('renders a row per controller row through the #row slot', () => {
    const wrapper = mountTable(makeController())
    expect(wrapper.findAll('[data-id^="hilos-table-row-"]')).toHaveLength(3)
    expect(wrapper.text()).toContain('Alice')
  })

  it('filters through the search box', async () => {
    const wrapper = mountTable(makeController(), true)
    await wrapper.find('[data-id="hilos-table-search"]').setValue('bob')
    expect(wrapper.findAll('[data-id^="hilos-table-row-"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Bob')
  })

  it('sorts when a sortable header is clicked', async () => {
    const wrapper = mountTable(makeController())
    await wrapper.find('[data-id="hilos-table-sort-name"]').trigger('click')
    expect(wrapper.findAll('td.cell').map((cell) => cell.text())).toEqual([
      'Alice',
      'Bob',
      'Carol',
    ])
  })
})
