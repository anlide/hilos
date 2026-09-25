import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import {
  HILOS_TABLE_OPENING_ORDER_KEY,
  TableViewportController,
} from '@hilos/core'
import type {
  ActionHandle,
  HilosTableBulkAccepted,
  HilosTableColumn,
  HilosTableFrame,
  HilosTableSortOrder,
  TableSortOrder,
  TableViewportDescriptor,
} from '@hilos/core'

import HilosModal from './HilosModal.vue'
import HilosTableBar from './HilosTableBar.vue'

const COLUMNS = [{ key: 'name', label: 'Name' }]

// The controller is typed as unknown so it lines up with the generic SFC, whose
// `R` @vue/test-utils does not infer from the prop value.
function makeController(
  frame: HilosTableFrame,
  initialFilter?: Record<string, unknown>,
  declaredOrders?: readonly HilosTableSortOrder[],
): {
  controller: TableViewportController<unknown>
  sent: TableViewportDescriptor[]
} {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<unknown>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: (descriptor) => sent.push(descriptor),
    initialFilter,
    frame,
    declaredOrders,
  })

  return { controller, sent }
}

// The two columns a composite order runs by, and the orders the table declares
// over them — the shape the "Order" menu is drawn from.
const ORDERED_COLUMNS: readonly HilosTableColumn[] = [
  { key: 'channel', label: 'Kind', sortable: true },
  { key: 'createdAt', label: 'Date', sortable: true },
]

const DECLARED_ORDERS: readonly HilosTableSortOrder[] = [
  {
    key: 'by_channel',
    components: [
      { field: 'channel', direction: 'asc' },
      { field: 'createdAt', direction: 'desc' },
    ],
  },
  {
    key: 'by_date_up',
    components: [
      { field: 'createdAt', direction: 'asc' },
      { field: 'channel', direction: 'asc' },
    ],
  },
]

const OPENING_ORDER: TableSortOrder = [
  { field: 'createdAt', direction: 'desc' },
]

/**
 * A table that declares composite orders and has opened in one of its own — the
 * order only the first window can tell it, the way a page's answer does.
 */
function makeOrdered(
  columns: readonly HilosTableColumn[] = ORDERED_COLUMNS,
  declaredOrders: readonly HilosTableSortOrder[] = DECLARED_ORDERS,
): {
  controller: TableViewportController<unknown>
  sent: TableViewportDescriptor[]
} {
  const made = makeController(
    { title: 'Deliveries', columns },
    undefined,
    declaredOrders,
  )
  made.controller.ingestSubscriptionWindow(
    [],
    0,
    true,
    null,
    null,
    20,
    OPENING_ORDER,
    [],
  )

  return made
}

const FILTERED: HilosTableFrame = {
  title: 'Deliveries',
  search: {},
  filters: [
    { kind: 'toggle', key: 'state', label: 'Failed only', on: 'failed' },
    { kind: 'toggle', key: 'unread', label: 'Unread only', on: true },
  ],
  columns: COLUMNS,
}

function mountBar(controller: TableViewportController<unknown>) {
  return mount(HilosTableBar, {
    props: { controller, titleId: 'table-title' },
  })
}

