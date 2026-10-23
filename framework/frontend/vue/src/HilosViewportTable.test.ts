import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { h, nextTick, ref, shallowRef } from 'vue'
import {
  createSignal,
  HIDDEN_VALUE,
  registerFocusReturnFallback,
  TableViewportController,
} from '@hilos/core/base'
import type {
  ActionHandle,
  HilosTableBulkAccepted,
  HilosTableColumn,
  HilosTableFrame,
  HilosTableProgress,
  TableViewportDescriptor,
} from '@hilos/core/base'

import HilosModal from './HilosModal.vue'
import HilosTableText from './HilosTableText.vue'
import HilosTableValue from './HilosTableValue.vue'
import HilosViewportTable from './HilosViewportTable.vue'
import { hilosPageHeadingIdKey } from './hilosPageHeading.js'
import {
  hilosTableSelectionEdgeKey,
  type HilosTableSelectionEdge,
} from './hilosTableSelectionEdge.js'

// Every mount now owns DOM observers and listeners as well as signal mirrors.
// Release them before the test environment removes its document and globals.
enableAutoUnmount(afterEach)

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
function makeController(
  frame: HilosTableFrame = { title: 'Users', columns: COLUMNS },
): {
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

function mountTable(controller: TableViewportController<unknown>) {
  return mount(HilosViewportTable, {
    props: { controller },
    slots: {
      'cell-name': (props: { row: unknown; rowKey: string }) =>
        h('span', { class: 'cell' }, (props.row as Row).name),
    },
  })
}

describe('HilosViewportTable', () => {
  it('renders a row per window row through the cell slot', () => {
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
    const { controller } = makeController({ columns: TWO_COLUMNS })
    controller.setOrder([
      { field: 'name', direction: 'desc' },
      { field: 'id', direction: 'desc' },
    ])
    const wrapper = mountTable(controller)
    await wrapper.vm.$nextTick()

    // Sorted by both columns means an arrow on both: one arrow would name one of
    // the two as the whole answer.
    const arrows = wrapper.findAll('th button > i.bi')

    expect(arrows).toHaveLength(2)
    expect(arrows.every((arrow) => arrow.classes('bi-arrow-down'))).toBe(true)
  })

  it('numbers the columns of a composite order and says the place in words', async () => {
    const { controller } = makeController({ columns: TWO_COLUMNS })
    controller.setOrder([
      { field: 'name', direction: 'desc' },
      { field: 'id', direction: 'asc' },
    ])
    const wrapper = mountTable(controller)
    await wrapper.vm.$nextTick()

    expect(wrapper.findAll('th sup').map((mark) => mark.text())).toEqual([
      '1',
      '2',
    ])
    // aria-sort names a direction and cannot say "second by importance", so the
    // place is spoken beside the number instead.
    expect(
      wrapper.findAll('th .visually-hidden').map((said) => said.text()),
    ).toEqual([
      'Sort column 1 of 2',
      'Sort column 2 of 2',
      'Row state and controls',
    ])
  })

  it('numbers nothing under an order of one column, where the arrow says it all', async () => {
    const { controller } = makeController({ columns: TWO_COLUMNS })
    controller.setSort('name')
    const wrapper = mountTable(controller)
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

  it('keeps the permanent mark column with and without waiting', async () => {
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
    // An applied removal keeps both its full-span slot and the state column.
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    const wrapper = mountTable(controller)

    expect(wrapper.findAll('thead th')).toHaveLength(COLUMNS.length + 1)
    expect(
      wrapper.find('[data-id="hilos-table-placeholder"]').attributes('colspan'),
    ).toBe(String(COLUMNS.length + 1))

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

  it('names the table with its title and reports sort state to assistive tech', async () => {
    const { controller } = makeController({
      title: 'Users',
      columns: COLUMNS,
      search: {},
    })
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      true,
      null,
      null,
      10,
    )
    const wrapper = mount(HilosViewportTable, {
      props: { controller },
      slots: {
        'cell-name': (props: { row: unknown; rowKey: string }) =>
          h('span', {}, (props.row as Row).name),
      },
    })

    const title = wrapper.find('[data-id="hilos-table-title"]')
    expect(title.text()).toBe('Users')
    expect(wrapper.find('table').attributes('aria-labelledby')).toBe(
      title.attributes('id'),
    )
    expect(wrapper.find('caption').exists()).toBe(false)
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
      props: { controller },
      slots: {
        'cell-name': (props: { row: unknown; rowKey: string }) =>
          h('span', {}, (props.row as Row).name),
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

  it('names the table and cards by the page heading when no title is declared', () => {
    const { controller } = makeController({ columns: COLUMNS })
    controller.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      true,
      null,
      null,
      10,
    )
    const wrapper = mount(HilosViewportTable, {
      props: { controller },
      global: {
        provide: { [hilosPageHeadingIdKey as symbol]: 'page-heading' },
      },
    })

    expect(wrapper.find('table').attributes('aria-labelledby')).toBe(
      'page-heading',
    )
    expect(
      wrapper
        .find('[data-id="hilos-table-cards"] [role="list"]')
        .attributes('aria-labelledby'),
    ).toBe('page-heading')
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

    expect(controller.frame.footer.get().page).toBe(0)
    expect(wrapper.find('[data-id="hilos-table-count"]').text()).toBe(
      '2 – 2 of 21',
    )
    expect(wrapper.find('[aria-current="page"]').text()).toBe('1')
    expect(
      wrapper.find('[data-id="hilos-table-prev"]').attributes('disabled'),
    ).toBeUndefined()
  })

  it('draws the declared footer with the delivered row range', () => {
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

  it('draws one search bar and keeps Apply in the waiting strip', () => {
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

    // The declared search bar and the waiting strip each stand once.
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
      props: { controller },
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
    expect(cells).toHaveLength(CELL_COLUMNS.length + 1)
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
      props: { controller },
      slots: {},
    })

    const cells = wrapper.findAll('[data-id="hilos-table-row-a"] td')
    expect(cells).toHaveLength(wrapper.findAll('thead th').length)
    expect(cells[1]?.text()).toBe('')
  })

  it('uses the same declared columns for the header and cells', () => {
    const { controller } = makeController(CELL_FRAME)
    window(controller)
    const wrapper = mount(HilosViewportTable, {
      props: { controller },
      slots: {
        'cell-size': () => h('span', { class: 'sized' }, '1.2 GB'),
      },
    })

    expect(wrapper.findAll('thead th')).toHaveLength(CELL_COLUMNS.length + 1)
    expect(wrapper.find('thead').text()).toContain('Size')
    expect(wrapper.find('.sized').exists()).toBe(true)
  })
})

describe('HilosViewportTable with a selection column', () => {
  // The table of a page that declared one bulk operation — the one sign that it
  // has marks at all, and the whole reason the column stands.
  const BULK_FRAME: HilosTableFrame = {
    title: 'Backups',
    columns: COLUMNS,
    bulkActions: [
      {
        key: 'delete',
        label: 'Delete',
        refusalTitle: "Couldn't delete",
        danger: true,
        run: neverRun,
      },
    ],
  }

  /** The same table with nothing declared for marked rows. */
  const PLAIN_FRAME: HilosTableFrame = { title: 'Backups', columns: COLUMNS }

  function mountWithEdge(
    controller: TableViewportController<unknown>,
    edge?: HilosTableSelectionEdge,
  ) {
    return mount(HilosViewportTable, {
      props: { controller },
      slots: {
        'cell-name': (props: { row: unknown; rowKey: string }) =>
          h('span', {}, (props.row as Row).name),
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
    expect(wrapper.findAll('thead th')).toHaveLength(COLUMNS.length + 1)
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

    // Placeholder and empty state span declared, selection and permanent state columns.
    expect(
      wrapper.find('[data-id="hilos-table-placeholder"]').attributes('colspan'),
    ).toBe(String(COLUMNS.length + 2))

    const empty = makeController(BULK_FRAME)
    empty.controller.ingestWindow([], 0, true, null, null, 10)
    const emptyWrapper = mountWithEdge(empty.controller)
    expect(emptyWrapper.find('tbody td').attributes('colspan')).toBe(
      String(COLUMNS.length + 2),
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
    expect(span).toBe(COLUMNS.length + 2)
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
      {
        key: 'delete',
        label: 'Delete',
        refusalTitle: "Couldn't delete",
        danger: true,
        run: neverRun,
      },
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
      props: { controller },
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
      props: { controller },
      slots: { 'cell-name': () => h('span', { class: 'named' }, 'Alice') },
    })

    // The row keeps every cell, or it comes out narrower than its header; the
    // card keeps no label with nothing under it.
    expect(wrapper.findAll('[data-id="hilos-table-row-a"] td')).toHaveLength(
      CARD_COLUMNS.length + 1,
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

  it('retains placeholders without an empty tile when the count reaches zero', () => {
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
      4,
    )
    expect(wrapper.findAll('[data-id="hilos-table-empty"]')).toHaveLength(0)
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
      props: { controller },
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
        .find('[data-id="hilos-table-card-a"] .hilos-table-card-marks input')
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
      props: { controller },
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
      props: { controller },
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
      props: { controller },
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
      props: { controller },
      slots: {
        'cell-name': (props: { row: unknown; rowKey: string }) =>
          h('span', {}, (props.row as Row).name),
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
    const { controller } = makeController({ columns: MARKED_COLUMNS })
    window(controller)
    rowBar(controller, 'a')
    const wrapper = mountTable(controller)

    const cells = wrapper
      .get('[data-id="hilos-table-progress-row-a"]')
      .findAll('td')
    expect(cells.map((cell) => cell.attributes('colspan'))).toEqual([
      '1',
      '2',
      '1',
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
    expect(cells).toHaveLength(2)
    expect(cells[0]?.attributes('colspan')).toBe('1')
    expect(cells[0]?.find('[role="progressbar"]').exists()).toBe(true)
  })

  it('adds the waiting cell to the bar row exactly as the header does', async () => {
    const { controller } = makeController({ columns: MARKED_COLUMNS })
    window(controller)
    rowBar(controller, 'a')
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'b',
      reason: 'deleted',
    })
    const wrapper = mountTable(controller)
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
      props: { controller },
      slots: {
        'cell-name': (props: { row: unknown; rowKey: string }) =>
          h('span', {}, (props.row as Row).name),
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
    const { controller } = makeController({ columns: SOURCED_COLUMNS })
    window(controller, ['connections'])
    const wrapper = mountTable(controller)

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
    const { controller } = makeController({ columns: SOURCED_COLUMNS })
    window(controller, ['connections'])
    const wrapper = mountTable(controller)

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
    const { controller, sent } = makeController({ columns: SOURCED_COLUMNS })
    window(controller, ['connections'])
    const wrapper = mountTable(controller)
    sent.length = 0
    await wrapper.find('[data-id="hilos-table-sort-presence"]').trigger('click')

    expect(sent).toHaveLength(1)
  })

  it('numbers headers of a composite order whose first column is quiet and speaks both places', async () => {
    const { controller } = makeController({ columns: SOURCED_COLUMNS })
    window(controller, ['connections'])
    controller.setOrder([
      { field: 'presence', direction: 'asc' },
      { field: 'name', direction: 'desc' },
    ])
    const wrapper = mountTable(controller)
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
    const { controller } = makeController({ columns: SOURCED_COLUMNS })
    window(controller, [])
    const wrapper = mountTable(controller)
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
    const { controller } = makeController({ columns: SOURCED_COLUMNS })
    window(controller, ['connections'])
    const wrapper = mountTable(controller)

    expect(wrapper.find('[data-id="hilos-table-stale-row-a"]').exists()).toBe(
      true,
    )
    expect(wrapper.find('[data-id="hilos-table-stale-row-b"]').exists()).toBe(
      false,
    )
  })

  it('stands the mark cell up on a quiet source with nothing waiting at all', () => {
    const { controller } = makeController({ columns: SOURCED_COLUMNS })
    window(controller, ['connections'])
    const wrapper = mountTable(controller)

    expect(wrapper.findAll('thead th')).toHaveLength(SOURCED_COLUMNS.length + 1)
    expect(wrapper.find('thead th:last-child > .visually-hidden').text()).toBe(
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

  it('takes the strip and live snowflakes down while keeping the state cell', async () => {
    const { controller } = makeController({ columns: SOURCED_COLUMNS })
    window(controller, ['connections'])
    const wrapper = mountTable(controller)
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
    expect(wrapper.findAll('thead th')).toHaveLength(SOURCED_COLUMNS.length + 1)
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
      props: { controller },
      slots: {
        'cell-name': (props: { row: unknown; rowKey: string }) =>
          h('span', { class: 'cell' }, (props.row as Row).name),
        ...detailSlots,
      },
    })
  }

  it('keeps a detail column out of the header and out of the width of a row', () => {
    const { controller } = makeController({
      columns: DETAIL_COLUMNS,
      search: {},
    })
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
    const { controller } = makeController({
      columns: DETAIL_COLUMNS,
      search: {},
    })
    window(controller)

    expect(
      mountDetailTable(controller)
        .find('[data-id="hilos-table-expand-a"]')
        .exists(),
    ).toBe(true)
    const plain = makeController()
    window(plain.controller)
    expect(
      mountTable(plain.controller)
        .find('[data-id="hilos-table-expand-a"]')
        .exists(),
    ).toBe(false)
  })

  it('opens the panel of one row and closes it again from the same control', async () => {
    const { controller } = makeController({
      columns: DETAIL_COLUMNS,
      search: {},
    })
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
    const { controller } = makeController({
      columns: DETAIL_COLUMNS,
      search: {},
    })
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
    const { controller } = makeController({
      columns: DETAIL_COLUMNS,
      search: {},
    })
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
    const { controller } = makeController({
      columns: DETAIL_COLUMNS,
      search: {},
    })
    window(controller)
    const wrapper = mountDetailTable(controller, {})
    await wrapper.find('[data-id="hilos-table-expand-a"]').trigger('click')

    expect(wrapper.find('[data-id="hilos-table-row-detail-a"] dd').text()).toBe(
      '—',
    )
  })

  it('closes every panel when the window changes', async () => {
    const { controller } = makeController({
      columns: DETAIL_COLUMNS,
      search: {},
    })
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
    const { controller } = makeController({
      columns: DETAIL_COLUMNS,
      search: {},
    })
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
      props: { controller },
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
    expect(tableRows[0]?.findAll('td')).toHaveLength(3)
    expect(
      wrapper.findAll(
        '[data-id="hilos-table-cards"] [data-id="hilos-table-skeleton-row"]',
      ),
    ).toHaveLength(2)
    expect(wrapper.findAll('.placeholder[aria-hidden="true"]')).toHaveLength(
      3 * 2,
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

  it('says nothing was found when only search is declared', async () => {
    const { controller } = makeController({ columns: COLUMNS, search: {} })
    const wrapper = mountTable(controller)
    controller.ingestWindow([], 0, true, null, null, 10)
    controller.setSearch('night')
    controller.ingestWindow([], 0, true, null, null, 10)
    await wrapper.vm.$nextTick()

    expect(
      wrapper.find('[data-id="hilos-table-no-matches-terms"]').text(),
    ).toBe('No rows match “night”')
  })
})

describe('HilosViewportTable rows with nothing to expand and wide fields', () => {
  // A names table in miniature: Bob has no corrections, so his row has nothing to open,
  // and the corrections are a list that takes the panel's whole width.
  const WIDE_COLUMNS: HilosTableColumn[] = [
    { key: 'name', label: 'Name' },
    { key: 'lastError', label: 'Error', detail: true },
    {
      key: 'corrections',
      label: 'Locale corrections',
      detail: true,
      detailWide: true,
    },
  ]

  function mountWide() {
    const controller = new TableViewportController<unknown>({
      resolve: (raw) => ({ name: String(raw.slots['name']) }),
      sendViewport: () => {},
      frame: { title: 'Names', columns: WIDE_COLUMNS },
      expandable: (row) => (row as Row).name !== 'Bob',
    })
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
    const wrapper = mount(HilosViewportTable, {
      props: { controller },
      slots: {
        'cell-name': (props: { row: unknown }) =>
          h('span', { class: 'named' }, (props.row as Row).name),
      },
    })

    return { controller, wrapper }
  }

  it('draws no control, in the row or in the card, on a row with nothing to open', () => {
    const { wrapper } = mountWide()

    expect(wrapper.findAll('[data-id="hilos-table-expand-a"]')).toHaveLength(2)
    expect(wrapper.find('[data-id="hilos-table-expand-b"]').exists()).toBe(
      false,
    )
  })

  it('gives a wide field the whole width of the panel and of the card', async () => {
    const { wrapper } = mountWide()

    await wrapper.find('tr [data-id="hilos-table-expand-a"]').trigger('click')

    const fields = wrapper.findAll(
      'tr[data-id="hilos-table-row-detail-a"] dl > div',
    )
    expect(fields).toHaveLength(2)
    expect(fields[0]?.classes()).not.toContain('col-md-12')
    expect(fields[1]?.classes()).toContain('col-md-12')

    const card = wrapper.find(
      '[data-id="hilos-table-card-a"] [data-id="hilos-table-row-detail-a"]',
    )
    const labels = card.findAll('dt')
    expect(labels[0]?.classes()).toContain('col-5')
    expect(labels[1]?.classes()).toContain('col-12')
    expect(card.findAll('dd')[1]?.classes()).toContain('col-12')
  })

  it('closes the panel of a row that lost what it had to show', async () => {
    const { controller, wrapper } = mountWide()
    controller.expandRow('a', true)
    await wrapper.vm.$nextTick()

    expect(
      wrapper.find('tr[data-id="hilos-table-row-detail-a"]').exists(),
    ).toBe(true)

    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'Bob' } },
    })
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-id="hilos-table-row-detail-a"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-table-expand-a"]').exists()).toBe(
      false,
    )
  })
})

// These tests exercise DOM ownership and numeric wiring. Browser layout is the
// separate G/B matrix; happy-dom supplies no CSS geometry evidence.
describe('HilosViewportTable permanent geometry chrome', () => {
  const combinations = [false, true].flatMap((stale) =>
    ([null, 'move', 'remove'] as const).flatMap((pending) =>
      (['absent', 'collapsed', 'expanded'] as const).map((detail) => ({
        stale,
        pending,
        detail,
      })),
    ),
  )

  for (const edge of ['start', 'end'] as const) {
    it.each(combinations)(
      `${edge}: stale=$stale pending=$pending detail=$detail`,
      async ({ stale, pending, detail }) => {
        const columns: HilosTableColumn[] = [
          { key: 'name', label: 'Name', source: 'people', progress: true },
        ]
        if (detail !== 'absent')
          columns.push({ key: 'note', label: 'Note', detail: true })
        const { controller } = makeController({
          title: 'People',
          columns,
          bulkActions: [
            {
              key: 'archive',
              label: 'Archive',
              refusalTitle: "Couldn't archive",
              run: neverRun,
            },
          ],
        })
        controller.ingestWindow(
          [{ rowKey: 'a', slots: { name: 'Alice' } }],
          1,
          true,
          null,
          null,
          10,
        )
        if (stale)
          controller.ingestDelta({
            kind: 'row_stale',
            rowKey: 'a',
            staleSources: ['people'],
          })
        if (detail === 'expanded') controller.expandRow('a', true)
        if (pending === 'move')
          controller.ingestDelta({
            kind: 'row_moved',
            rowKey: 'a',
            row: { rowKey: 'a', slots: { name: 'Alice' } },
            position: 0,
          })
        if (pending === 'remove')
          controller.ingestDelta({
            kind: 'row_removed',
            rowKey: 'a',
            reason: 'deleted',
          })
        const wrapper = mount(HilosViewportTable, {
          props: { controller },
          global: { provide: { [hilosTableSelectionEdgeKey as symbol]: edge } },
        })
        expect(wrapper.findAll('thead th')).toHaveLength(3)
        expect(wrapper.findAll('colgroup col')).toHaveLength(3)
        expect(
          wrapper.get('tr[data-id="hilos-table-row-a"]').findAll('td'),
        ).toHaveLength(3)
        const holders = wrapper.findAll('.hilos-table-marks.invisible')
        expect(holders).toHaveLength(3) // header, row, card
        for (const holder of holders) {
          expect(holder.attributes('aria-hidden')).toBe('true')
          expect(holder.attributes()).toHaveProperty('inert')
          expect(holder.findAll('.bi-snow')).toHaveLength(1)
          expect(holder.findAll('.badge').map((badge) => badge.text())).toEqual(
            ['Will move', 'Will leave'],
          )
          expect(holder.findAll('.btn')).toHaveLength(
            detail === 'absent' ? 0 : 1,
          )
          expect(
            holder
              .find(
                '[id], [data-id], [data-autofocus], button, input, [tabindex], a',
              )
              .exists(),
          ).toBe(false)
        }
        expect(
          wrapper.findAll('[data-id="hilos-table-expand-a"]'),
        ).toHaveLength(detail === 'absent' ? 0 : 2)
        expect(
          wrapper.findAll('[data-id="hilos-table-stale-column-name"]'),
        ).toHaveLength(stale ? 1 : 0)
        expect(
          wrapper.findAll('th .bi-snow.invisible, th .invisible .bi-snow'),
        ).toHaveLength(2) // header mark + source mark
        if (pending === 'remove') {
          controller.apply()
          await wrapper.vm.$nextTick()
          const placeholders = wrapper.findAll(
            '[data-id="hilos-table-placeholder"]',
          )
          expect(placeholders).toHaveLength(2)
          expect(placeholders[0]?.attributes('colspan')).toBe('3')
          for (const placeholder of placeholders)
            expect(
              placeholder.find('button, input, [id], [tabindex]').exists(),
            ).toBe(false)
          expect(
            wrapper.findAll(
              '[data-id="hilos-table-expand-a"], [data-id="hilos-table-select-a"], [data-id="hilos-table-row-detail-a"]',
            ),
          ).toHaveLength(0)
        }
        wrapper.unmount()
        controller.dispose()
      },
    )
  }

  it('reserves declared detail room even where the record is not expandable', () => {
    const controller = new TableViewportController<unknown>({
      resolve: () => ({}),
      sendViewport: () => {},
      expandable: () => false,
      frame: {
        columns: [
          { key: 'name', label: 'Name' },
          { key: 'note', label: 'Note', detail: true },
        ],
      },
    })
    controller.ingestWindow(
      [{ rowKey: 'a', slots: {} }],
      1,
      true,
      null,
      null,
      10,
    )
    const wrapper = mountTable(controller)
    expect(wrapper.findAll('.hilos-table-marks.invisible > .btn')).toHaveLength(
      3,
    )
    expect(
      wrapper.findAll('button[data-id="hilos-table-expand-a"]'),
    ).toHaveLength(0)
    wrapper.unmount()
    controller.dispose()
  })

  it('replaces every mirror, child subscription and geometry registration with its controller', async () => {
    const first = makeController({
      title: 'First',
      columns: COLUMNS,
      search: {},
    }).controller
    first.ingestWindow(
      [{ rowKey: 'a', slots: { name: 'Alice' } }],
      1,
      true,
      null,
      null,
      10,
    )
    const second = makeController({
      title: 'Second',
      columns: TWO_COLUMNS,
      search: {},
    }).controller
    second.ingestWindow(
      [{ rowKey: 'b', slots: { name: 'Bob' } }],
      1,
      true,
      null,
      null,
      10,
    )
    const wrapper = mountTable(first)
    await wrapper.setProps({ controller: second })
    expect(wrapper.get('[data-id="hilos-table-title"]').text()).toBe('Second')
    expect(wrapper.find('[data-id="hilos-table-row-a"]').exists()).toBe(false)
    expect(wrapper.get('[data-id="hilos-table-row-b"]').text()).toContain('Bob')
    expect(wrapper.findAll('thead th')).toHaveLength(3)
    first.ingestWindow(
      [{ rowKey: 'old', slots: { name: 'Obsolete' } }],
      1,
      true,
      null,
      null,
      10,
    )
    first.setSearch('obsolete')
    await wrapper.vm.$nextTick()
    expect(wrapper.text()).not.toContain('Obsolete')
    expect(
      (
        wrapper.get('[data-id="hilos-table-search"]')
          .element as HTMLInputElement
      ).value,
    ).toBe('')
    second.ingestDelta({
      kind: 'row_updated',
      rowKey: 'b',
      row: { rowKey: 'b', slots: { name: 'Bobby' } },
    })
    await wrapper.vm.$nextTick()
    expect(wrapper.get('[data-id="hilos-table-row-b"]').text()).toContain(
      'Bobby',
    )
    wrapper.unmount()
    // Unmounting one view does not destroy a controller still owned by the page.
    second.ingestWindow(
      [{ rowKey: 'c', slots: { name: 'Carol' } }],
      1,
      true,
      null,
      null,
      10,
    )
    expect(second.rows.get()[0]?.rowKey).toBe('c')
    first.dispose()
    second.dispose()
  })
})

describe('HilosViewportTable capture and disappearing controls', () => {
  const views: { unmount(): void }[] = []
  afterEach(() => {
    views.splice(0).forEach((wrapper) => wrapper.unmount())
    vi.restoreAllMocks()
    document.body.replaceChildren()
  })

  function numericDOM(searchVisible = true) {
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(
      function (this: HTMLElement) {
        const id = this.dataset.id
        const dimensions = (top: number, height: number, width = 400) =>
          new DOMRect(0, top, width, height)
        if (id === 'hilos-table-search' && !searchVisible)
          return dimensions(0, 0, 0)
        if (id === 'hilos-table-row-a') return dimensions(40, 41.25)
        if (id === 'hilos-table-row-detail-a' && this.tagName === 'TR')
          return dimensions(99.75, 60.25)
        if (id === 'hilos-table-progress-row-a')
          return this.tagName === 'TR'
            ? dimensions(81.25, 18.5)
            : dimensions(400, 24.5)
        if (id === 'hilos-table-card-a') return dimensions(300, 260.75)
        if (id === 'hilos-table-row-detail-a') return dimensions(340, 60.25)
        if (this.classList.contains('hilos-table-work-reserve')) {
          const space = this.classList.contains('hilos-table-work-space')
            ? this
            : this.querySelector<HTMLElement>('.hilos-table-work-space')
          return dimensions(
            81.25,
            Number(space?.style.getPropertyValue('--hilos-table-work-px')),
          )
        }
        if (this.classList.contains('hilos-table-replacement-row'))
          return dimensions(0, 19)
        if (this.classList.contains('hilos-table-reason-twins'))
          return dimensions(0, 18)
        if (this.tagName === 'TH')
          return new DOMRect(
            Array.from(this.parentElement!.children).indexOf(this) * 100,
            0,
            100,
            20,
          )
        return dimensions(0, 100)
      },
    )
  }

  function painted(search = true) {
    const { controller } = makeController({
      title: 'People',
      columns: [
        { key: 'name', label: 'Name' },
        { key: 'note', label: 'Note', detail: true },
      ],
      ...(search ? { search: {} } : {}),
    })
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
    controller.expandRow('a', true)
    controller.ingestProgress({
      scope: 'row',
      progressKey: 'job',
      rowKey: 'a',
      current: 1,
      total: 2,
      detail: { secret: 'work value' },
    })
    const wrapper = mount(HilosViewportTable, {
      props: { controller },
      attachTo: document.body,
      slots: {
        'cell-name': ({ row, rowKey }: { row: unknown; rowKey: string }) =>
          h(
            'button',
            { 'data-id': `page-control-${rowKey}` },
            (row as Row).name,
          ),
        'detail-note': () => 'private detail',
        'row-progress': () => 'private work caption',
      },
    })
    views.push(wrapper)
    return { controller, wrapper }
  }

  it('gives the first own-removal replacement the complete old footprint, and returns focus to visible search', async () => {
    numericDOM()
    const { controller, wrapper } = painted()
    await wrapper.vm.$nextTick()
    ;(
      wrapper.get('tr [data-id="page-control-a"]').element as HTMLElement
    ).focus()
    const firstInsertions: string[] = []
    const insert = Node.prototype.insertBefore
    vi.spyOn(Node.prototype, 'insertBefore').mockImplementation(function <
      T extends Node,
    >(this: Node, node: T, anchor: Node | null): T {
      const result = insert.call(this, node, anchor) as T
      if (node instanceof HTMLElement && node.isConnected) {
        const sizing = node.querySelector<HTMLElement>(
          '.hilos-table-record-space',
        )
        if (sizing?.closest('tr[data-id="hilos-table-row-a"]'))
          firstInsertions.push(
            sizing.style.getPropertyValue('--hilos-table-record-px'),
          )
      }
      return result
    })
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
      own: true,
    })
    await wrapper.vm.$nextTick()
    const placeholder = wrapper.get(
      'tr[data-id="hilos-table-row-a"] [data-id="hilos-table-placeholder"]',
    )
    expect(
      placeholder.get('.hilos-table-record-space').attributes('style'),
    ).toBe('--hilos-table-record-px: 119;')
    expect(firstInsertions[0]).toBe('119')
    expect(
      wrapper
        .get('[data-id="hilos-table-card-a"] .hilos-table-record-space')
        .attributes('style'),
    ).toBe('--hilos-table-record-px: 260.75;')
    expect(placeholder.text()).not.toMatch(/Alice|private|work value/)
    expect(
      wrapper.findAll(
        '[data-id="page-control-a"], [data-id="hilos-table-progress-row-a"], [data-id="hilos-table-row-detail-a"]',
      ),
    ).toHaveLength(0)
    expect(document.activeElement).toBe(
      wrapper.get('[data-id="hilos-table-search"]').element,
    )
    controller.dispose()
  })

  it('holds an empty numeric work block before Apply and consumes it once in the full placeholder', async () => {
    numericDOM()
    const { controller, wrapper } = painted()
    await wrapper.vm.$nextTick()
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'left_set',
    })
    await wrapper.vm.$nextTick()
    const reserves = wrapper.findAll('.hilos-table-work-reserve')
    expect(reserves).toHaveLength(2)
    expect(
      reserves[0]?.get('.hilos-table-work-space').attributes('style'),
    ).toBe('--hilos-table-work-px: 18.5;')
    expect(reserves[1]?.attributes('style')).toBe(
      '--hilos-table-work-px: 24.5;',
    )
    for (const reserve of reserves) {
      expect(reserve.text()).toBe('')
      expect(reserve.find('[data-id], button, input').exists()).toBe(false)
    }
    expect(wrapper.text()).not.toContain('private work caption')
    controller.apply()
    await wrapper.vm.$nextTick()
    expect(wrapper.findAll('.hilos-table-work-reserve')).toHaveLength(0)
    expect(
      wrapper
        .get('tr[data-id="hilos-table-row-a"] .hilos-table-record-space')
        .attributes('style'),
    ).toBe('--hilos-table-record-px: 119;')
    controller.dispose()
  })

  it.each([false, true])(
    'falls back to the root when search is absent/hidden: hidden=$0',
    async (hidden) => {
      numericDOM(false)
      const { controller, wrapper } = painted(hidden)
      await wrapper.vm.$nextTick()
      ;(
        wrapper.get('tr [data-id="page-control-a"]').element as HTMLElement
      ).focus()
      controller.ingestDelta({
        kind: 'row_removed',
        rowKey: 'a',
        reason: 'deleted',
        own: true,
      })
      await wrapper.vm.$nextTick()
      expect(document.activeElement).toBe(wrapper.element)
      expect(wrapper.attributes('tabindex')).toBe('-1')
      controller.dispose()
    },
  )

  it('keeps focus on a surviving record or outside the table', async () => {
    numericDOM()
    const { controller, wrapper } = painted()
    await wrapper.vm.$nextTick()
    const other = wrapper.get('tr [data-id="page-control-b"]')
      .element as HTMLElement
    other.focus()
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
      own: true,
    })
    await wrapper.vm.$nextTick()
    expect(document.activeElement).toBe(other)
    const outside = document.createElement('button')
    document.body.append(outside)
    outside.focus()
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'b',
      reason: 'deleted',
      own: true,
    })
    await wrapper.vm.$nextTick()
    expect(document.activeElement).toBe(outside)
    controller.dispose()
  })
})

// Mocked boxes prove capture/wiring/lifetime only. CSS wrapping and keyboard
// layout evidence belong to the real browser fixture in HIL-1752 slice 7.
describe('HilosViewportTable text integration', () => {
  const owners: TableViewportController<unknown>[] = []
  afterEach(() => {
    owners.splice(0).forEach((owner) => owner.dispose())
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
    document.body.replaceChildren()
  })

  function textDOM() {
    let serial = 0
    let long = true
    let cardVisible = true
    let cardLong = true
    const frames = new Map<number, FrameRequestCallback>()
    const observers: {
      changed: () => void
      targets: Set<Element>
    }[] = []
    vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => {
      frames.set(++serial, callback)
      return serial
    })
    vi.stubGlobal('cancelAnimationFrame', (id: number) => frames.delete(id))
    vi.stubGlobal(
      'ResizeObserver',
      class {
        readonly targets = new Set<Element>()
        constructor(readonly changed: () => void) {
          observers.push(this)
        }
        observe(node: Element) {
          this.targets.add(node)
        }
        unobserve(node: Element) {
          this.targets.delete(node)
        }
        disconnect() {
          this.targets.clear()
        }
      },
    )
    vi.stubGlobal(
      'MutationObserver',
      class {
        readonly targets = new Set<Element>()
        constructor(readonly changed: () => void) {
          observers.push(this)
        }
        observe(node: Element) {
          this.targets.add(node)
        }
        disconnect() {
          this.targets.clear()
        }
      },
    )
    const card = (node: HTMLElement) =>
      node.closest('[data-id="hilos-table-cards"]') !== null
    const style = window.getComputedStyle.bind(window)
    vi.spyOn(window, 'getComputedStyle').mockImplementation((node) => {
      const result = style(node)
      return new Proxy(result, {
        get(target, property) {
          if (
            property === 'display' &&
            !cardVisible &&
            card(node as HTMLElement)
          )
            return 'none'
          return Reflect.get(target, property)
        },
      })
    })
    const opened = (node: Element | null) =>
      (node?.querySelectorAll('.hilos-table-text.is-open').length ?? 0) * 100
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(
      function (this: HTMLElement) {
        if (!cardVisible && card(this)) return new DOMRect()
        const row = this.closest('tbody')?.querySelector(
          '[data-id="hilos-table-row-a"]',
        )
        const extra = opened(row ?? null)
        if (this.dataset.id === 'hilos-table-row-a')
          return new DOMRect(0, 40, 400, 40.25 + extra)
        if (this.dataset.id === 'hilos-table-card-a')
          return new DOMRect(0, 300, 400, 260.75 + opened(this))
        if (this.dataset.id === 'hilos-table-progress-row-a')
          return new DOMRect(0, 80.25 + extra, 400, 18.5)
        if (this.dataset.id === 'hilos-table-row-detail-a')
          return new DOMRect(0, 98.75 + extra, 400, 60.25)
        if (this.classList.contains('hilos-table-text'))
          return new DOMRect(0, 0, 200, 40)
        if (this.classList.contains('hilos-table-reason-twins'))
          return new DOMRect(0, 0, 400, 18)
        if (this.classList.contains('hilos-table-replacement-row'))
          return new DOMRect(0, 0, 400, 19)
        if (this.parentElement?.classList.contains('hilos-table-record-space'))
          return new DOMRect(0, 0, 400, 18)
        return new DOMRect(0, 0, 400, 100)
      },
    )
    for (const property of [
      'clientWidth',
      'clientHeight',
      'scrollWidth',
      'scrollHeight',
    ] as const) {
      vi.spyOn(HTMLElement.prototype, property, 'get').mockImplementation(
        function (this: HTMLElement) {
          if (!cardVisible && card(this)) return 0
          if (property === 'clientHeight') return 40
          if (property === 'scrollHeight')
            return (card(this) ? cardLong : long) ? 80 : 40
          return 200
        },
      )
    }
    return {
      observers,
      frames,
      shorten() {
        long = false
        cardLong = false
        observers.forEach((observer) => observer.changed())
      },
      shortenCard() {
        cardLong = false
        observers.forEach((observer) => observer.changed())
      },
      hideCard() {
        cardVisible = false
        observers.forEach((observer) => observer.changed())
      },
      async flush() {
        await nextTick()
        for (let pass = 0; pass < 3 && frames.size > 0; pass++) {
          const callbacks = [...frames.values()]
          frames.clear()
          callbacks.forEach((callback) => callback(0))
          await nextTick()
        }
      },
    }
  }

  function textTable(columns?: () => readonly HilosTableColumn[]) {
    const mode = shallowRef<'text' | 'parts' | 'unsafe'>('text')
    const value = shallowRef('Complete live name')
    const note = shallowRef<string | typeof HIDDEN_VALUE>('Complete note')
    const { controller } = makeController({
      title: 'Text values',
      search: {},
      rowHeight: { mode: 'lines', count: 2 },
      columns: columns ?? [
        { key: 'name', label: 'Name', heightContent: 'text' },
        { key: 'note', label: 'Note', heightContent: 'parts' },
        { key: 'badge', label: 'Status', card: 'badge' },
        { key: 'empty', label: 'Empty', heightContent: 'text' },
        { key: 'missing', label: 'Missing', heightContent: 'parts' },
        { key: 'actions', label: 'Actions' },
        { key: 'detail', label: 'Detail', detail: true },
      ],
    })
    owners.push(controller)
    const snapshot = () =>
      controller.ingestWindow(
        [{ rowKey: 'a', slots: { name: value.value } }],
        1,
        true,
        null,
        null,
        10,
      )
    snapshot()
    const wrapper = mount(HilosViewportTable, {
      props: { controller },
      attachTo: document.body,
      slots: {
        'cell-name': () =>
          mode.value === 'parts'
            ? h(HilosTableText, { value: value.value })
            : mode.value === 'unsafe'
              ? h('button', { 'data-id': 'unsafe-text-action' }, value.value)
              : value.value,
        'cell-note': () => [
          h(HilosTableText, { value: note.value }),
          h('span', { class: 'badge' }, 'outside cap'),
        ],
        'cell-badge': () => h('span', { class: 'badge' }, 'Active'),
        'cell-empty': () => [],
        'cell-actions': () => h('button', { 'data-id': 'text-action' }, 'Act'),
        'detail-detail': () => 'private detail',
        'row-progress': () => 'private work',
      },
    })
    const wide = () => wrapper.get('[data-id="hilos-table-row-a"]')
    const card = () => wrapper.get('[data-id="hilos-table-card-a"]')
    return { wrapper, controller, snapshot, wide, card, value, note, mode }
  }

  it('connects text/parts in both projections, preserving missing/empty renderers and valid card blocks', async () => {
    const dom = textDOM()
    const f = textTable()
    await dom.flush()
    expect(f.wrapper.findAllComponents(HilosTableValue)).toHaveLength(7)
    expect(f.wide().findAll('td')).toHaveLength(7)
    expect(
      f
        .card()
        .findAll('dt')
        .map((node) => node.text()),
    ).toEqual(['Note', 'Empty'])
    expect(f.wrapper.findAll('.hilos-table-text-lines-2')).toHaveLength(6)
    expect(
      f.wrapper
        .findAll('.hilos-table-text')
        .filter((node) => node.text() === ''),
    ).toHaveLength(2)
    const title = f.card().get('.fw-medium.flex-grow-1')
    expect(title.element.tagName).toBe('DIV')
    expect(title.find('span > div').exists()).toBe(false)
    for (const branch of [f.wide(), f.card()]) {
      expect(
        branch
          .get('[data-id="text-action"]')
          .element.closest('.hilos-table-text'),
      ).toBeNull()
      for (const badge of branch.findAll('.badge'))
        expect(badge.element.closest('.hilos-table-text')).toBeNull()
    }
    f.note.value = HIDDEN_VALUE
    await dom.flush()
    expect(f.wrapper.findAll('[data-id="hilos-hidden"]')).toHaveLength(2)
    for (const mark of f.wrapper.findAll('[data-id="hilos-hidden"]'))
      expect(mark.element.closest('.hilos-table-text')).toBeNull()
    expect(f.wrapper.text()).not.toContain('[object Object]')
  })

  it('keeps unsafe text slot controls intrinsic through live safe/unsafe changes', async () => {
    const warning = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const dom = textDOM()
    const f = textTable()
    await dom.flush()
    f.mode.value = 'unsafe'
    await dom.flush()
    for (const branch of [f.wide(), f.card()]) {
      expect(
        branch
          .get('[data-id="unsafe-text-action"]')
          .element.closest('.hilos-table-text'),
      ).toBeNull()
      expect(branch.find('[aria-label="Show in full: Name"]').exists()).toBe(
        false,
      )
    }
    expect(warning).toHaveBeenCalledTimes(2)
    f.mode.value = 'text'
    await dom.flush()
    expect(f.wrapper.findAll('[aria-label="Show in full: Name"]')).toHaveLength(
      2,
    )
  })

  it('shares intent with independent ids/overflow and columns, keeps focus after live shortening and collapse', async () => {
    const dom = textDOM()
    const f = textTable()
    await dom.flush()
    const texts = f.wrapper.findAll('.hilos-table-text')
    expect(new Set(texts.map((node) => node.attributes('id'))).size).toBe(6)
    dom.shortenCard()
    await dom.flush()
    expect(f.card().find('[data-id="hilos-table-text-toggle"]').exists()).toBe(
      false,
    )
    const toggle = f.wide().get('[aria-label="Show in full: Name"]')
    const button = toggle.element as HTMLButtonElement
    expect(document.getElementById(toggle.attributes('aria-controls')!)).toBe(
      texts[0].element,
    )
    button.focus()
    await toggle.trigger('click')
    await dom.flush()
    expect(f.wrapper.findAll('.hilos-table-text.is-open')).toHaveLength(2)
    expect(
      f.card().get('[aria-label="Collapse: Name"]').attributes('aria-expanded'),
    ).toBe('true')
    expect(f.controller.rows.get()[0]!.expanded).toBe(false)
    expect(f.wide().find('[aria-label="Collapse: Note"]').exists()).toBe(false)
    await f.wide().get('[aria-label="Show in full: Note"]').trigger('click')
    expect(f.wrapper.findAll('.hilos-table-text.is-open')).toHaveLength(4)
    await f.wide().get('[aria-label="Collapse: Note"]').trigger('click')
    expect(f.wrapper.findAll('.hilos-table-text.is-open')).toHaveLength(2)
    dom.hideCard()
    await dom.flush()
    expect(f.wrapper.findAll('.hilos-table-text.is-open')).toHaveLength(2)
    f.value.value = 'Short'
    dom.shorten()
    await dom.flush()
    expect(toggle.text()).toBe('Collapse')
    await toggle.trigger('click')
    await dom.flush()
    expect(f.wide().get('[aria-label="Show in full: Name"]').element).toBe(
      button,
    )
    expect(document.activeElement).toBe(button)
    expect(f.card().find('[data-id="hilos-table-text-toggle"]').exists()).toBe(
      false,
    )
    button.blur()
    await dom.flush()
    expect(f.wide().find('[aria-label="Show in full: Name"]').exists()).toBe(
      false,
    )
  })

  it.each([
    'refresh',
    'search',
    'reset',
    'snapshot',
    'refusal',
    'dispose',
  ] as const)(
    'makes saved read/command/observer callbacks inert on %s before Vue flush',
    async (action) => {
      const dom = textDOM()
      const f = textTable()
      await dom.flush()
      const inputs = f.wrapper
        .findAllComponents(HilosTableValue)
        .map((value) => value.props())
      const read = inputs[0].readRecord
      const old = read()!
      expect(old).not.toBeNull()
      const expand = vi.spyOn(f.controller, 'expandText')
      const toggle = f.wide().get('[aria-label="Show in full: Name"]')
        .element as HTMLButtonElement
      const late = [...dom.frames.values()]
      if (action === 'refresh') f.controller.refresh()
      if (action === 'search') f.controller.setSearch('next')
      if (action === 'reset') f.controller.resetWindowRequests()
      if (action === 'snapshot') f.snapshot()
      if (action === 'refusal') f.controller.ingestRefusal('denied')
      if (action === 'dispose') f.controller.dispose()
      expect(read()).toBeNull()
      toggle.click()
      dom.observers.forEach((observer) => observer.changed())
      late.forEach((callback) => callback(0))
      expect(expand).not.toHaveBeenCalled()
      expect(f.controller.expandedText.get().size).toBe(0)
      await dom.flush()
      if (['refresh', 'search', 'reset'].includes(action)) {
        expect(f.wide().text()).toContain('Complete live name')
        expect(
          f.wrapper.find('[data-id="hilos-table-text-toggle"]').exists(),
        ).toBe(false)
        for (const value of f.wrapper.findAllComponents(HilosTableValue))
          expect(value.props().readRecord()).toBeNull()
        f.snapshot()
        await dom.flush()
        expect(
          f.wrapper.find('[aria-label="Show in full: Name"]').exists(),
        ).toBe(true)
        expect(read()).toBeNull()
      }
    },
  )

  it('captures full expanded DOM with detail/work before immediate departure and releases both projections', async () => {
    const dom = textDOM()
    const f = textTable()
    f.controller.expandRow('a', true)
    f.controller.ingestProgress({
      scope: 'row',
      progressKey: 'job',
      rowKey: 'a',
      current: 1,
      total: 2,
    })
    await dom.flush()
    const inputs = f.wrapper
      .findAllComponents(HilosTableValue)
      .map((value) => value.props())
    const button = f.wide().get('[aria-label="Show in full: Name"]')
    ;(button.element as HTMLElement).focus()
    await button.trigger('click')
    // No ResizeObserver/RAF measurement after expansion: departure must read DOM.
    expect(f.wide().find('.is-open').exists()).toBe(true)
    const late = [...dom.frames.values()]
    f.controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
      own: true,
    })
    expect(inputs.every((input) => input.readRecord() === null)).toBe(true)
    await nextTick()
    // The replacement row contributes one pixel of shell outside this block.
    expect(f.wide().get('.hilos-table-record-space').attributes('style')).toBe(
      '--hilos-table-record-px: 218;',
    )
    expect(f.card().get('.hilos-table-record-space').attributes('style')).toBe(
      '--hilos-table-record-px: 360.75;',
    )
    for (const branch of [f.wide(), f.card()]) {
      expect(branch.text()).not.toMatch(/Complete|private|outside cap/)
      expect(
        branch
          .find('.hilos-table-text, button, [data-id="text-action"]')
          .exists(),
      ).toBe(false)
    }
    expect(f.wrapper.findAllComponents(HilosTableValue)).toHaveLength(0)
    expect(f.controller.expandedText.get().size).toBe(0)
    expect(document.activeElement).toBe(
      f.wrapper.get('[data-id="hilos-table-search"]').element,
    )
    expect(
      dom.observers.every((observer) =>
        [...observer.targets].every((node) => node.isConnected),
      ),
    ).toBe(true)
    late.forEach((callback) => callback(0))
    dom.observers.forEach((observer) => observer.changed())
    await dom.flush()
    expect(f.wide().get('.hilos-table-record-space').attributes('style')).toBe(
      '--hilos-table-record-px: 218;',
    )
    f.snapshot()
    await dom.flush()
    expect(f.wrapper.find('.is-open').exists()).toBe(false)
    f.wrapper.unmount()
    expect(dom.observers.every((observer) => observer.targets.size === 0)).toBe(
      true,
    )
  })

  it('keeps pending foreign removal live until Apply and measures collapse as a smaller live extent', async () => {
    const dom = textDOM()
    const f = textTable()
    await dom.flush()
    await f.wide().get('[aria-label="Show in full: Name"]').trigger('click')
    await dom.flush()
    const input = f.wrapper.findComponent(HilosTableValue).props()
    const registration = f.controller.geometry.register(() => ({ records: [] }))
    const old = input.readRecord()!
    f.controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'left_set',
    })
    await dom.flush()
    expect(input.readRecord()).toBe(old)
    expect(
      f.controller.expandedText.get().get(old.occurrence)?.has('name'),
    ).toBe(true)
    await f.wide().get('[aria-label="Collapse: Name"]').trigger('click')
    await dom.flush()
    f.controller.apply()
    await dom.flush()
    expect(input.readRecord()).toBeNull()
    expect(f.wide().get('.hilos-table-record-space').attributes('style')).toBe(
      '--hilos-table-record-px: 39.25;',
    )
    expect(f.card().get('.hilos-table-record-space').attributes('style')).toBe(
      '--hilos-table-record-px: 260.75;',
    )
    expect(registration.record('a', 'wide')!.reason).toBe('left_set')
    registration.dispose()
  })

  it.each(['eviction', 'reused key'] as const)(
    'retires text readers and intent on %s inside the same window',
    async (action) => {
      const dom = textDOM()
      const f = textTable()
      f.controller.ingestWindow(
        [{ rowKey: 'a', slots: { name: 'First' } }],
        2,
        true,
        null,
        null,
        1,
      )
      await dom.flush()
      const read = f.wrapper.findComponent(HilosTableValue).props().readRecord
      const old = read()!
      await f.wide().get('[aria-label="Show in full: Name"]').trigger('click')
      if (action === 'reused key') {
        f.controller.ingestDelta({
          kind: 'row_removed',
          rowKey: 'a',
          reason: 'deleted',
          own: true,
        })
        await dom.flush()
      }
      f.controller.ingestOwnCreate(
        { rowKey: action === 'eviction' ? 'b' : 'a', slots: { name: 'New' } },
        0,
        2,
        true,
        null,
        null,
      )
      expect(read()).toBeNull()
      expect(f.controller.expandedText.get().has(old.occurrence)).toBe(false)
      await dom.flush()
      expect(f.wrapper.find('.is-open').exists()).toBe(false)
      if (action === 'eviction') {
        expect(
          f.wrapper
            .find(
              '[data-id="hilos-table-row-a"], [data-id="hilos-table-card-a"]',
            )
            .exists(),
        ).toBe(false)
      } else {
        expect(
          f.wrapper.findComponent(HilosTableValue).props().readRecord()!
            .occurrence,
        ).not.toBe(old.occurrence)
      }
    },
  )

  it('replaces dynamic text/parts contexts and controller readers without inheriting old intent', async () => {
    const dom = textDOM()
    const columns = createSignal<readonly HilosTableColumn[]>([
      { key: 'name', label: 'Name', heightContent: 'text' },
    ])
    const f = textTable(() => columns.get())
    await dom.flush()
    const old = f.wrapper.findComponent(HilosTableValue).props()
    await f.wide().get('[aria-label="Show in full: Name"]').trigger('click')
    f.mode.value = 'parts'
    columns.set([{ key: 'name', label: 'Renamed', heightContent: 'parts' }])
    await dom.flush()
    expect(f.wrapper.findAllComponents(HilosTableText)).toHaveLength(2)
    expect(f.wide().get('[aria-label="Collapse: Renamed"]').text()).toBe(
      'Collapse',
    )
    columns.set([
      { key: 'name', label: 'Intrinsic' },
      { key: 'reserve', label: 'Reserve', heightContent: 'text' },
    ])
    f.mode.value = 'text'
    await dom.flush()
    expect(f.wrapper.find('.hilos-table-text').exists()).toBe(false)
    expect(f.controller.expandedText.get().size).toBe(0)
    columns.set([{ key: 'name', label: 'Again', heightContent: 'text' }])
    await dom.flush()
    expect(f.wrapper.find('.is-open').exists()).toBe(false)
    const next = textTable()
    await f.wrapper.setProps({ controller: next.controller })
    expect(old.readRecord()).toBeNull()
    await dom.flush()
    expect(f.wrapper.find('.is-open').exists()).toBe(false)
  })
})

