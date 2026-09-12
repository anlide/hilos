import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import {
  HILOS_TABLE_OPENING_ORDER_KEY,
  TableViewportController,
} from '@hilos/core'
import type {
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
const ORDERED_COLUMNS = [
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
function makeOrdered(): {
  controller: TableViewportController<unknown>
  sent: TableViewportDescriptor[]
} {
  const made = makeController(
    { title: 'Deliveries', columns: ORDERED_COLUMNS },
    undefined,
    DECLARED_ORDERS,
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
    const modal = document.querySelector('[data-id="modal"]')
    expect(modal).not.toBeNull()
    expect(
      modal?.querySelector('[data-id="hilos-table-filter-state"]'),
    ).not.toBeNull()

    const done = modal?.querySelector<HTMLButtonElement>(
      '[data-id="hilos-table-filters-done"]',
    )
    done?.click()
    await wrapper.vm.$nextTick()

    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    wrapper.unmount()
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
