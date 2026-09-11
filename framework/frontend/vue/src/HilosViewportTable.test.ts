import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { h } from 'vue'
import { TableViewportController } from '@hilos/core'
import type {
  HilosTableColumn,
  HilosTableFrame,
  TableViewportDescriptor,
} from '@hilos/core'

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
function makeController(frame?: HilosTableFrame): {
  controller: TableViewportController<unknown>
  sent: TableViewportDescriptor[]
} {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<unknown>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: (descriptor) => sent.push(descriptor),
    frame,
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
      true,
      null,
      null,
      10,
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
      true,
      null,
      null,
      10,
    )
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })
    const wrapper = mountTable(controller)

    expect(wrapper.find('[data-id="hilos-table-pending"]').text()).toBe('1')
    await wrapper.find('[data-id="hilos-table-apply"]').trigger('click')

    expect(wrapper.text()).toContain('new')
    expect(wrapper.find('[data-id="hilos-table-apply"]').exists()).toBe(false)
  })

  it('highlights a row whose new value landed in place, with no waiting mark', () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'old' } }],
      1,
      true,
      null,
      null,
      10,
    )
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })
    const wrapper = mountTable(controller)

    expect(wrapper.find('[data-id="hilos-table-row-a"]').classes()).toContain(
      'table-success',
    )
    expect(wrapper.findAll('[data-id^="hilos-table-pending-"]')).toHaveLength(0)
  })

  it('marks a waiting row amber and says in words what waits on it', () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [
        { rowKey: 'a', slots: { name: 'Alice' } },
        { rowKey: 'b', slots: { name: 'Bob' } },
      ],
      2,
      true,
      null,
      null,
      10,
    )
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'Alicia' } },
    })
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'b',
      reason: 'deleted',
    })
    const wrapper = mountTable(controller)

    expect(wrapper.find('[data-id="hilos-table-row-a"]').classes()).toContain(
      'table-warning',
    )
    expect(wrapper.find('[data-id="hilos-table-pending-move-a"]').text()).toBe(
      'Will move',
    )
    expect(wrapper.find('[data-id="hilos-table-row-b"]').classes()).toContain(
      'table-warning',
    )
    expect(
      wrapper.find('[data-id="hilos-table-pending-remove-b"]').text(),
    ).toBe('Will leave')
  })

  it('lets the waiting outrank the highlight on a row that is both', () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'old' } }],
      1,
      true,
      null,
      null,
      10,
    )
    // The value lands and lights the row up; the move that follows is held at the
    // gate, so the row is highlighted and waiting at the same moment.
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'newer' } },
    })
    const wrapper = mountTable(controller)

    const row = wrapper.find('[data-id="hilos-table-row-a"]')
    expect(row.classes()).toContain('table-warning')
    expect(row.classes()).not.toContain('table-success')
  })

  it('grows the mark column with the waiting, header and body at once', async () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [
        { rowKey: 'a', slots: { name: 'Alice' } },
        { rowKey: 'b', slots: { name: 'Bob' } },
      ],
      2,
      true,
      null,
      null,
      10,
    )
    // An applied removal leaves a placeholder behind and nothing waiting, which is
    // the state where the mark column must not stand.
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    const wrapper = mountTable(controller)

    expect(wrapper.findAll('thead th')).toHaveLength(COLUMNS.length)
    expect(
      wrapper.find('[data-id="hilos-table-placeholder"]').attributes('colspan'),
    ).toBe(String(COLUMNS.length))

    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'b',
      row: { rowKey: 'b', slots: { name: 'Bobby' } },
    })
    await wrapper.vm.$nextTick()

    expect(wrapper.findAll('thead th')).toHaveLength(COLUMNS.length + 1)
    expect(
      wrapper.find('[data-id="hilos-table-placeholder"]').attributes('colspan'),
    ).toBe(String(COLUMNS.length + 1))
  })

  it('raises the announcement strip for rows above the window and counts them', async () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      true,
      null,
      null,
      10,
    )
    const wrapper = mountTable(controller)

    expect(wrapper.find('[data-id="hilos-table-announce"]').exists()).toBe(
      false,
    )

    controller.ingestAnnounce('x', 'above', 2, true)
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-id="hilos-table-announce"]').text()).toContain(
      '1 new row above the window',
    )

    controller.ingestAnnounce('y', 'above', 3, true)
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-id="hilos-table-announce"]').text()).toContain(
      '2 new rows above the window',
    )
  })

  it('leaves the strip down for a row announced inside the window', () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      true,
      null,
      null,
      10,
    )
    // The strip has one sentence and it names one place; the other outcome is a
    // design debt (D-041), and drawing it would mean inventing the words.
    controller.ingestAnnounce('x', 'inside', 2, true)
    const wrapper = mountTable(controller)

    expect(wrapper.find('[data-id="hilos-table-announce"]').exists()).toBe(
      false,
    )
  })

  it('asks for the window again when Show is pressed, and the strip goes', async () => {
    const { controller, sent } = makeController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      true,
      null,
      null,
      10,
    )
    controller.ingestAnnounce('x', 'above', 2, true)
    const wrapper = mountTable(controller)
    const asked = sent.length

    await wrapper.find('[data-id="hilos-table-announce-show"]').trigger('click')

    expect(sent).toHaveLength(asked + 1)
    expect(wrapper.find('[data-id="hilos-table-announce"]').exists()).toBe(
      false,
    )
  })

  it('stands both strips at once when there is new and there is waiting', () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      true,
      null,
      null,
      10,
    )
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'Alicia' } },
    })
    controller.ingestAnnounce('x', 'above', 2, true)
    const wrapper = mountTable(controller)

    expect(wrapper.find('[data-id="hilos-table-announce"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="hilos-table-apply"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="hilos-table-pending"]').text()).toBe('1')
  })

  it('renders a placeholder for an applied removal', () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      true,
      null,
      null,
      10,
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
      true,
      null,
      null,
      10,
    )
    controller.ingestCount(5, true)
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
      true,
      null,
      null,
      10,
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