describe('HilosViewportTable modal focus lifetime', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    document.body.replaceChildren()
  })

  function fixture({
    search = true,
    projection = 'wide',
    marked = true,
  }: {
    search?: boolean
    projection?: 'wide' | 'card'
    marked?: boolean
  } = {}) {
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(
      function (this: HTMLElement) {
        return this.closest('[hidden]')
          ? new DOMRect()
          : new DOMRect(0, 0, 400, 40)
      },
    )
    const { controller } = makeController({
      title: 'People',
      columns: COLUMNS,
      ...(search ? { search: {} } : {}),
    })
    function snapshot(name = 'Alice') {
      controller.ingestWindow(
        [{ rowKey: 'a', slots: { name } }],
        1,
        true,
        null,
        null,
        10,
      )
    }
    snapshot()
    const current = shallowRef(controller)
    const open = ref(false)
    const tableMounted = ref(true)
    const marker = ref<string | undefined>(marked ? 'person-open' : undefined)
    const duplicates = ref(false)
    const wrapper = mount(
      {
        setup: () => () =>
          h('div', [
            tableMounted.value
              ? h(
                  HilosViewportTable,
                  { controller: current.value },
                  {
                    'cell-name': () => [
                      h(
                        'button',
                        {
                          'data-id': 'open-person',
                          'data-hilos-focus-return': marker.value,
                          onClick: () => {
                            open.value = true
                          },
                        },
                        'Inspect',
                      ),
                      duplicates.value
                        ? h(
                            'button',
                            {
                              'data-hilos-focus-return': marker.value,
                            },
                            'Ambiguous',
                          )
                        : null,
                    ],
                  },
                )
              : null,
            h(HilosModal, {
              modelValue: open.value,
              title: 'Inspect person',
              initialFocus: 'dialog',
              'onUpdate:modelValue': (value: boolean) => {
                open.value = value
              },
            }),
            h('button', { 'data-id': 'unrelated' }, 'Other control'),
          ]),
      },
      { attachTo: document.body },
    )
    function show(next: 'wide' | 'card') {
      const wide = wrapper.get('.table-responsive').element as HTMLElement
      const cards = wrapper.get('[data-id="hilos-table-cards"]')
        .element as HTMLElement
      wide.hidden = next !== 'wide'
      cards.hidden = next !== 'card'
    }
    show(projection)
    function control(target: 'wide' | 'card' = projection): HTMLElement {
      return wrapper.get(
        `${target === 'wide' ? 'tr' : '[role="list"]'} [data-id="open-person"]`,
      ).element as HTMLElement
    }
    const opener = control()
    function remove() {
      controller.ingestDelta({
        kind: 'row_removed',
        rowKey: 'a',
        reason: 'deleted',
        own: true,
      })
    }
    async function activate() {
      opener.focus()
      opener.click()
      await flushPromises()
      expect(document.activeElement).toBe(
        [...document.querySelectorAll('[data-id="modal"]')].at(-1),
      )
    }
    async function close() {
      document
        .querySelector('[data-id="modal"]')
        ?.dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        )
      await flushPromises()
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
    }
    return {
      controller,
      current,
      open,
      tableMounted,
      wrapper,
      opener,
      marker,
      duplicates,
      show,
      control,
      remove,
      snapshot,
      activate,
      close,
    }
  }

  it('returns to the connected opener after a live value update', async () => {
    const f = fixture()
    await f.activate()
    f.controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'Updated' } },
    })
    await flushPromises()
    expect(f.opener.isConnected).toBe(true)
    await f.close()
    expect(document.activeElement).toBe(f.opener)
    f.controller.dispose()
  })

  it.each(['wide', 'card'] as const)(
    'returns to the fresh marked logical control in the %s projection',
    async (projection) => {
      const f = fixture({ projection })
      const occurrence = f.controller.rows.get()[0]!.occurrence
      await f.activate()
      const modal = document.activeElement
      f.snapshot('Updated person')
      expect(f.controller.rows.get()[0]!.occurrence).not.toBe(occurrence)
      await flushPromises()
      const fresh = f.control()
      expect(f.opener.isConnected).toBe(false)
      expect(fresh).not.toBe(f.opener)
      expect(fresh.isConnected).toBe(true)
      expect(document.activeElement).toBe(modal)
      await f.close()
      expect(document.activeElement).toBe(fresh)
      f.controller.dispose()
    },
  )

  it.each(['same key', 'reused key'] as const)(
    'returns an unmarked data-id-only %s control to search',
    async (replacement) => {
      const f = fixture({ marked: false })
      await f.activate()
      const modal = document.activeElement
      f.snapshot(replacement === 'reused key' ? 'Another person' : 'Alice')
      await flushPromises()
      expect(f.opener.isConnected).toBe(false)
      expect(f.control()).not.toBe(f.opener)
      expect(document.activeElement).toBe(modal)
      await f.close()
      expect(document.activeElement).toBe(
        f.wrapper.get('[data-id="hilos-table-search"]').element,
      )
      f.controller.dispose()
    },
  )

  it.each([
    'removed',
    'placeholder',
    'missing marker',
    'duplicate marker',
    'hidden control',
    'disabled control',
    'hidden projection',
    'foreign parts',
  ] as const)(
    'returns to search when the marked replacement is unavailable: %s',
    async (replacement) => {
      const f = fixture()
      await f.activate()
      const modal = document.activeElement
      if (replacement === 'removed') {
        f.controller.ingestWindow([], 0, true, null, null, 10)
      } else if (replacement === 'placeholder') {
        f.remove()
      } else {
        if (replacement === 'missing marker') f.marker.value = undefined
        if (replacement === 'duplicate marker') f.duplicates.value = true
        f.snapshot()
      }
      await flushPromises()
      if (replacement === 'hidden control') f.control().hidden = true
      if (replacement === 'disabled control')
        f.control().setAttribute('disabled', '')
      if (replacement === 'hidden projection') f.show('card')
      const foreign = document.createElement('table')
      if (replacement === 'foreign parts') {
        document.body.append(foreign)
        foreign.append(f.control().closest('tr')!)
      }
      expect(f.opener.isConnected).toBe(false)
      expect(document.activeElement).toBe(modal)
      await f.close()
      expect(document.activeElement).toBe(
        f.wrapper.get('[data-id="hilos-table-search"]').element,
      )
      // Return externally moved DOM before Vue unmounts its own children.
      if (replacement === 'foreign parts')
        f.wrapper.get('tbody').element.append(foreign.firstElementChild!)
      foreign.remove()
      f.controller.dispose()
    },
  )

  it.each(['absent', 'hidden'] as const)(
    'returns a removed modal opener to the root with %s search',
    async (search) => {
      const f = fixture({ search: search === 'hidden' })
      await f.activate()
      if (search === 'hidden')
        f.wrapper
          .get('[data-id="hilos-table-search"]')
          .element.setAttribute('hidden', '')
      f.remove()
      await flushPromises()
      await f.close()
      const root = f.wrapper.get('[data-id="hilos-viewport-table"]')
      expect(document.activeElement).toBe(root.element)
      expect(root.attributes('tabindex')).toBe('-1')
      f.controller.dispose()
    },
  )

  it('keeps an explicit focus choice outside the dialog after a replacement', async () => {
    const f = fixture()
    await f.activate()
    const other = f.wrapper.get('[data-id="unrelated"]').element as HTMLElement
    other.focus()
    f.snapshot()
    await flushPromises()
    expect(document.activeElement).toBe(other)
    await f.close()
    expect(document.activeElement).toBe(other)
    f.controller.dispose()
  })

  it('keeps two modal addresses separate and preserves the upper dialog focus', async () => {
    const lower = fixture()
    const upper = fixture()
    await lower.activate()
    await upper.activate()
    const modal = document.activeElement
    lower.snapshot('Lower person')
    upper.snapshot('Upper person')
    await flushPromises()
    expect(lower.opener.isConnected).toBe(false)
    expect(upper.opener.isConnected).toBe(false)
    expect(document.activeElement).toBe(modal)
    lower.open.value = false
    await flushPromises()
    expect(document.activeElement).toBe(modal)
    await upper.close()
    expect(document.activeElement).toBe(upper.control())
    expect(document.activeElement).not.toBe(lower.control())
    lower.controller.dispose()
    upper.controller.dispose()
  })

  it.each(['controller', 'unmount'] as const)(
    'invalidates a modal return callback after table %s',
    async (change) => {
      const f = fixture()
      const other = f.wrapper.get('[data-id="unrelated"]')
        .element as HTMLElement
      const release = registerFocusReturnFallback(
        f.wrapper.element as HTMLElement,
        () => other,
      )
      await f.activate()
      const next = makeController({
        title: 'Other table',
        columns: COLUMNS,
        search: {},
      }).controller
      if (change === 'controller') {
        next.ingestWindow(
          [{ rowKey: 'a', slots: { name: 'Unrelated' } }],
          1,
          true,
          null,
          null,
          10,
        )
        f.current.value = next
        await flushPromises()
        const modal = document.activeElement
        expect(f.opener.isConnected).toBe(false)
        expect(modal).toBe(document.querySelector('[data-id="modal"]'))
        const replacement = f.control()
        await f.close()
        expect(document.activeElement).not.toBe(
          f.wrapper.get('[data-id="hilos-table-search"]').element,
        )
        expect(document.activeElement).not.toBe(replacement)
        expect(modal?.isConnected).toBe(false)
      } else {
        f.tableMounted.value = false
        await flushPromises()
        expect(document.activeElement).toBe(
          document.querySelector('[data-id="modal"]'),
        )
        await f.close()
        expect(document.activeElement).not.toBe(f.opener)
      }
      expect(document.activeElement).toBe(other)
      release()
      f.controller.dispose()
      next.dispose()
    },
  )
})
