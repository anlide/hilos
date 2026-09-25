import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { TableViewportController } from '@hilos/core'
import type {
  ActionHandle,
  HilosTableBulkAccepted,
  HilosTableColumn,
  HilosTableFrame,
  HilosTableProgress,
  TableViewportDescriptor,
} from '@hilos/core'

import HilosViewportTable from './HilosViewportTable.vue'
import {
  hilosTableSelectionEdgeKey,
  type HilosTableSelectionEdge,
} from './hilosTableSelectionEdge.js'

interface Row {
  name: string
}

const COLUMNS: HilosTableColumn[] = [
  { key: 'name', label: 'Name', sortable: true },
]

/**
 * The sender of the declared operation, which this file never presses: the column
 * is what it is about, and the panel that presses is tested next door.
 */
function neverRun(): ActionHandle<HilosTableBulkAccepted> {
  throw new Error('the declaration is only read here')
}

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

  it('numbers the columns of a composite order and says the place in words', async () => {
    const { controller } = makeController()
    controller.setOrder([
      { field: 'name', direction: 'desc' },
      { field: 'id', direction: 'asc' },
    ])
    const wrapper = mountTable(controller, false, TWO_COLUMNS)
    await wrapper.vm.$nextTick()

    expect(wrapper.findAll('th sup').map((mark) => mark.text())).toEqual([
      '1',
      '2',
    ])
    // aria-sort names a direction and cannot say "second by importance", so the
    // place is spoken beside the number instead.
    expect(
      wrapper.findAll('th .visually-hidden').map((said) => said.text()),
    ).toEqual(['Sort column 1 of 2', 'Sort column 2 of 2'])
  })

  it('numbers nothing under an order of one column, where the arrow says it all', async () => {
    const { controller } = makeController()
    controller.setSort('name')
    const wrapper = mountTable(controller, false, TWO_COLUMNS)
    await wrapper.vm.$nextTick()

    expect(wrapper.findAll('th sup')).toHaveLength(0)
  })

  it('never repeats the state on display when the opening column is clicked on', async () => {
    const { controller, sent } = makeController()
    controller.ingestSubscriptionWindow(
      [],
      0,
      true,
      null,
      null,
      20,
      [{ field: 'name', direction: 'asc' }],
      [],
    )
    const wrapper = mountTable(controller)

    // The table opened sorted by this column ascending, so that state is already
    // on display and the cycle skips it: three clicks ask for three windows, no
    // one of them the window just shown.
    await wrapper.find('[data-id="hilos-table-sort-name"]').trigger('click')
    await wrapper.find('[data-id="hilos-table-sort-name"]').trigger('click')
    await wrapper.find('[data-id="hilos-table-sort-name"]').trigger('click')

    const orders = sent.map((descriptor) => JSON.stringify(descriptor.sort))
    expect(
      orders.filter((order, index) => index > 0 && order === orders[index - 1]),
    ).toEqual([])
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

  it('raises one strip for rows announced above or inside the window, and counts them together', async () => {
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

    // The strip names no place (HIL-1026): a row inside the window raises it as
    // one above does, and the two are one number.
    controller.ingestAnnounce('x', 'inside', 2, true)
    await wrapper.vm.$nextTick()

    const strip = wrapper.find('[data-id="hilos-table-announce"]')
    expect(strip.text()).toContain('1 new row')
    expect(strip.text()).not.toContain('above')

    controller.ingestAnnounce('y', 'above', 3, true)
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-id="hilos-table-announce"]').text()).toContain(
      '2 new rows',
    )
    expect(
      wrapper.find('[data-id="hilos-table-announce"]').text(),
    ).not.toContain('above')
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

  it('gives the one line to the waiting and keeps the new rows as an icon beside it', () => {
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

    // One room, one line: the waiting holds it because its button would be hidden
    // otherwise, and the new rows keep speaking by their icon (Flow F4).
    expect(wrapper.find('[data-id="hilos-table-announce"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-table-apply"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="hilos-table-pending"]').text()).toBe('1')
    expect(
      wrapper
        .find('[data-id="hilos-table-live-rest"] .bi-arrow-down-circle')
        .exists(),
    ).toBe(true)
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

  it('keeps Back live while rows stand to the left, page number or no page number', () => {
    const { controller } = makeController()
    // The window one press back from the tail after Show: rows 2 through 11 of twenty-one,
    // one row standing to its left. Its page number is the first, and a footer that read
    // the number alone would switch Back off over the very row the reader is going back for.
    controller.ingestWindow(
      [{ rowKey: 'b', slots: { name: 'Bob' } }],
      21,
      true,
      null,
      null,
      10,
      1,
    )
    const wrapper = mountTable(controller)

    expect(wrapper.find('[data-id="hilos-table-page"]').text()).toBe('1 / 3')
    expect(
      wrapper.find('[data-id="hilos-table-prev"]').attributes('disabled'),
    ).toBeUndefined()
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

describe('HilosViewportTable drawing the cells of a declared table', () => {
  // Two columns, one of them aligned the way a numeric column is: what the page
  // used to write onto its own `<td>` and now declares once.
  const CELL_COLUMNS: HilosTableColumn[] = [
    { key: 'name', label: 'Name' },
    {
      key: 'size',
      label: 'Size',
      headerClass: 'text-end',
      cellClass: 'text-end',
    },
  ]
  const CELL_FRAME: HilosTableFrame = {
    title: 'Backups',
    columns: CELL_COLUMNS,
  }

  function window(controller: TableViewportController<unknown>): void {
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      true,
      null,
      null,
      10,
    )
  }

  function mountCells(controller: TableViewportController<unknown>) {
    return mount(HilosViewportTable, {
      props: { controller, columns: CELL_COLUMNS },
      slots: {
        'cell-name': (props: { row: unknown }) =>
          h('span', { class: 'named' }, (props.row as Row).name),
        'cell-size': () => h('span', { class: 'sized' }, '1.2 GB'),
      },
    })
  }

  it('draws one cell per declared column and fills it from its own slot', () => {
    const { controller } = makeController(CELL_FRAME)
    window(controller)
    const wrapper = mountCells(controller)

    const cells = wrapper.findAll('[data-id="hilos-table-row-a"] td')
    expect(cells).toHaveLength(CELL_COLUMNS.length)
    expect(cells[0]?.find('.named').text()).toBe('Alice')
    expect(cells[1]?.find('.sized').text()).toBe('1.2 GB')
  })

  it('puts the declared cell class on the body cell and nowhere else', () => {
    const { controller } = makeController(CELL_FRAME)
    window(controller)
    const wrapper = mountCells(controller)

    const cells = wrapper.findAll('[data-id="hilos-table-row-a"] td')
    expect(cells[0]?.classes()).not.toContain('text-end')
    expect(cells[1]?.classes()).toContain('text-end')
  })

  it('leaves the cell standing where the page filled no slot', () => {
    const { controller } = makeController(CELL_FRAME)
    window(controller)
    const wrapper = mount(HilosViewportTable, {
      props: { controller, columns: CELL_COLUMNS },
      slots: {},
    })

    const cells = wrapper.findAll('[data-id="hilos-table-row-a"] td')
    expect(cells).toHaveLength(wrapper.findAll('thead th').length)
    expect(cells[1]?.text()).toBe('')
  })

  it('reads the columns off the declaration rather than off the prop', () => {
    const { controller } = makeController(CELL_FRAME)
    window(controller)
    const wrapper = mount(HilosViewportTable, {
      // A prop left behind from the props epoch: the declared table ignores it,
      // so its row and its header cannot be assembled from two different lists.
      props: { controller, columns: [{ key: 'name', label: 'Name' }] },
      slots: {
        'cell-size': () => h('span', { class: 'sized' }, '1.2 GB'),
      },
    })

    expect(wrapper.findAll('thead th')).toHaveLength(CELL_COLUMNS.length)
    expect(wrapper.find('thead').text()).toContain('Size')
    expect(wrapper.find('.sized').exists()).toBe(true)
  })

  it('keeps handing the whole row over while the page passes columns as a prop', () => {
    const { controller } = makeController()
    window(controller)
    const wrapper = mountTable(controller)

    expect(wrapper.find('[data-id="hilos-table-row-a"] td.cell').text()).toBe(
      'Alice',
    )
  })
})

describe('HilosViewportTable with a selection column', () => {
  // The table of a page that declared one bulk operation — the one sign that it
  // has marks at all, and the whole reason the column stands.
  const BULK_FRAME: HilosTableFrame = {
    title: 'Backups',
    columns: COLUMNS,
    bulkActions: [
      { key: 'delete', label: 'Delete', danger: true, run: neverRun },
    ],
  }

  /** The same table with nothing declared for marked rows. */
  const PLAIN_FRAME: HilosTableFrame = { title: 'Backups', columns: COLUMNS }

  function mountWithEdge(
    controller: TableViewportController<unknown>,
    edge?: HilosTableSelectionEdge,
    columns: HilosTableColumn[] = COLUMNS,
  ) {
    return mount(HilosViewportTable, {
      props: { controller, columns },
      slots: {
        row: (props: { row: unknown; rowKey: string }) =>
          h('td', {}, (props.row as Row).name),
      },
      global:
        edge === undefined
          ? {}
          : { provide: { [hilosTableSelectionEdgeKey as symbol]: edge } },
    })
  }

  function window(controller: TableViewportController<unknown>): void {
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
  }

  it('draws no checkbox column for a table that declared no bulk operations', () => {
    const { controller } = makeController(PLAIN_FRAME)
    window(controller)
    const wrapper = mountWithEdge(controller)

    expect(wrapper.find('[data-id="hilos-table-select-page"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-table-select-a"]').exists()).toBe(
      false,
    )
    expect(wrapper.findAll('thead th')).toHaveLength(COLUMNS.length)
  })

  it('puts the column first by default and last where the app asked for the end', () => {
    const { controller } = makeController(BULK_FRAME)
    window(controller)

    const left = mountWithEdge(controller)
    expect(
      left.findAll('thead th')[0]?.classes('hilos-table-selection-cell'),
    ).toBe(true)
    const leftCells = left.findAll('[data-id="hilos-table-row-a"] td')
    expect(leftCells[0]?.find('input').attributes('data-id')).toBe(
      'hilos-table-select-a',
    )

    const right = mountWithEdge(controller, 'end')
    const headers = right.findAll('thead th')
    expect(
      headers[headers.length - 1]?.classes('hilos-table-selection-cell'),
    ).toBe(true)
    const rightCells = right.findAll('[data-id="hilos-table-row-a"] td')
    expect(
      rightCells[rightCells.length - 1]?.find('input').attributes('data-id'),
    ).toBe('hilos-table-select-a')
  })

  it('stands after the row-state cell on the end edge', async () => {
    const { controller } = makeController(BULK_FRAME)
    window(controller)
    // A waiting change is what raises the row-state cell, so both framework cells
    // stand and their order can be read at all.
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'b',
      row: { rowKey: 'b', slots: { name: 'Bobby' } },
    })
    const wrapper = mountWithEdge(controller, 'end')
    await wrapper.vm.$nextTick()

    const headers = wrapper.findAll('thead th')
    expect(headers).toHaveLength(COLUMNS.length + 2)
    expect(headers[COLUMNS.length]?.classes('text-end')).toBe(true)
    expect(
      headers[COLUMNS.length + 1]?.classes('hilos-table-selection-cell'),
    ).toBe(true)
  })

  it('tells the core the state each checkbox is now in', async () => {
    const { controller } = makeController(BULK_FRAME)
    window(controller)
    const wrapper = mountWithEdge(controller)

    const row = wrapper.find('[data-id="hilos-table-select-a"]')
    await row.setValue(true)
    expect(controller.selection.count.get()).toBe(1)
    await row.setValue(false)
    expect(controller.selection.count.get()).toBe(0)

    const header = wrapper.find('[data-id="hilos-table-select-page"]')
    await header.setValue(true)
    expect(controller.selection.count.get()).toBe(2)
    await header.setValue(false)
    expect(controller.selection.count.get()).toBe(0)
  })

  it('shows the header checkbox empty, half-marked and full', async () => {
    const { controller } = makeController(BULK_FRAME)
    window(controller)
    const wrapper = mountWithEdge(controller)
    const header = wrapper.find<HTMLInputElement>(
      '[data-id="hilos-table-select-page"]',
    )

    expect(header.element.checked).toBe(false)
    expect(header.element.indeterminate).toBe(false)

    controller.selectRow('a', true)
    await wrapper.vm.$nextTick()
    expect(header.element.checked).toBe(false)
    expect(header.element.indeterminate).toBe(true)

    controller.selectRow('b', true)
    await wrapper.vm.$nextTick()
    expect(header.element.checked).toBe(true)
    expect(header.element.indeterminate).toBe(false)
  })

  it('gives a row shown as a placeholder no checkbox', async () => {
    const { controller } = makeController(BULK_FRAME)
    window(controller)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    const wrapper = mountWithEdge(controller)
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-id="hilos-table-placeholder"]').exists()).toBe(
      true,
    )
    expect(wrapper.find('[data-id="hilos-table-select-a"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-table-select-b"]').exists()).toBe(true)
  })

  it('counts the checkbox column into every cell that spans the row', async () => {
    const { controller } = makeController(BULK_FRAME)
    window(controller)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    const wrapper = mountWithEdge(controller)
    await wrapper.vm.$nextTick()

    // The placeholder spans the declared columns and the checkbox column; nothing
    // is waiting after the apply, so the row-state cell is not standing.
    expect(
      wrapper.find('[data-id="hilos-table-placeholder"]').attributes('colspan'),
    ).toBe(String(COLUMNS.length + 1))

    const empty = makeController(BULK_FRAME)
    empty.controller.ingestWindow([], 0, true, null, null, 10)
    const emptyWrapper = mountWithEdge(empty.controller)
    expect(emptyWrapper.find('tbody td').attributes('colspan')).toBe(
      String(COLUMNS.length + 1),
    )
  })

  it('adds an empty segment to a row bar so it stays as wide as its header', async () => {
    const { controller } = makeController(BULK_FRAME)
    window(controller)
    controller.ingestProgress({
      scope: 'row',
      progressKey: 'p1',
      rowKey: 'a',
      current: 1,
      total: 4,
      ended: false,
    })
    const wrapper = mountWithEdge(controller)
    await wrapper.vm.$nextTick()

    const cells = wrapper.findAll('[data-id="hilos-table-progress-row-a"] td')
    const span = cells.reduce(
      (total, cell) => total + Number(cell.attributes('colspan') ?? 1),
      0,
    )
    expect(span).toBe(COLUMNS.length + 1)
    expect(cells[0]?.attributes('colspan')).toBe('1')
  })
})

describe('HilosViewportTable drawing a row as a card', () => {
  // One column of each place a card has, plus one the page keeps out of it: the
  // whole projection read back through the markup the view writes.
  const CARD_COLUMNS: HilosTableColumn[] = [
    { key: 'name', label: 'Name' },
    { key: 'kind', label: 'Kind', cellClass: 'text-end' },
    { key: 'state', label: 'State', card: 'badge' },
    { key: 'secret', label: 'Secret', card: 'hidden' },
    { key: 'actions', label: '' },
  ]
  const CARD_FRAME: HilosTableFrame = {
    title: 'Backups',
    columns: CARD_COLUMNS,
  }

  /** The same table on a page that also declared an operation over marked rows. */
  const BULK_CARD_FRAME: HilosTableFrame = {
    ...CARD_FRAME,
    bulkActions: [
      { key: 'delete', label: 'Delete', danger: true, run: neverRun },
    ],
  }

  const CARD_SLOTS = {
    'cell-name': (props: { row: unknown }) =>
      h('span', { class: 'named' }, (props.row as Row).name),
    'cell-kind': () => h('span', { class: 'kind' }, 'full'),
    'cell-state': () => h('span', { class: 'state-badge' }, 'ready'),
    'cell-secret': () => h('span', { class: 'secret' }, '1.2 GB'),
    'cell-actions': () => h('button', { class: 'restore' }, 'Restore'),
  }

  function window(controller: TableViewportController<unknown>): void {
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
  }

  function mountCards(
    controller: TableViewportController<unknown>,
    edge?: HilosTableSelectionEdge,
  ) {
    return mount(HilosViewportTable, {
      props: { controller, columns: CARD_COLUMNS },
      slots: CARD_SLOTS,
      global:
        edge === undefined
          ? {}
          : { provide: { [hilosTableSelectionEdgeKey as symbol]: edge } },
    })
  }

  it('stands the cards beside the table and shows exactly one of the two', () => {
    const { controller } = makeController(CARD_FRAME)
    window(controller)
    const wrapper = mountCards(controller)

    const cards = wrapper.find('[data-id="hilos-table-cards"]')
    expect(cards.classes()).toContain('d-md-none')
    expect(cards.findAll('[data-id^="hilos-table-card-"]')).toHaveLength(2)

    const wide = wrapper.find('.table-responsive')
    expect(wide.classes()).toContain('d-none')
    expect(wide.classes()).toContain('d-md-block')
    // Nothing to scroll sideways once the columns became lines of a card.
    expect(cards.findAll('.table-responsive')).toHaveLength(0)
  })

  it('draws no cards and keeps the table at every width without a declaration', () => {
    const { controller } = makeController()
    window(controller)
    const wrapper = mountTable(controller)

    expect(wrapper.find('[data-id="hilos-table-cards"]').exists()).toBe(false)
    expect(wrapper.find('.table-responsive').classes()).not.toContain('d-none')
  })

  it('lays the card out the way the core projected it', () => {
    const { controller } = makeController(CARD_FRAME)
    window(controller)
    const card = mountCards(controller).find('[data-id="hilos-table-card-a"]')

    expect(card.find('.named').text()).toBe('Alice')
    // The title and the badge are drawn bare; only fields carry a label.
    expect(card.text()).not.toContain('Name')
    expect(card.find('.state-badge').exists()).toBe(true)
    expect(card.text()).not.toContain('State')
    expect(card.findAll('dt').map((label) => label.text())).toEqual(['Kind'])
    expect(card.find('dd .kind').exists()).toBe(true)
    expect(card.find('.hilos-button-row .restore').exists()).toBe(true)
    // A column the page kept out of the card is nowhere in it, though it still
    // stands in the row.
    expect(card.find('.secret').exists()).toBe(false)
    expect(
      mountCards(controller)
        .find('[data-id="hilos-table-row-a"] .secret')
        .exists(),
    ).toBe(true)
  })

  it('fills a cell of the row and a line of the card from one slot', () => {
    const { controller } = makeController(CARD_FRAME)
    window(controller)
    const wrapper = mountCards(controller)

    expect(wrapper.find('[data-id="hilos-table-row-a"] .kind').exists()).toBe(
      true,
    )
    expect(wrapper.find('[data-id="hilos-table-card-a"] .kind').exists()).toBe(
      true,
    )
  })

  it('leaves out the card line of a column the page drew nothing into', () => {
    const { controller } = makeController(CARD_FRAME)
    window(controller)
    const wrapper = mount(HilosViewportTable, {
      props: { controller, columns: CARD_COLUMNS },
      slots: { 'cell-name': () => h('span', { class: 'named' }, 'Alice') },
    })

    // The row keeps every cell, or it comes out narrower than its header; the
    // card keeps no label with nothing under it.
    expect(wrapper.findAll('[data-id="hilos-table-row-a"] td')).toHaveLength(
      CARD_COLUMNS.length,
    )
    expect(wrapper.findAll('[data-id="hilos-table-card-a"] dt')).toHaveLength(0)
    expect(
      wrapper.find('[data-id="hilos-table-card-a"] .hilos-button-row').exists(),
    ).toBe(false)
  })

  it('puts the declared cell class on the row cell and not on the card line', () => {
    const { controller } = makeController(CARD_FRAME)
    window(controller)
    const wrapper = mountCards(controller)

    const cells = wrapper.findAll('[data-id="hilos-table-row-a"] td')
    expect(cells[1]?.classes()).toContain('text-end')
    expect(
      wrapper.find('[data-id="hilos-table-card-a"] dd').classes(),
    ).not.toContain('text-end')
  })

  it('wraps actions cell content in an inline-flex gap-1 container on wide rows', () => {
    const { controller } = makeController(CARD_FRAME)
    window(controller)
    const wrapper = mountCards(controller)

    const row = wrapper.find('[data-id="hilos-table-row-a"]')
    const cells = row.findAll('td')
    const actionsCell = cells[CARD_COLUMNS.length - 1]
    const wrapperSpan = actionsCell?.find(
      'span.d-inline-flex.align-items-center.gap-1',
    )
    expect(wrapperSpan?.exists()).toBe(true)
    expect(wrapperSpan?.find('.restore').exists()).toBe(true)

    const nameCell = cells[0]
    expect(nameCell?.find('span.d-inline-flex').exists()).toBe(false)
  })

  it('draws every placeholder reason in the row and card', async () => {
    for (const [reason, text, icon] of [
      ['deleted', 'Removed', 'bi-dash-circle'],
      ['moved_out', 'Moved to another page', 'bi-arrows-move'],
      ['left_set', 'No longer in this list', 'bi-box-arrow-right'],
    ] as const) {
      const { controller } = makeController(CARD_FRAME)
      window(controller)
      controller.ingestDelta({ kind: 'row_removed', rowKey: 'a', reason })
      controller.apply()
      const wrapper = mountCards(controller)
      await wrapper.vm.$nextTick()

      const row = wrapper.find(
        '[data-id="hilos-table-row-a"] [data-id="hilos-table-placeholder"]',
      )
      const card = wrapper.find(
        '[data-id="hilos-table-card-a"] [data-id="hilos-table-placeholder"]',
      )
      expect(row.text()).toBe(text)
      expect(row.find('i').classes()).toContain(icon)
      expect(card.text()).toBe(text)
      expect(card.find('i').classes()).toContain(icon)
      expect(wrapper.findAll('[data-id^="hilos-table-card-"]')).toHaveLength(2)
      wrapper.unmount()
    }
  })

  it('draws the empty tile instead of placeholders after convergence', () => {
    const { controller } = makeController(CARD_FRAME)
    window(controller)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'b',
      reason: 'left_set',
    })
    controller.ingestCount(0, true)
    controller.apply()
    const wrapper = mountCards(controller)

    expect(wrapper.findAll('[data-id="hilos-table-placeholder"]')).toHaveLength(
      0,
    )
    expect(wrapper.findAll('[data-id="hilos-table-empty"]')).toHaveLength(2)
  })

  it('tints a card amber while a change waits and green after one landed', () => {
    const { controller } = makeController(CARD_FRAME)
    window(controller)
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'Alicia' } },
    })
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'b',
      row: { rowKey: 'b', slots: { name: 'Bobby' } },
    })
    const wrapper = mountCards(controller)

    expect(wrapper.find('[data-id="hilos-table-card-a"]').classes()).toContain(
      'border-warning',
    )
    expect(wrapper.find('[data-id="hilos-table-card-b"]').classes()).toContain(
      'border-success',
    )
  })

  it('lets the waiting outrank the highlight on a card that is both', () => {
    const { controller } = makeController(CARD_FRAME)
    window(controller)
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'Alicia' } },
    })
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'Alexandra' } },
    })
    const card = mountCards(controller).find('[data-id="hilos-table-card-a"]')

    expect(card.classes()).toContain('border-warning')
    expect(card.classes()).not.toContain('border-success')
  })

  it('stands the framework marks beside the page badge, not instead of it', async () => {
    const { controller } = makeController(CARD_FRAME)
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' }, staleSources: ['sizes'] }],
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
    const wrapper = mountCards(controller)
    await wrapper.vm.$nextTick()

    const card = wrapper.find('[data-id="hilos-table-card-a"]')
    expect(card.find('.state-badge').exists()).toBe(true)
    expect(card.find('[data-id="hilos-table-stale-row-a"]').exists()).toBe(true)
    expect(card.find('[data-id="hilos-table-pending-move-a"]').exists()).toBe(
      true,
    )
  })

  it('puts the bar of a running job at the foot of the card', async () => {
    const { controller } = makeController(CARD_FRAME)
    window(controller)
    controller.ingestProgress({
      scope: 'row',
      progressKey: 'pack-a',
      rowKey: 'a',
      current: 34,
      total: 110,
    })
    const wrapper = mountCards(controller)
    await wrapper.vm.$nextTick()

    const card = wrapper.find('[data-id="hilos-table-card-a"]')
    expect(card.find('[data-id="hilos-table-progress-row-a"]').exists()).toBe(
      true,
    )
    expect(card.find('[role="progressbar"]').exists()).toBe(true)

    // A row shown as a placeholder gets no bar, on a card as in a row.
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    await wrapper.vm.$nextTick()

    expect(
      wrapper
        .find(
          '[data-id="hilos-table-card-a"] [data-id="hilos-table-progress-row-a"]',
        )
        .exists(),
    ).toBe(false)
  })

  it('says loading and then the page own empty words in both branches', async () => {
    const { controller } = makeController(CARD_FRAME)
    const wrapper = mount(HilosViewportTable, {
      props: { controller, columns: CARD_COLUMNS },
      slots: {
        ...CARD_SLOTS,
        empty: () => h('span', { class: 'none-yet' }, 'No backups yet'),
      },
    })

    expect(wrapper.findAll('[data-id="hilos-table-loading"]')).toHaveLength(2)

    controller.ingestWindow([], 0, true, null, null, 10)
    await wrapper.vm.$nextTick()

    expect(wrapper.findAll('[data-id="hilos-table-loading"]')).toHaveLength(0)
    expect(wrapper.findAll('.none-yet')).toHaveLength(2)
  })

  it('names the list of cards with the heading the table is named by', () => {
    const { controller } = makeController(CARD_FRAME)
    window(controller)
    const wrapper = mountCards(controller)

    const list = wrapper.find('[data-id="hilos-table-cards"] [role="list"]')
    const titleId = wrapper
      .find('[data-id="hilos-table-title"]')
      .attributes('id')
    expect(list.attributes('aria-labelledby')).toBe(titleId)
    expect(wrapper.find('table').attributes('aria-labelledby')).toBe(titleId)
    expect(
      wrapper.find('[data-id="hilos-table-card-a"]').attributes('role'),
    ).toBe('listitem')
    // The list owns cards and nothing else.
    expect(list.element.children).toHaveLength(2)
  })

  it('keeps the words of an empty table beside the list and not inside it', async () => {
    const { controller } = makeController(CARD_FRAME)
    const wrapper = mountCards(controller)

    expect(
      wrapper
        .find(
          '[data-id="hilos-table-cards"] [role="list"] [data-id="hilos-table-loading"]',
        )
        .exists(),
    ).toBe(false)

    controller.ingestWindow([], 0, true, null, null, 10)
    await wrapper.vm.$nextTick()

    expect(
      wrapper.find('[data-id="hilos-table-cards"] [role="list"]').exists(),
    ).toBe(false)
  })

  it('carries the row mark in the head of the card, on the edge the app chose', async () => {
    const { controller } = makeController(BULK_CARD_FRAME)
    window(controller)
    const left = mountCards(controller)

    const box = left.find(
      '[data-id="hilos-table-card-a"] [data-id="hilos-table-select-a"]',
    )
    expect(box.exists()).toBe(true)
    // On the left edge the mark stands before the title, not in the group of
    // marks pushed to the right.
    expect(
      left.find('[data-id="hilos-table-card-a"] .ms-auto input').exists(),
    ).toBe(false)

    await box.setValue(true)
    expect(controller.selection.count.get()).toBe(1)

    const right = mountCards(controller, 'end')
    expect(
      right
        .find('[data-id="hilos-table-card-a"] .ms-auto input')
        .attributes('data-id'),
    ).toBe('hilos-table-select-a')
  })

  it('gives a card shown as a placeholder no mark to make', async () => {
    const { controller } = makeController(BULK_CARD_FRAME)
    window(controller)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    const wrapper = mountCards(controller)
    await wrapper.vm.$nextTick()

    expect(
      wrapper
        .find('[data-id="hilos-table-card-a"] [data-id="hilos-table-select-a"]')
        .exists(),
    ).toBe(false)
    expect(
      wrapper
        .find('[data-id="hilos-table-card-b"] [data-id="hilos-table-select-b"]')
        .exists(),
    ).toBe(true)
  })
})