describe('HilosViewportTable with a declared frame', () => {
  const FRAME: HilosTableFrame = {
    title: 'Backups',
    search: {},
    columns: COLUMNS,
  }

  function mountDeclared(controller: TableViewportController<unknown>) {
    return mount(HilosViewportTable, {
      props: { controller, columns: COLUMNS, label: 'Users', searchable: true },
      slots: {
        row: (props: { row: unknown; rowKey: string }) =>
          h('td', {}, (props.row as Row).name),
      },
    })
  }

  it('draws the declared bar and names the table by its visible title', () => {
    const { controller } = makeController(FRAME)
    const wrapper = mountDeclared(controller)

    const title = wrapper.find('[data-id="hilos-table-title"]')
    expect(title.text()).toBe('Backups')
    expect(wrapper.find('table').attributes('aria-labelledby')).toBe(
      title.attributes('id'),
    )
    expect(wrapper.find('caption').exists()).toBe(false)
  })

  it('draws the declared footer instead of the one built from props', () => {
    const { controller } = makeController(FRAME)
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      128,
      true,
      null,
      null,
      20,
    )
    const wrapper = mountDeclared(controller)

    expect(wrapper.find('[data-id="hilos-table-count"]').text()).toBe(
      '1 – 1 of 128',
    )
    expect(wrapper.find('[data-id="hilos-table-page"]').exists()).toBe(false)
  })

  it('leaves the props-driven bar out and keeps Apply in the waiting strip', () => {
    const { controller } = makeController(FRAME)
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      true,
      null,
      null,
      20,
    )
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'Alicia' } },
    })
    const wrapper = mountDeclared(controller)

    // One search box, the declared one; and the strips speak in both epochs of the
    // frame, because they are about the rows and not about what the page declared.
    expect(wrapper.findAll('[data-id="hilos-table-search"]')).toHaveLength(1)
    expect(wrapper.find('[data-id="hilos-table-apply"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="hilos-table-pending"]').text()).toBe('1')
  })
})
