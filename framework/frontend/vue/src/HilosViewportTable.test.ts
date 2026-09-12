import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { h } from 'vue'
import { TableViewportController } from '@hilos/core'
import type {
  HilosTableColumn,
  HilosTableFrame,
  HilosTableProgress,
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

  it('stands above the strips of live change', () => {
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

    const ids = wrapper
      .findAll('[data-id]')
      .map((node) => node.attributes('data-id'))
    expect(ids.indexOf('hilos-table-progress')).toBeLessThan(
      ids.indexOf('hilos-table-announce'),
    )
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

  it('leaves no caption line where the page filled no slot', () => {
    const { controller } = makeController()
    window(controller)
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 34,
      total: 120,
    })
    const wrapper = mountTable(controller)

    // A line holding its margin with nothing in it is not a reserve but a gap:
    // the track is the only thing in the block (Flow F7).
    const block = wrapper.get('[data-id="hilos-table-progress"]')
    expect(block.findAll('div.small')).toHaveLength(0)
  })

  it('draws nothing for a bulk frame, whose place is the selection panel', () => {
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
      false,
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

  it('takes the sort control off a quiet column and leaves the reason in its place', () => {
    const { controller } = makeController()
    window(controller, ['connections'])
    const wrapper = mountTable(controller, false, SOURCED_COLUMNS)

    expect(wrapper.find('[data-id="hilos-table-sort-presence"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-table-sort-name"]').exists()).toBe(
      true,
    )
    expect(
      wrapper.find('[data-id="hilos-table-stale-column-presence"]').exists(),
    ).toBe(true)
    expect(wrapper.findAll('thead th')[1]?.text()).toContain(
      'Sorting by this column is unavailable',
    )
  })

  it('sends no viewport when the name of a quiet column is clicked', async () => {
    const { controller, sent } = makeController()
    window(controller, ['connections'])
    const wrapper = mountTable(controller, false, SOURCED_COLUMNS)
    sent.length = 0
    await wrapper.findAll('thead th')[1]?.trigger('click')

    expect(sent).toHaveLength(0)
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
    expect(wrapper.find('thead th:last-child').text()).toBe('Row state')
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