describe('HilosViewportTable expanding a card', () => {
  // The same table as next door with one field that did not fit a column: on a
  // narrow screen it is what the card opens into.
  const CARD_DETAIL_COLUMNS: HilosTableColumn[] = [
    { key: 'name', label: 'Name' },
    { key: 'kind', label: 'Kind' },
    { key: 'lastError', label: 'Error', detail: true },
    { key: 'actions', label: '' },
  ]
  const CARD_DETAIL_FRAME: HilosTableFrame = {
    title: 'Backups',
    columns: CARD_DETAIL_COLUMNS,
  }

  function mountDetailCards(controller: TableViewportController<unknown>) {
    return mount(HilosViewportTable, {
      props: { controller, columns: CARD_DETAIL_COLUMNS },
      slots: {
        'cell-name': () => h('span', { class: 'named' }, 'Alice'),
        'cell-kind': () => h('span', { class: 'kind' }, 'full'),
        'cell-actions': () => h('button', { class: 'restore' }, 'Restore'),
        'detail-lastError': () =>
          h('span', { class: 'reason' }, 'Mailbox full'),
      },
    })
  }

  function window(controller: TableViewportController<unknown>): void {
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
  }

  it('opens one card from the control in its head and closes it again', async () => {
    const { controller } = makeController(CARD_DETAIL_FRAME)
    window(controller)
    const wrapper = mountDetailCards(controller)
    const control = wrapper.find(
      '[data-id="hilos-table-card-a"] [data-id="hilos-table-expand-a"]',
    )

    expect(control.attributes('aria-expanded')).toBe('false')
    expect(control.text()).toBe('Show details')
    expect(
      wrapper
        .find(
          '[data-id="hilos-table-card-a"] [data-id="hilos-table-row-detail-a"]',
        )
        .exists(),
    ).toBe(false)

    await control.trigger('click')

    const panel = wrapper.find(
      '[data-id="hilos-table-card-a"] [data-id="hilos-table-row-detail-a"]',
    )
    expect(panel.text()).toContain('Error')
    expect(panel.find('.reason').text()).toBe('Mailbox full')
    expect(
      wrapper
        .find(
          '[data-id="hilos-table-card-b"] [data-id="hilos-table-row-detail-b"]',
        )
        .exists(),
    ).toBe(false)

    await wrapper
      .find('[data-id="hilos-table-card-a"] [data-id="hilos-table-expand-a"]')
      .trigger('click')

    expect(
      wrapper
        .find(
          '[data-id="hilos-table-card-a"] [data-id="hilos-table-row-detail-a"]',
        )
        .exists(),
    ).toBe(false)
  })

  it('stands the panel after the fields and before the controls', async () => {
    const { controller } = makeController(CARD_DETAIL_FRAME)
    window(controller)
    const wrapper = mountDetailCards(controller)
    await wrapper
      .find('[data-id="hilos-table-card-a"] [data-id="hilos-table-expand-a"]')
      .trigger('click')

    const blocks = wrapper.findAll(
      '[data-id="hilos-table-card-a"] .card-body > *',
    )
    expect(blocks[1]?.element.tagName).toBe('DL')
    expect(blocks[2]?.attributes('data-id')).toBe('hilos-table-row-detail-a')
    expect(blocks[3]?.classes()).toContain('hilos-button-row')
  })

  it('gives the card panel an id of its own, apart from the row panel', async () => {
    const { controller } = makeController(CARD_DETAIL_FRAME)
    window(controller)
    const wrapper = mountDetailCards(controller)
    await wrapper
      .find('[data-id="hilos-table-card-a"] [data-id="hilos-table-expand-a"]')
      .trigger('click')

    const rowPanel = wrapper.find('tr[data-id="hilos-table-row-detail-a"]')
    const cardControl = wrapper.find(
      '[data-id="hilos-table-card-a"] [data-id="hilos-table-expand-a"]',
    )
    const cardPanel = wrapper.find(
      '[data-id="hilos-table-card-a"] [data-id="hilos-table-row-detail-a"]',
    )

    expect(cardPanel.attributes('id')).toBeTruthy()
    expect(cardPanel.attributes('id')).not.toBe(rowPanel.attributes('id'))
    expect(cardControl.attributes('aria-controls')).toBe(
      cardPanel.attributes('id'),
    )
    expect(cardControl.attributes('aria-expanded')).toBe('true')
  })

  it('offers no control on a card of a table that declared no such field', () => {
    const { controller } = makeController({
      title: 'Backups',
      columns: [
        { key: 'name', label: 'Name' },
        { key: 'actions', label: '' },
      ],
    })
    window(controller)
    const wrapper = mount(HilosViewportTable, {
      props: {
        controller,
        columns: [
          { key: 'name', label: 'Name' },
          { key: 'actions', label: '' },
        ],
      },
      slots: { 'cell-name': () => h('span', { class: 'named' }, 'Alice') },
    })

    expect(
      wrapper
        .find('[data-id="hilos-table-card-a"] [data-id="hilos-table-expand-a"]')
        .exists(),
    ).toBe(false)
  })

  it('stands a dash in a card field the page drew nothing into', async () => {
    const { controller } = makeController(CARD_DETAIL_FRAME)
    window(controller)
    const wrapper = mount(HilosViewportTable, {
      props: { controller, columns: CARD_DETAIL_COLUMNS },
      slots: { 'cell-name': () => h('span', { class: 'named' }, 'Alice') },
    })
    await wrapper
      .find('[data-id="hilos-table-card-a"] [data-id="hilos-table-expand-a"]')
      .trigger('click')

    expect(
      wrapper
        .find(
          '[data-id="hilos-table-card-a"] [data-id="hilos-table-row-detail-a"] dd',
        )
        .text(),
    ).toBe('—')
  })
})