describe('HilosTableBar', () => {
  it('shows the declared title and subtitle, the title carrying the given id', () => {
    const { controller } = makeController({
      title: 'Backups',
      subtitle: 'Every copy this installation keeps',
      columns: COLUMNS,
    })
    const wrapper = mountBar(controller)

    const title = wrapper.find('[data-id="hilos-table-title"]')
    expect(title.text()).toBe('Backups')
    expect(title.attributes('id')).toBe('table-title')
    expect(wrapper.find('[data-id="hilos-table-subtitle"]').text()).toBe(
      'Every copy this installation keeps',
    )
  })

  it('draws no subtitle when the declaration carries none', () => {
    const { controller } = makeController({
      title: 'Backups',
      columns: COLUMNS,
    })
    const wrapper = mountBar(controller)

    expect(wrapper.find('[data-id="hilos-table-subtitle"]').exists()).toBe(
      false,
    )
  })

  it('draws no search box when the table does not declare search', () => {
    const { controller } = makeController({
      title: 'Backups',
      columns: COLUMNS,
    })
    const wrapper = mountBar(controller)

    expect(wrapper.find('[data-id="hilos-table-search"]').exists()).toBe(false)
  })

  it('names the search field with its declared placeholder', () => {
    const { controller } = makeController({
      title: 'Backups',
      search: { placeholder: 'Search backups…' },
      columns: COLUMNS,
    })
    const field = mountBar(controller).find('[data-id="hilos-table-search"]')

    expect(field.attributes('placeholder')).toBe('Search backups…')
    expect(field.attributes('aria-label')).toBe('Search backups…')
  })

  it('falls back to "Search…" when the declaration names no placeholder', () => {
    const { controller } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    const field = mountBar(controller).find('[data-id="hilos-table-search"]')

    expect(field.attributes('placeholder')).toBe('Search…')
  })

  it('sends a window on every keystroke of the search field', async () => {
    const { controller, sent } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    const wrapper = mountBar(controller)

    await wrapper.find('[data-id="hilos-table-search"]').setValue('nig')

    expect(sent).toHaveLength(1)
    expect(sent.at(-1)?.filter).toMatchObject({ search: 'nig' })
  })

  it('offers the clear button only while the search holds text, and clearing drops the key', async () => {
    const { controller, sent } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    const wrapper = mountBar(controller)
    expect(wrapper.find('[data-id="hilos-table-search-clear"]').exists()).toBe(
      false,
    )

    await wrapper.find('[data-id="hilos-table-search"]').setValue('nig')
    await wrapper.find('[data-id="hilos-table-search-clear"]').trigger('click')

    expect(sent.at(-1)?.filter).not.toHaveProperty('search')
  })

  it('presses the declared main action exactly once per click', async () => {
    let pressed = 0
    const { controller } = makeController({
      title: 'Backups',
      mainAction: {
        label: 'Create backup',
        press: () => {
          pressed += 1
        },
      },
      columns: COLUMNS,
    })
    const wrapper = mountBar(controller)

    const button = wrapper.find('[data-id="hilos-table-main-action"]')
    expect(button.text()).toBe('Create backup')
    await button.trigger('click')

    expect(pressed).toBe(1)
  })

  it('draws a control per declared filter', () => {
    const { controller } = makeController(FILTERED)
    const wrapper = mountBar(controller)

    expect(wrapper.find('[data-id="hilos-table-filter-state"]').exists()).toBe(
      true,
    )
    expect(wrapper.find('[data-id="hilos-table-filter-unread"]').exists()).toBe(
      true,
    )
  })

  it('counts the filters holding a value in the badge, and never the search', async () => {
    const { controller } = makeController(FILTERED, { state: 'failed' })
    const wrapper = mountBar(controller)
    expect(wrapper.find('[data-id="hilos-table-filter-badge"]').text()).toBe(
      '1 filter',
    )

    await wrapper.find('[data-id="hilos-table-search"]').setValue('nig')
    expect(wrapper.find('[data-id="hilos-table-filter-badge"]').text()).toBe(
      '1 filter',
    )

    await wrapper.find('[data-id="hilos-table-filter-unread"]').setValue(true)
    expect(wrapper.find('[data-id="hilos-table-filter-badge"]').text()).toBe(
      '2 filters',
    )
  })

  it('shows no badge while no filter holds a value', () => {
    const { controller } = makeController(FILTERED)
    const wrapper = mountBar(controller)

    expect(wrapper.find('[data-id="hilos-table-filter-badge"]').exists()).toBe(
      false,
    )
  })

  it('resets to the filters the table opened with and clears the search with them', async () => {
    const { controller, sent } = makeController(FILTERED, { state: 'failed' })
    const wrapper = mountBar(controller)
    await wrapper.find('[data-id="hilos-table-search"]').setValue('nig')
    await wrapper.find('[data-id="hilos-table-filter-unread"]').setValue(true)

    await wrapper.find('[data-id="hilos-table-filter-reset"]').trigger('click')

    expect(sent.at(-1)?.filter).toEqual({ state: 'failed' })
    expect(
      (
        wrapper.find('[data-id="hilos-table-search"]')
          .element as HTMLInputElement
      ).value,
    ).toBe('')
  })

  it('opens the filters in a modal and closes it with Done', async () => {
    const { controller } = makeController(FILTERED)
    const wrapper = mountBar(controller)
    expect(document.querySelector('[data-id="modal"]')).toBeNull()

    await wrapper.find('[data-id="hilos-table-filters-open"]').trigger('click')
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(
      document.querySelectorAll('[data-id="hilos-table-filter-state-modal"]'),
    ).toHaveLength(1)

    document
      .querySelector<HTMLButtonElement>('[data-id="hilos-table-filters-done"]')
      ?.click()
    await wrapper.vm.$nextTick()

    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    wrapper.unmount()
  })

  it('keeps the button that opens the filters free of their number', async () => {
    const { controller } = makeController(FILTERED, { state: 'failed' })
    const wrapper = mountBar(controller)
    await wrapper.find('[data-id="hilos-table-filter-unread"]').setValue(true)

    const open = wrapper.find('[data-id="hilos-table-filters-open"]')
    expect(open.text()).toBe('Filters')
    expect(open.find('.badge').exists()).toBe(false)
  })

  it('offers no way into the filters when the table declares none, and mounts no modal either', () => {
    const { controller } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    const wrapper = mountBar(controller)

    expect(wrapper.find('[data-id="hilos-table-filters-open"]').exists()).toBe(
      false,
    )
    expect(wrapper.findComponent(HilosModal).exists()).toBe(false)
    wrapper.unmount()
  })

  it('draws no order menu at all for a table that declared no composite order', () => {
    const { controller } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    const wrapper = mountBar(controller)

    expect(wrapper.find('[data-id="hilos-table-order"]').exists()).toBe(false)
  })

  it('offers the way home first and every declared order after it', () => {
    const { controller } = makeOrdered()
    const wrapper = mountBar(controller)

    expect(
      wrapper
        .findAll('[data-id^="hilos-table-order-"]')
        .map((item) => item.text()),
    ).toEqual(['Date ↓', 'Kind ↑, then Date ↓', 'Date ↑, then Kind ↑'])
    expect(
      wrapper
        .find(`[data-id="hilos-table-order-${HILOS_TABLE_OPENING_ORDER_KEY}"]`)
        .exists(),
    ).toBe(true)
  })

  it('names the order the window runs in on the face of the button', async () => {
    const { controller } = makeOrdered()
    const wrapper = mountBar(controller)

    expect(wrapper.find('[data-id="hilos-dropdown-toggle"]').text()).toBe(
      'Order: Date ↓',
    )

    await wrapper
      .find('[data-id="hilos-table-order-by_channel"]')
      .trigger('click')
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-id="hilos-dropdown-toggle"]').text()).toBe(
      'Order: Kind ↑, then Date ↓',
    )
  })

  it('takes a declared order whole rather than a column of it', async () => {
    const { controller, sent } = makeOrdered()
    const wrapper = mountBar(controller)

    await wrapper
      .find('[data-id="hilos-table-order-by_channel"]')
      .trigger('click')

    expect(sent.at(-1)).toMatchObject({
      sort: [
        { field: 'channel', direction: 'asc' },
        { field: 'createdAt', direction: 'desc' },
      ],
    })
  })

  it('comes home through the first item, whatever order the window left in', async () => {
    const { controller, sent } = makeOrdered()
    const wrapper = mountBar(controller)

    await wrapper
      .find('[data-id="hilos-table-order-by_channel"]')
      .trigger('click')
    await wrapper.vm.$nextTick()
    await wrapper
      .find(`[data-id="hilos-table-order-${HILOS_TABLE_OPENING_ORDER_KEY}"]`)
      .trigger('click')

    expect(sent.at(-1)).toMatchObject({ sort: OPENING_ORDER })
  })

  it('asks for nothing when the item picked is the one already running', async () => {
    const { controller, sent } = makeOrdered()
    const wrapper = mountBar(controller)

    await wrapper
      .find(`[data-id="hilos-table-order-${HILOS_TABLE_OPENING_ORDER_KEY}"]`)
      .trigger('click')

    expect(sent).toEqual([])
  })

  it('marks no item at all once a header click has left an order of one column', async () => {
    const { controller } = makeOrdered()
    const wrapper = mountBar(controller)

    controller.setSort('channel')
    await wrapper.vm.$nextTick()

    expect(
      wrapper
        .findAll('[data-id^="hilos-table-order-"]')
        .filter((item) => item.classes('active')),
    ).toEqual([])
    expect(wrapper.find('[data-id="hilos-dropdown-toggle"]').text()).toBe(
      'Order: Kind ↑',
    )
  })

  it('marks an order running over a column of a frozen source and leaves it pickable', async () => {
    const sourcedColumns: readonly HilosTableColumn[] = [
      { key: 'channel', label: 'Kind', sortable: true, source: 'channels' },
      { key: 'createdAt', label: 'Date', sortable: true },
      { key: 'state', label: 'State', sortable: true },
    ]
    const orders: readonly HilosTableSortOrder[] = [
      {
        key: 'by_channel',
        components: [
          { field: 'channel', direction: 'asc' },
          { field: 'createdAt', direction: 'desc' },
        ],
      },
      {
        key: 'by_state',
        components: [
          { field: 'state', direction: 'asc' },
          { field: 'createdAt', direction: 'desc' },
        ],
      },
    ]
    const { controller, sent } = makeOrdered(sourcedColumns, orders)
    controller.ingestWindow(
      [{ rowKey: '1', slots: {}, staleSources: ['channels'] }],
      1,
      true,
      null,
      null,
      20,
    )
    const wrapper = mountBar(controller)

    const staleMark = wrapper.find(
      '[data-id="hilos-table-order-stale-by_channel"]',
    )
    expect(staleMark.exists()).toBe(true)
    expect(staleMark.classes()).toContain('bi-snow')
    expect(
      wrapper.find('[data-id="hilos-table-order-by_channel"]').text(),
    ).toContain('Sorting by this column may be wrong')

    expect(
      wrapper.find('[data-id="hilos-table-order-stale-by_state"]').exists(),
    ).toBe(false)

    await wrapper
      .find('[data-id="hilos-table-order-by_channel"]')
      .trigger('click')
    expect(sent.at(-1)).toMatchObject({
      sort: [
        { field: 'channel', direction: 'asc' },
        { field: 'createdAt', direction: 'desc' },
      ],
    })
  })

  it('marks no order when the set of frozen sources is empty', () => {
    const sourcedColumns: readonly HilosTableColumn[] = [
      { key: 'channel', label: 'Kind', sortable: true, source: 'channels' },
      { key: 'createdAt', label: 'Date', sortable: true },
      { key: 'state', label: 'State', sortable: true },
    ]
    const orders: readonly HilosTableSortOrder[] = [
      {
        key: 'by_channel',
        components: [
          { field: 'channel', direction: 'asc' },
          { field: 'createdAt', direction: 'desc' },
        ],
      },
      {
        key: 'by_state',
        components: [
          { field: 'state', direction: 'asc' },
          { field: 'createdAt', direction: 'desc' },
        ],
      },
    ]
    const { controller } = makeOrdered(sourcedColumns, orders)
    controller.ingestWindow(
      [{ rowKey: '1', slots: {}, staleSources: [] }],
      1,
      true,
      null,
      null,
      20,
    )
    const wrapper = mountBar(controller)

    expect(
      wrapper.findAll('[data-id^="hilos-table-order-stale-"]'),
    ).toHaveLength(0)
  })

  it('draws no main action when the table declares none', () => {
    const { controller } = makeController({
      title: 'Backups',
      columns: COLUMNS,
    })
    const wrapper = mountBar(controller)

    expect(wrapper.find('[data-id="hilos-table-main-action"]').exists()).toBe(
      false,
    )
  })
})