describe('HilosViewportTable drawing work in progress', () => {
  // A window the bars are read against: a real one, so the row a bar belongs to
  // is on screen and the placeholder condition is the real one.
  function window(controller: TableViewportController<unknown>): void {
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
  }

  it('puts a bar above the table while work runs on the set, and takes it away with the end', async () => {
    const { controller } = makeController()
    window(controller)
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 34,
      total: 120,
    })
    const wrapper = mountTable(controller)

    const block = wrapper.get('[data-id="hilos-table-progress"]')
    expect(block.get('[role="progressbar"]').attributes('aria-valuenow')).toBe(
      '28',
    )

    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 120,
      total: 120,
      ended: true,
    })
    await wrapper.vm.$nextTick()

    // Nothing is left behind — not a finished bar, not an empty block.
    expect(wrapper.find('[data-id="hilos-table-progress"]').exists()).toBe(
      false,
    )
  })

  it('yields the line to new rows and stays on it as an icon', async () => {
    const { controller } = makeController()
    window(controller)
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 34,
      total: 120,
    })
    controller.ingestAnnounce('c', 'above', 3, true)
    const wrapper = mountTable(controller)

    // Running work is the junior message: while new rows wait to be shown, its own
    // bar is not on screen and only its icon is (Flow F4/F5).
    expect(wrapper.find('[data-id="hilos-table-progress"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-table-announce"]').exists()).toBe(true)
    expect(
      wrapper
        .find('[data-id="hilos-table-live-rest"] .bi-arrow-repeat')
        .exists(),
    ).toBe(true)

    await wrapper.get('[data-id="hilos-table-announce-show"]').trigger('click')
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      3,
      true,
      null,
      null,
      10,
    )
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-id="hilos-table-progress"]').exists()).toBe(true)
  })

  it('hands the whole bar, detail and all, to the slots beside the track', () => {
    const { controller } = makeController()
    window(controller)
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 34,
      total: 120,
      detail: { title: 'Nightly check' },
    })
    const wrapper = mount(HilosViewportTable, {
      props: { controller, columns: COLUMNS },
      slots: {
        row: (props: { row: unknown; rowKey: string }) =>
          h('td', {}, (props.row as Row).name),
        'table-progress': (props: { progress: HilosTableProgress }) =>
          h(
            'span',
            { 'data-id': 'page-progress-title' },
            `${String(props.progress.detail['title'])} — ${props.progress.current} of ${props.progress.total}`,
          ),
        'table-progress-action': (props: { progress: HilosTableProgress }) =>
          h(
            'button',
            { 'data-id': 'page-progress-stop' },
            `Stop ${props.progress.progressKey}`,
          ),
      },
    })

    expect(wrapper.get('[data-id="page-progress-title"]').text()).toBe(
      'Nightly check — 34 of 120',
    )
    expect(wrapper.get('[data-id="page-progress-stop"]').text()).toBe(
      'Stop nightly',
    )
  })

  it('draws a bulk frame in the live room, not under table progress', () => {
    const { controller } = makeController()
    window(controller)
    controller.ingestProgress({
      scope: 'bulk',
      progressKey: 'delete',
      current: 12,
      total: 40,
    })
    const wrapper = mountTable(controller)

    expect(wrapper.find('[data-id="hilos-table-progress"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-table-progress-bulk"]').exists()).toBe(
      true,
    )
  })
})

describe('HilosViewportTable drawing work over one row', () => {
  // Columns that name where the bar goes: under the two content columns and not
  // under the actions one, the way the mockup draws it.
  const MARKED_COLUMNS: HilosTableColumn[] = [
    { key: 'mark', label: '' },
    { key: 'name', label: 'Name', progress: true },
    { key: 'kind', label: 'Kind', progress: true },
    { key: 'actions', label: '' },
  ]

  function window(controller: TableViewportController<unknown>): void {
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
  }

  function rowBar(
    controller: TableViewportController<unknown>,
    rowKey: string,
  ): void {
    controller.ingestProgress({
      scope: 'row',
      progressKey: `pack-${rowKey}`,
      rowKey,
      current: 34,
      total: 110,
      detail: { note: 'packed 3.4 GB of 11 GB' },
    })
  }

  it('draws the bar in a row of its own right after the row it belongs to', () => {
    const { controller } = makeController()
    window(controller)
    rowBar(controller, 'a')
    const wrapper = mountTable(controller)

    const ids = wrapper
      .findAll('tbody tr')
      .map((node) => node.attributes('data-id'))
    expect(ids).toEqual([
      'hilos-table-row-a',
      'hilos-table-progress-row-a',
      'hilos-table-row-b',
    ])
  })

  it('stretches the bar under the marked columns and leaves the rest empty', () => {
    const { controller } = makeController()
    window(controller)
    rowBar(controller, 'a')
    const wrapper = mountTable(controller, false, MARKED_COLUMNS)

    const cells = wrapper
      .get('[data-id="hilos-table-progress-row-a"]')
      .findAll('td')
    expect(cells.map((cell) => cell.attributes('colspan'))).toEqual([
      '1',
      '2',
      '1',
    ])
    expect(cells[1]?.find('[role="progressbar"]').exists()).toBe(true)
    expect(cells[0]?.find('[role="progressbar"]').exists()).toBe(false)
    expect(cells[2]?.find('[role="progressbar"]').exists()).toBe(false)
  })

  it('stretches the bar across the whole row when no column is marked', () => {
    const { controller } = makeController()
    window(controller)
    rowBar(controller, 'a')
    const wrapper = mountTable(controller)

    const cells = wrapper
      .get('[data-id="hilos-table-progress-row-a"]')
      .findAll('td')
    expect(cells).toHaveLength(1)
    expect(cells[0]?.attributes('colspan')).toBe('1')
    expect(cells[0]?.find('[role="progressbar"]').exists()).toBe(true)
  })

  it('adds the waiting cell to the bar row exactly as the header does', async () => {
    const { controller } = makeController()
    window(controller)
    rowBar(controller, 'a')
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'b',
      reason: 'deleted',
    })
    const wrapper = mountTable(controller, false, MARKED_COLUMNS)
    await wrapper.vm.$nextTick()

    const spans = wrapper
      .get('[data-id="hilos-table-progress-row-a"]')
      .findAll('td')
      .map((cell) => Number(cell.attributes('colspan')))
    // The header grew by the waiting column, and the bar row grew with it: the
    // two are one sum, so the row can never be wider than its header.
    expect(spans.reduce((total, span) => total + span, 0)).toBe(
      wrapper.findAll('thead th').length,
    )
    expect(spans).toEqual([1, 2, 1, 1])
  })

  it('hands the whole bar to the row-progress slot', () => {
    const { controller } = makeController()
    window(controller)
    rowBar(controller, 'a')
    const wrapper = mount(HilosViewportTable, {
      props: { controller, columns: COLUMNS },
      slots: {
        row: (props: { row: unknown; rowKey: string }) =>
          h('td', {}, (props.row as Row).name),
        'row-progress': (props: {
          progress: HilosTableProgress
          rowKey: string
        }) =>
          h(
            'span',
            { 'data-id': `page-row-note-${props.rowKey}` },
            String(props.progress.detail['note']),
          ),
      },
    })

    expect(wrapper.get('[data-id="page-row-note-a"]').text()).toBe(
      'packed 3.4 GB of 11 GB',
    )
  })

  it('leaves no caption line under a row where the page filled no slot', () => {
    const { controller } = makeController()
    window(controller)
    rowBar(controller, 'a')
    const wrapper = mountTable(controller)

    const row = wrapper.get('[data-id="hilos-table-progress-row-a"]')
    expect(row.findAll('div.small')).toHaveLength(0)
    expect(row.find('[role="progressbar"]').exists()).toBe(true)
  })

  it('draws no bar for a key outside the current window', () => {
    const { controller } = makeController()
    window(controller)
    rowBar(controller, 'z')
    const wrapper = mountTable(controller)

    expect(
      wrapper.findAll('[data-id^="hilos-table-progress-row-"]'),
    ).toHaveLength(0)
  })

  it('draws no bar under a row shown as a placeholder', () => {
    const { controller } = makeController()
    window(controller)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    // The bar arrives AFTER the row became a placeholder, which is the one order
    // in which the core cannot have taken it down already: a removal drops the
    // bar at once, so this race is what the view's own condition is there for.
    rowBar(controller, 'a')
    const wrapper = mountTable(controller)

    expect(wrapper.find('[data-id="hilos-table-placeholder"]').exists()).toBe(
      true,
    )
    expect(
      wrapper.find('[data-id="hilos-table-progress-row-a"]').exists(),
    ).toBe(false)
  })
})