describe('HilosTableBar showing the selection panel instead of its controls', () => {
  /**
   * The sender of the declared operation, which these tests never press: what the
   * bar decides is which of the two strips stands, and the panel is tested next
   * door.
   */
  function neverRun(): ActionHandle<HilosTableBulkAccepted> {
    throw new Error('the declaration is only read here')
  }

  const BULK: HilosTableFrame = {
    title: 'Backups',
    search: {},
    columns: COLUMNS,
    bulkActions: [
      { key: 'delete', label: 'Delete', danger: true, run: neverRun },
    ],
  }

  function windowed(): TableViewportController<unknown> {
    const { controller } = makeController(BULK)
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

    return controller
  }

  /** What the bar shows right now, and that the title never leaves with it. */
  function strips(wrapper: ReturnType<typeof mountBar>): {
    controlsVisible: boolean
    panelVisible: boolean
  } {
    const title = wrapper.find('[data-id="hilos-table-title"]')
    expect(title.text()).toBe('Backups')
    expect(title.attributes('id')).toBe('table-title')

    const controls = wrapper.find('[data-id="hilos-table-controls"]')
    const panel = wrapper.find('[data-id="hilos-table-selection"]')

    return {
      controlsVisible:
        controls.exists() &&
        !controls.classes('invisible') &&
        controls.attributes('aria-hidden') === undefined,
      panelVisible:
        panel.exists() &&
        !panel.classes('invisible') &&
        panel.attributes('aria-hidden') === undefined,
    }
  }

  it('stacks the controls and panel, keeping the controls visible while nothing is marked', () => {
    const wrapper = mountBar(windowed())

    expect(wrapper.find('[data-id="hilos-table-bar-slot"]').exists()).toBe(true)
    expect(strips(wrapper)).toEqual({
      controlsVisible: true,
      panelVisible: false,
    })

    const controls = wrapper.find('[data-id="hilos-table-controls"]')
    const panel = wrapper.find('[data-id="hilos-table-selection"]')
    expect(controls.classes()).not.toContain('invisible')
    expect(controls.attributes('aria-hidden')).toBeUndefined()
    expect(panel.classes()).toContain('invisible')
    expect(panel.attributes('aria-hidden')).toBe('true')
  })

  it('a table declaring no bulk actions has no stack slot', () => {
    const noBulk: HilosTableFrame = {
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    }
    const { controller } = makeController(noBulk)
    const wrapper = mountBar(controller)

    expect(wrapper.find('[data-id="hilos-table-bar-slot"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-table-controls"]').exists()).toBe(true)
    expect(
      wrapper.find('[data-id="hilos-table-controls"]').classes(),
    ).toContain('mb-3')
    expect(wrapper.find('[data-id="hilos-table-selection"]').exists()).toBe(
      false,
    )
  })

  it('holds an open confirmation when the marks go and the strip with them', async () => {
    const controller = windowed()
    const wrapper = mountBar(controller)

    controller.selectRow('a', true)
    await wrapper.vm.$nextTick()
    await wrapper.find('[data-id="hilos-table-bulk-delete"]').trigger('click')
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()

    controller.clearSelection()
    await wrapper.vm.$nextTick()

    expect(strips(wrapper)).toEqual({
      controlsVisible: true,
      panelVisible: false,
    })
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    document.body.innerHTML = ''
    document.body.classList.remove('modal-open')
  })

  it('swaps the controls for the panel from the first marked row', async () => {
    const controller = windowed()
    const wrapper = mountBar(controller)

    controller.selectRow('a', true)
    await wrapper.vm.$nextTick()

    expect(strips(wrapper)).toEqual({
      controlsVisible: false,
      panelVisible: true,
    })
    const controls = wrapper.find('[data-id="hilos-table-controls"]')
    const panel = wrapper.find('[data-id="hilos-table-selection"]')
    expect(controls.classes()).toContain('invisible')
    expect(controls.attributes('aria-hidden')).toBe('true')
    expect(panel.classes()).not.toContain('invisible')
    expect(panel.attributes('aria-hidden')).toBeUndefined()
  })

  it('marks alone hold the panel: bulk progress and report leave controls visible when nothing is marked', async () => {
    const controller = windowed()
    const wrapper = mountBar(controller)

    controller.ingestProgress({
      scope: 'bulk',
      progressKey: 'run-1',
      current: 12,
      total: 40,
    })
    await wrapper.vm.$nextTick()

    expect(controller.selection.count.get()).toBe(0)
    expect(strips(wrapper)).toEqual({
      controlsVisible: true,
      panelVisible: false,
    })

    controller.ingestBulkReport({
      progressKey: 'run-1',
      touched: 39,
      untouched: [],
      untouchedOmitted: 0,
    })
    await wrapper.vm.$nextTick()

    expect(strips(wrapper)).toEqual({
      controlsVisible: true,
      panelVisible: false,
    })
  })
})