describe('HilosViewportTable marking a source that went quiet', () => {
  // A table assembled from two sources: the name comes from the row's own record,
  // the presence from a second slot — and a slot is what can go quiet on its own.
  const SOURCED_COLUMNS: HilosTableColumn[] = [
    { key: 'name', label: 'Name', sortable: true },
    {
      key: 'presence',
      label: 'Presence',
      sortable: true,
      source: 'connections',
    },
  ]

  function window(
    controller: TableViewportController<unknown>,
    staleSources: readonly string[] = [],
  ): void {
    controller.ingestWindow(
      [
        { rowKey: 'a', slots: { name: 'Alice' }, staleSources },
        { rowKey: 'b', slots: { name: 'Bob' } },
      ],
      2,
      true,
      null,
      null,
      10,
    )
  }

  it('raises the strip and names the columns built from the quiet source', () => {
    const { controller } = makeController()
    window(controller, ['connections'])
    const wrapper = mountTable(controller, false, SOURCED_COLUMNS)

    expect(wrapper.find('[data-id="hilos-table-stale"]').text()).toBe(
      'Presence is not updating: the link to its source was lost. The other columns are live.',
    )
  })

  it('raises the strip in the generic wording when no column named the source', () => {
    const { controller } = makeController()
    window(controller, ['connections'])
    // COLUMNS declares no source at all, so there is nothing to name — and saying
    // nothing would leave yesterday's value looking like today's.
    const wrapper = mountTable(controller)

    expect(wrapper.find('[data-id="hilos-table-stale"]').text()).toBe(
      'Some values here are not updating: the link to their source was lost. The other columns are live.',
    )
  })

  it('keeps the sort control on a quiet column and carries the warning inside it', () => {
    const { controller } = makeController()
    window(controller, ['connections'])
    const wrapper = mountTable(controller, false, SOURCED_COLUMNS)

    const button = wrapper.find('[data-id="hilos-table-sort-presence"]')
    expect(button.exists()).toBe(true)
    expect(wrapper.find('[data-id="hilos-table-sort-name"]').exists()).toBe(
      true,
    )
    expect(
      button.find('[data-id="hilos-table-stale-column-presence"]').exists(),
    ).toBe(true)
    expect(wrapper.findAll('thead th')[1]?.text()).toContain(
      'Sorting by this column may be wrong',
    )
  })

  it('sends a viewport when the name of a quiet column is clicked', async () => {
    const { controller, sent } = makeController()
    window(controller, ['connections'])
    const wrapper = mountTable(controller, false, SOURCED_COLUMNS)
    sent.length = 0
    await wrapper.find('[data-id="hilos-table-sort-presence"]').trigger('click')

    expect(sent).toHaveLength(1)
  })

  it('numbers headers of a composite order whose first column is quiet and speaks both places', async () => {
    const { controller } = makeController()
    window(controller, ['connections'])
    controller.setOrder([
      { field: 'presence', direction: 'asc' },
      { field: 'name', direction: 'desc' },
    ])
    const wrapper = mountTable(controller, false, SOURCED_COLUMNS)
    await wrapper.vm.$nextTick()

    expect(wrapper.findAll('th sup').map((mark) => mark.text())).toEqual([
      '2',
      '1',
    ])
    expect(
      wrapper.findAll('th sup + .visually-hidden').map((mark) => mark.text()),
    ).toEqual(['Sort column 2 of 2', 'Sort column 1 of 2'])
  })

  it('keeps the standing order over a column that went quiet readable', async () => {
    const { controller } = makeController()
    window(controller, [])
    const wrapper = mountTable(controller, false, SOURCED_COLUMNS)
    controller.setSort('presence')
    await wrapper.vm.$nextTick()
    controller.ingestDelta({
      kind: 'row_stale',
      rowKey: 'a',
      staleSources: ['connections'],
    })
    await wrapper.vm.$nextTick()

    // The rows lie in that order right now, and saying so is the truth; only
    // choosing or reversing it is gone.
    const header = wrapper.findAll('thead th')[1]
    expect(header?.attributes('aria-sort')).toBe('ascending')
    expect(header?.find('.bi-arrow-up').exists()).toBe(true)
  })

  it('marks exactly the rows whose own values are behind', () => {
    const { controller } = makeController()
    window(controller, ['connections'])
    const wrapper = mountTable(controller, false, SOURCED_COLUMNS)

    expect(wrapper.find('[data-id="hilos-table-stale-row-a"]').exists()).toBe(
      true,
    )
    expect(wrapper.find('[data-id="hilos-table-stale-row-b"]').exists()).toBe(
      false,
    )
  })

  it('stands the mark cell up on a quiet source with nothing waiting at all', () => {
    const { controller } = makeController()
    window(controller, ['connections'])
    const wrapper = mountTable(controller, false, SOURCED_COLUMNS)

    expect(wrapper.findAll('thead th')).toHaveLength(SOURCED_COLUMNS.length + 1)
    expect(wrapper.find('thead th:last-child').text()).toBe(
      'Row state and controls',
    )
    // Header and body read one condition, so the cell the header just made room
    // for is the one standing last in the row.
    const cells = wrapper.findAll('[data-id="hilos-table-row-a"] td')
    expect(
      cells[cells.length - 1]
        ?.find('[data-id="hilos-table-stale-row-a"]')
        .exists(),
    ).toBe(true)
  })

  it('takes the strip, the snowflakes and the cell down when the source is current again', async () => {
    const { controller } = makeController()
    window(controller, ['connections'])
    const wrapper = mountTable(controller, false, SOURCED_COLUMNS)
    controller.ingestDelta({
      kind: 'row_stale',
      rowKey: 'a',
      staleSources: [],
    })
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-id="hilos-table-stale"]').exists()).toBe(false)
    expect(
      wrapper.find('[data-id="hilos-table-stale-column-presence"]').exists(),
    ).toBe(false)
    expect(wrapper.find('[data-id="hilos-table-stale-row-a"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-table-sort-presence"]').exists()).toBe(
      true,
    )
    expect(wrapper.findAll('thead th')).toHaveLength(SOURCED_COLUMNS.length)
  })
})

describe('HilosViewportTable expanding a row', () => {
  // A table with one field that did not fit a column of its own: the row shows the
  // name, and the reason waits in the panel under it.
  const DETAIL_COLUMNS: HilosTableColumn[] = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'lastError', label: 'Error', detail: true },
  ]

  function window(controller: TableViewportController<unknown>): void {
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
  }

  function mountDetailTable(
    controller: TableViewportController<unknown>,
    detailSlots: Record<string, unknown> = {
      'detail-lastError': () => h('span', { class: 'reason' }, 'Mailbox full'),
    },
  ) {
    return mount(HilosViewportTable, {
      props: { controller, columns: DETAIL_COLUMNS, searchable: true },
      slots: {
        row: (props: { row: unknown; rowKey: string }) =>
          h('td', { class: 'cell' }, (props.row as Row).name),
        ...detailSlots,
      },
    })
  }

  it('keeps a detail column out of the header and out of the width of a row', () => {
    const { controller } = makeController()
    window(controller)
    const wrapper = mountDetailTable(controller)

    // One declared column left standing, plus the row-state cell the control lives in.
    expect(wrapper.findAll('thead th')).toHaveLength(2)
    expect(wrapper.find('thead').text()).not.toContain('Error')
    expect(
      wrapper.find('[data-id="hilos-table-sort-lastError"]').exists(),
    ).toBe(false)
  })

  it('offers the control only where a detail field was declared', () => {
    const { controller } = makeController()
    window(controller)

    expect(
      mountDetailTable(controller)
        .find('[data-id="hilos-table-expand-a"]')
        .exists(),
    ).toBe(true)
    expect(
      mountTable(controller).find('[data-id="hilos-table-expand-a"]').exists(),
    ).toBe(false)
  })

  it('opens the panel of one row and closes it again from the same control', async () => {
    const { controller } = makeController()
    window(controller)
    const wrapper = mountDetailTable(controller)
    const control = wrapper.find('[data-id="hilos-table-expand-a"]')

    expect(control.attributes('aria-expanded')).toBe('false')
    expect(control.text()).toBe('Show details')
    expect(wrapper.find('[data-id="hilos-table-row-detail-a"]').exists()).toBe(
      false,
    )

    await control.trigger('click')

    const panel = wrapper.find('[data-id="hilos-table-row-detail-a"]')
    expect(panel.exists()).toBe(true)
    expect(panel.text()).toContain('Error')
    expect(panel.find('.reason').text()).toBe('Mailbox full')
    expect(wrapper.find('[data-id="hilos-table-expand-a"]').text()).toBe(
      'Hide details',
    )
    expect(wrapper.find('[data-id="hilos-table-row-detail-b"]').exists()).toBe(
      false,
    )

    await wrapper.find('[data-id="hilos-table-expand-a"]').trigger('click')

    expect(wrapper.find('[data-id="hilos-table-row-detail-a"]').exists()).toBe(
      false,
    )
  })

  it('points the control at its own panel and hides the chevron from the reader', async () => {
    const { controller } = makeController()
    window(controller)
    const wrapper = mountDetailTable(controller)
    await wrapper.find('[data-id="hilos-table-expand-a"]').trigger('click')

    const control = wrapper.find('[data-id="hilos-table-expand-a"]')
    const panel = wrapper.find('[data-id="hilos-table-row-detail-a"]')
    expect(control.attributes('aria-expanded')).toBe('true')
    expect(control.attributes('aria-controls')).toBe(panel.attributes('id'))
    expect(panel.attributes('id')).toBeTruthy()
    expect(control.find('i').attributes('aria-hidden')).toBe('true')
    expect(control.find('i').classes()).toContain('bi-chevron-up')
  })

  it('spans the panel across the whole row, tinted as the row it hangs under', async () => {
    const { controller } = makeController()
    window(controller)
    const wrapper = mountDetailTable(controller)
    await wrapper.find('[data-id="hilos-table-expand-a"]').trigger('click')

    const panel = wrapper.find('[data-id="hilos-table-row-detail-a"]')
    expect(panel.classes()).toContain('table-active')
    expect(panel.find('td').attributes('colspan')).toBe('2')
    expect(wrapper.find('[data-id="hilos-table-row-a"]').classes()).toContain(
      'table-active',
    )
  })

  it('stands a dash in a field the page declared but drew nothing into', async () => {
    const { controller } = makeController()
    window(controller)
    const wrapper = mountDetailTable(controller, {})
    await wrapper.find('[data-id="hilos-table-expand-a"]').trigger('click')

    expect(wrapper.find('[data-id="hilos-table-row-detail-a"] dd').text()).toBe(
      '—',
    )
  })

  it('closes every panel when the window changes', async () => {
    const { controller } = makeController()
    window(controller)
    const wrapper = mountDetailTable(controller)
    await wrapper.find('[data-id="hilos-table-expand-a"]').trigger('click')
    await wrapper.find('[data-id="hilos-table-expand-b"]').trigger('click')

    await wrapper.find('[data-id="hilos-table-search"]').setValue('failed')
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-id="hilos-table-row-detail-a"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-table-row-detail-b"]').exists()).toBe(
      false,
    )
  })

  it('hands a detail field the row and its key, as a cell is handed them', async () => {
    const { controller } = makeController()
    window(controller)
    const wrapper = mountDetailTable(controller, {
      'detail-lastError': (props: { row: unknown; rowKey: string }) =>
        h(
          'span',
          { class: 'reason' },
          `${(props.row as Row).name} ${props.rowKey}`,
        ),
    })
    await wrapper.find('[data-id="hilos-table-expand-b"]').trigger('click')

    expect(
      wrapper.find('[data-id="hilos-table-row-detail-b"] .reason').text(),
    ).toBe('Bob b')
  })
})

describe('HilosViewportTable drawing the states of the body', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  const STATE_COLUMNS: HilosTableColumn[] = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'kind', label: 'Kind' },
  ]
  const STATE_FRAME: HilosTableFrame = {
    title: 'Backups',
    search: {},
    columns: STATE_COLUMNS,
    empty: { title: 'Nothing here yet' },
  }
  const STATE_SLOTS = {
    'cell-name': (props: { row: unknown }) =>
      h('span', { class: 'named' }, (props.row as Row).name),
  }

  function mountStates(controller: TableViewportController<unknown>) {
    return mount(HilosViewportTable, {
      props: { controller, columns: STATE_COLUMNS },
      slots: STATE_SLOTS,
    })
  }

  function twoRows(controller: TableViewportController<unknown>): void {
    controller.ingestWindow(
      [
        { rowKey: 'a', slots: { name: 'Alice' } },
        { rowKey: 'b', slots: { name: 'Bob' } },
      ],
      2,
      true,
      null,
      null,
      25,
    )
  }

  it('keeps the rows through a quick change and draws a skeleton as tall as the window past the threshold', async () => {
    vi.useFakeTimers()
    const { controller } = makeController(STATE_FRAME)
    twoRows(controller)
    const wrapper = mountStates(controller)

    controller.setSort('name')
    vi.advanceTimersByTime(399)
    await wrapper.vm.$nextTick()
    expect(wrapper.findAll('[data-id="hilos-table-loading"]')).toHaveLength(0)
    expect(wrapper.findAll('.named')).toHaveLength(4)

    vi.advanceTimersByTime(1)
    await wrapper.vm.$nextTick()

    // Both branches say it, each in its own shape: rows of cells in the table,
    // one bar per card in the list.
    const loading = wrapper.findAll('[data-id="hilos-table-loading"]')
    expect(loading).toHaveLength(2)
    expect(loading[0]?.attributes('aria-busy')).toBe('true')
    const tableRows = wrapper.findAll(
      'tbody[data-id="hilos-table-loading"] [data-id="hilos-table-skeleton-row"]',
    )
    expect(tableRows).toHaveLength(2)
    // A cell for every column standing in the row, so the widths hold.
    expect(tableRows[0]?.findAll('td')).toHaveLength(2)
    expect(
      wrapper.findAll(
        '[data-id="hilos-table-cards"] [data-id="hilos-table-skeleton-row"]',
      ),
    ).toHaveLength(2)
    expect(wrapper.findAll('.placeholder[aria-hidden="true"]')).toHaveLength(
      2 * 2,
    )
    expect(
      wrapper
        .findAll('[role="status"]')
        .filter((node) => node.text() === 'Loading…'),
    ).toHaveLength(2)
    expect(wrapper.findAll('.named')).toHaveLength(0)
  })

  it('counts the skeleton by the window size when the window before was empty', async () => {
    vi.useFakeTimers()
    const { controller } = makeController(STATE_FRAME)
    controller.ingestWindow([], 0, true, null, null, 3)
    const wrapper = mountStates(controller)

    controller.setSearch('x')
    vi.advanceTimersByTime(400)
    await wrapper.vm.$nextTick()

    expect(
      wrapper.findAll(
        'tbody[data-id="hilos-table-loading"] [data-id="hilos-table-skeleton-row"]',
      ),
    ).toHaveLength(3)
  })

  it('says nothing was found in both branches and resets out of it', async () => {
    const { controller, sent } = makeController(STATE_FRAME)
    twoRows(controller)
    const wrapper = mountStates(controller)

    controller.setSearch('night')
    controller.ingestWindow([], 0, true, null, null, 25)
    await wrapper.vm.$nextTick()

    const states = wrapper.findAll('[data-id="hilos-table-no-matches"]')
    expect(states).toHaveLength(2)
    expect(
      wrapper.findAll('[data-id="hilos-table-no-matches-terms"]')[1]?.text(),
    ).toBe('No rows match “night”')
    expect(wrapper.find('[data-id="hilos-table-empty"]').exists()).toBe(false)

    await wrapper
      .findAll('[data-id="hilos-table-no-matches-reset"]')[1]
      ?.trigger('click')
    expect(sent.at(-1)?.filter).toEqual({})
  })

  it('draws the declared empty state in both branches when nothing filters the set', async () => {
    const { controller } = makeController(STATE_FRAME)
    const wrapper = mountStates(controller)

    controller.ingestWindow([], 0, true, null, null, 25)
    await wrapper.vm.$nextTick()

    expect(wrapper.findAll('[data-id="hilos-table-empty-title"]')).toHaveLength(
      2,
    )
    expect(wrapper.find('[data-id="hilos-table-no-matches"]').exists()).toBe(
      false,
    )
  })

  it('draws List unavailable in both branches when the window was refused, with no footer', async () => {
    const { controller } = makeController(STATE_FRAME)
    twoRows(controller)
    const wrapper = mountStates(controller)

    controller.ingestRefusal('internal_error')
    await wrapper.vm.$nextTick()

    const tiles = wrapper.findAll('[data-id="hilos-table-unavailable"]')
    expect(tiles).toHaveLength(2)
    expect(tiles[0]?.attributes('role')).toBe('status')
    expect(
      wrapper.find('[data-id="hilos-table-unavailable-title"]').text(),
    ).toBe('List unavailable')
    expect(
      wrapper.find('[data-id="hilos-table-unavailable-hint"]').text(),
    ).toBe(
      'The rows of this list could not be fetched. The rest of the page still works.',
    )
    expect(wrapper.find('[data-id="hilos-table-count"]').exists()).toBe(false)
  })

  it('says nothing was found on a table that still draws its frame from props', async () => {
    const { controller } = makeController()
    const wrapper = mountTable(controller, true)
    controller.ingestWindow([], 0, true, null, null, 10)
    controller.setSearch('night')
    controller.ingestWindow([], 0, true, null, null, 10)
    await wrapper.vm.$nextTick()

    expect(
      wrapper.find('[data-id="hilos-table-no-matches-terms"]').text(),
    ).toBe('No rows match “night”')
  })
})
