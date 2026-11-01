import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import {
  HILOS_TABLE_OPENING_ORDER_KEY,
  TABLE_VIEW_COPY,
  TableViewportController,
  subscribeSignal,
} from '@hilos/core'
import type {
  ActionHandle,
  HilosTableBulkAccepted,
  HilosTableColumn,
  HilosTableFrame,
  HilosTableSortOrder,
  HilosTableViews,
  TableSortOrder,
  TableViewportDescriptor,
} from '@hilos/core'

import { HilosTableBar } from '../src/HilosTableBar.js'

// The React port of vue/src/HilosTableBar.test.ts, under the same case names,
// for the layer the React bar draws: title, search, filters, badge, the order menu,
// main action, and the filters modal. The modal portals to <body>, so every query reads the
// document rather than the render.

const COLUMNS = [{ key: 'name', label: 'Name' }]

afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
})

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

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

function renderBar(controller: TableViewportController<unknown>) {
  return render(<HilosTableBar controller={controller} titleId="table-title" />)
}

// Every item of the order menu — its snowflakes, which carry the same prefix, left
// out.
function orderItems(): HTMLElement[] {
  return Array.from(
    document.querySelectorAll<HTMLElement>(
      '[data-id^="hilos-table-order-"]:not([data-id^="hilos-table-order-stale-"])',
    ),
  )
}

function type(id: string, value: string): void {
  fireEvent.change(byId(id) as HTMLElement, { target: { value } })
}

describe('HilosTableBar', () => {
  it.each([false, true])(
    'disables unknown subscription controls until recovery, bulk: %s',
    (bulk) => {
      const sent: TableViewportDescriptor[] = []
      const controller = new TableViewportController<unknown>({
        resolve: (row) => row,
        sendViewport: (descriptor) => sent.push(descriptor),
        initialFilter: { document: 'terms', unread: true },
        initialFilterInSubscription: true,
        frame: {
          columns: [{ key: 'name', label: 'Name', sortable: true }],
          search: {},
          filters: [
            {
              kind: 'select',
              key: 'state',
              label: 'State',
              options: () => [{ value: 'up', label: 'Up' }],
            },
            { kind: 'toggle', key: 'unread', label: 'Unread only', on: true },
            {
              kind: 'date_range',
              fromKey: 'from',
              toKey: 'to',
              label: 'Period',
            },
          ],
          mainAction: { label: 'Create', press: vi.fn() },
          ...(bulk
            ? {
                bulkActions: [
                  {
                    key: 'archive',
                    label: 'Archive',
                    refusalTitle: 'Cannot archive',
                    run: vi.fn(),
                  },
                ],
              }
            : {}),
        },
      })
      controller.ingestRefusal('internal_error')
      const view = render(
        <HilosTableBar controller={controller} titleId="table-title" />,
      )
      try {
        for (const selector of [
          '[data-id="hilos-table-search"]',
          '[data-id="hilos-table-filter-unread"]',
          '[data-id="hilos-table-filter-from"]',
          '[data-id="hilos-table-filter-state"] [data-id="hilos-dropdown-toggle"]',
          '[data-id="hilos-table-order"] [data-id="hilos-dropdown-toggle"]',
          '[data-id="hilos-table-filter-reset"]',
          '[data-id="hilos-table-filters-open"]',
        ]) {
          expect(
            (view.container.querySelector(selector) as HTMLButtonElement)
              .disabled,
          ).toBe(true)
        }
        expect(
          (byId('hilos-table-main-action') as HTMLButtonElement).disabled,
        ).toBe(false)
        expect(controller.filter.get()).toEqual({
          document: 'terms',
          unread: true,
        })
        expect(sent).toEqual([])
        act(() =>
          controller.ingestSubscriptionWindow(
            [],
            0,
            true,
            null,
            null,
            37,
            undefined,
            [],
          ),
        )
        for (const selector of [
          '[data-id="hilos-table-search"]',
          '[data-id="hilos-table-filter-unread"]',
          '[data-id="hilos-table-filter-from"]',
          '[data-id="hilos-table-filter-state"] [data-id="hilos-dropdown-toggle"]',
          '[data-id="hilos-table-order"] [data-id="hilos-dropdown-toggle"]',
          '[data-id="hilos-table-filter-reset"]',
          '[data-id="hilos-table-filters-open"]',
        ]) {
          expect(
            (view.container.querySelector(selector) as HTMLButtonElement)
              .disabled,
          ).toBe(false)
        }
        fireEvent.input(byId('hilos-table-search')!, {
          target: { value: 'retention' },
        })
        expect(sent.at(-1)).toMatchObject({
          filter: { document: 'terms', unread: true, search: 'retention' },
          limit: 37,
          sort: null,
        })
      } finally {
        view.unmount()
        controller.dispose()
      }
    },
  )

  it('shows the declared title and subtitle, the title carrying the given id', () => {
    const { controller } = makeController({
      title: 'Backups',
      subtitle: 'Every copy this installation keeps',
      columns: COLUMNS,
    })
    renderBar(controller)

    const title = byId('hilos-table-title')
    expect(title?.textContent).toBe('Backups')
    expect(title?.id).toBe('table-title')
    expect(byId('hilos-table-subtitle')?.textContent).toBe(
      'Every copy this installation keeps',
    )
  })

  it('draws no subtitle when the declaration carries none', () => {
    const { controller } = makeController({
      title: 'Backups',
      columns: COLUMNS,
    })
    renderBar(controller)

    expect(byId('hilos-table-subtitle')).toBeNull()
  })

  it('draws no heading and no subtitle when the declaration carries no title', () => {
    // The page heading names such a table; an empty h2 would be a heading with
    // nothing to say, and a subtitle would hang under a heading that is not there.
    const { controller } = makeController({
      subtitle: 'Every copy this installation keeps',
      search: {},
      columns: COLUMNS,
    })
    renderBar(controller)

    expect(byId('hilos-table-title')).toBeNull()
    expect(document.querySelector('h2')).toBeNull()
    expect(byId('hilos-table-subtitle')).toBeNull()
    expect(byId('hilos-table-search')).not.toBeNull()
  })

  it('draws no search box when the table does not declare search', () => {
    const { controller } = makeController({
      title: 'Backups',
      columns: COLUMNS,
    })
    renderBar(controller)

    expect(byId('hilos-table-search')).toBeNull()
  })

  it('names the search field with its declared placeholder', () => {
    const { controller } = makeController({
      title: 'Backups',
      search: { placeholder: 'Search backups…' },
      columns: COLUMNS,
    })
    renderBar(controller)
    const field = byId('hilos-table-search')

    expect(field?.getAttribute('placeholder')).toBe('Search backups…')
    expect(field?.getAttribute('aria-label')).toBe('Search backups…')
  })

  it('falls back to "Search…" when the declaration names no placeholder', () => {
    const { controller } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    renderBar(controller)

    expect(byId('hilos-table-search')?.getAttribute('placeholder')).toBe(
      'Search…',
    )
  })

  it('sends a window on every keystroke of the search field', () => {
    const { controller, sent } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    renderBar(controller)

    type('hilos-table-search', 'nig')

    expect(sent).toHaveLength(1)
    expect(sent.at(-1)?.filter).toMatchObject({ search: 'nig' })
  })

  it('offers the clear button only while the search holds text, and clearing drops the key', () => {
    const { controller, sent } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    renderBar(controller)
    expect(byId('hilos-table-search-clear')).toBeNull()

    type('hilos-table-search', 'nig')
    fireEvent.click(byId('hilos-table-search-clear') as HTMLElement)

    expect(sent.at(-1)?.filter).not.toHaveProperty('search')
  })

  it('presses the declared main action exactly once per click', () => {
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
    renderBar(controller)

    const button = byId('hilos-table-main-action') as HTMLElement
    expect(button.textContent).toBe('Create backup')
    fireEvent.click(button)

    expect(pressed).toBe(1)
  })

  it('draws a control per declared filter', () => {
    const { controller } = makeController(FILTERED)
    renderBar(controller)

    expect(byId('hilos-table-filter-state')).not.toBeNull()
    expect(byId('hilos-table-filter-unread')).not.toBeNull()
  })

  it('counts the filters holding a value in the badge, and never the search', () => {
    const { controller } = makeController(FILTERED, { state: 'failed' })
    renderBar(controller)
    expect(byId('hilos-table-filter-badge')?.textContent).toBe('1 filter')

    type('hilos-table-search', 'nig')
    expect(byId('hilos-table-filter-badge')?.textContent).toBe('1 filter')

    fireEvent.click(byId('hilos-table-filter-unread') as HTMLElement)
    expect(byId('hilos-table-filter-badge')?.textContent).toBe('2 filters')
  })

  it('shows no badge while no filter holds a value', () => {
    const { controller } = makeController(FILTERED)
    renderBar(controller)

    expect(byId('hilos-table-filter-badge')).toBeNull()
  })

  it('resets to the filters the table opened with and clears the search with them', () => {
    const { controller, sent } = makeController(FILTERED, { state: 'failed' })
    renderBar(controller)
    type('hilos-table-search', 'nig')
    fireEvent.click(byId('hilos-table-filter-unread') as HTMLElement)

    fireEvent.click(byId('hilos-table-filter-reset') as HTMLElement)

    expect(sent.at(-1)?.filter).toEqual({ state: 'failed' })
    expect((byId('hilos-table-search') as HTMLInputElement).value).toBe('')
  })

  it('opens the filters in a modal and closes it with Done', () => {
    const { controller } = makeController(FILTERED)
    renderBar(controller)
    expect(byId('modal')).toBeNull()

    fireEvent.click(byId('hilos-table-filters-open') as HTMLElement)
    expect(byId('modal')).not.toBeNull()
    expect(
      document.querySelectorAll('[data-id="hilos-table-filter-state-modal"]'),
    ).toHaveLength(1)

    act(() => {
      byId('hilos-table-filters-done')?.click()
    })

    expect(byId('modal')).toBeNull()
  })

  it('keeps the button that opens the filters free of their number', () => {
    const { controller } = makeController(FILTERED, { state: 'failed' })
    renderBar(controller)
    fireEvent.click(byId('hilos-table-filter-unread') as HTMLElement)

    const open = byId('hilos-table-filters-open') as HTMLElement
    expect(open.textContent).toBe('Filters')
    expect(open.querySelector('.badge')).toBeNull()
  })

  it('offers no way into the filters when the table declares none, and mounts no modal either', () => {
    const { controller } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    renderBar(controller)

    expect(byId('hilos-table-filters-open')).toBeNull()
    expect(byId('modal')).toBeNull()
  })

  it('draws no order menu at all for a table with neither a sortable column nor a composite order', () => {
    const { controller } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    renderBar(controller)

    expect(byId('hilos-table-order')).toBeNull()
  })

  it('offers a table with sortable columns and no composite order a menu below md alone', () => {
    const { controller } = makeOrdered(ORDERED_COLUMNS, [])
    renderBar(controller)

    // Above md the header gives every one of these, so the menu, and the bar holding
    // nothing else, hide there rather than stand as an empty row.
    expect(byId('hilos-table-order')?.classList.contains('d-md-none')).toBe(
      true,
    )
    expect(byId('hilos-table-controls')?.classList.contains('d-md-none')).toBe(
      true,
    )
    expect(
      orderItems().map((item) => [
        item.textContent,
        item.classList.contains('d-md-none'),
      ]),
    ).toEqual([
      ['Date ↓', true],
      ['Kind ↑', true],
      ['Kind ↓', true],
      ['Date ↑', true],
    ])
  })

  it('keeps the bar above md when it holds more than a menu for the narrow screen', () => {
    const { controller } = makeController({
      title: 'Settings',
      search: {},
      columns: ORDERED_COLUMNS,
    })
    renderBar(controller)

    expect(byId('hilos-table-order')?.classList.contains('d-md-none')).toBe(
      true,
    )
    expect(byId('hilos-table-controls')?.classList.contains('d-md-none')).toBe(
      false,
    )
  })

  it('hides the items of one column above md and keeps the composite orders on both widths', () => {
    const { controller } = makeOrdered()
    renderBar(controller)
    const narrowOnly = (key: string): boolean | undefined =>
      byId(`hilos-table-order-${key}`)?.classList.contains('d-md-none')

    expect(byId('hilos-table-order')?.classList.contains('d-md-none')).toBe(
      false,
    )
    expect(narrowOnly(HILOS_TABLE_OPENING_ORDER_KEY)).toBe(false)
    expect(narrowOnly('channel-asc')).toBe(true)
    expect(narrowOnly('createdAt-asc')).toBe(true)
    expect(narrowOnly('by_channel')).toBe(false)
    expect(narrowOnly('by_channel-mirror')).toBe(false)
  })

  it('runs the window by one column when its item is picked', () => {
    const { controller, sent } = makeOrdered(ORDERED_COLUMNS, [])
    renderBar(controller)

    fireEvent.click(byId('hilos-table-order-channel-desc') as HTMLElement)

    expect(sent.at(-1)).toMatchObject({
      sort: [{ field: 'channel', direction: 'desc' }],
    })
  })

  it('offers the way home first, then the columns both ways, then every declared order with its mirror', () => {
    const { controller } = makeOrdered()
    renderBar(controller)

    // The columns stand in the order they are declared, the one the table opened in
    // left out — the way home names it. The mirror is the framework's: the same
    // columns with every direction turned, offered right after the order it mirrors,
    // and declared by no table (HIL-1095).
    expect(orderItems().map((item) => item.textContent)).toEqual([
      'Date ↓',
      'Kind ↑',
      'Kind ↓',
      'Date ↑',
      'Kind ↑, then Date ↓',
      'Kind ↓, then Date ↑',
      'Date ↑, then Kind ↑',
      'Date ↓, then Kind ↓',
    ])
    expect(
      byId(`hilos-table-order-${HILOS_TABLE_OPENING_ORDER_KEY}`),
    ).not.toBeNull()
  })

  it('names the order the window runs in on the face of the button', () => {
    const { controller } = makeOrdered()
    renderBar(controller)

    expect(byId('hilos-dropdown-toggle')?.textContent).toBe('Order: Date ↓')

    fireEvent.click(byId('hilos-table-order-by_channel') as HTMLElement)

    expect(byId('hilos-dropdown-toggle')?.textContent).toBe(
      'Order: Kind ↑, then Date ↓',
    )
  })

  it('takes a declared order whole rather than a column of it', () => {
    const { controller, sent } = makeOrdered()
    renderBar(controller)

    fireEvent.click(byId('hilos-table-order-by_channel') as HTMLElement)

    expect(sent.at(-1)).toMatchObject({
      sort: [
        { field: 'channel', direction: 'asc' },
        { field: 'createdAt', direction: 'desc' },
      ],
    })
  })

  it('comes home through the first item, whatever order the window left in', () => {
    const { controller, sent } = makeOrdered()
    renderBar(controller)

    fireEvent.click(byId('hilos-table-order-by_channel') as HTMLElement)
    fireEvent.click(
      byId(`hilos-table-order-${HILOS_TABLE_OPENING_ORDER_KEY}`) as HTMLElement,
    )

    expect(sent.at(-1)).toMatchObject({ sort: OPENING_ORDER })
  })

  it('asks for nothing when the item picked is the one already running', () => {
    const { controller, sent } = makeOrdered()
    renderBar(controller)

    fireEvent.click(
      byId(`hilos-table-order-${HILOS_TABLE_OPENING_ORDER_KEY}`) as HTMLElement,
    )

    expect(sent).toEqual([])
  })

  it('marks the item of the column, hidden above md, once a header click has left an order of one column', () => {
    const { controller } = makeOrdered()
    renderBar(controller)

    act(() => controller.setSort('channel'))

    const active = orderItems().filter((item) =>
      item.classList.contains('active'),
    )
    expect(active.map((item) => item.dataset['id'])).toEqual([
      'hilos-table-order-channel-asc',
    ])
    expect(active[0]?.classList.contains('d-md-none')).toBe(true)
    expect(byId('hilos-dropdown-toggle')?.textContent).toBe('Order: Kind ↑')
  })

  it('marks an order running over a column of a frozen source and leaves it pickable', () => {
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
    renderBar(controller)

    const staleMark = byId('hilos-table-order-stale-by_channel')
    expect(staleMark).not.toBeNull()
    expect(staleMark?.classList.contains('bi-snow')).toBe(true)
    expect(byId('hilos-table-order-by_channel')?.textContent).toContain(
      'Sorting by this column may be wrong',
    )

    expect(byId('hilos-table-order-stale-by_state')).toBeNull()
    // The column of the frozen source carries the mark on its own items too.
    expect(byId('hilos-table-order-stale-channel-desc')).not.toBeNull()
    expect(byId('hilos-table-order-stale-state-asc')).toBeNull()

    fireEvent.click(byId('hilos-table-order-by_channel') as HTMLElement)
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
    renderBar(controller)

    expect(
      document.querySelectorAll('[data-id^="hilos-table-order-stale-"]'),
    ).toHaveLength(0)
  })

  it('draws no main action when the table declares none', () => {
    const { controller } = makeController({
      title: 'Backups',
      columns: COLUMNS,
    })
    renderBar(controller)

    expect(byId('hilos-table-main-action')).toBeNull()
  })
})

// The Table/Tiles choice of a table that declares views (HIL-1761). The bar only
// draws it and calls the controller; what the window does with the layout is the
// table body's, tested beside it.
const BULK_ARCHIVE = {
  bulkActions: [
    {
      key: 'archive',
      label: 'Archive',
      refusalTitle: 'Cannot archive',
      run: () => {
        throw new Error('Not pressed')
      },
    },
  ],
} satisfies Partial<HilosTableFrame>

function viewsFrame(
  extra: Partial<HilosTableFrame> = {},
  order: HilosTableViews['order'] = ['table', 'tiles'],
): HilosTableFrame {
  return {
    title: 'Pictures',
    columns: COLUMNS,
    views: { tableKey: 'pictures', order, imageColumn: 'name' },
    ...extra,
  }
}

function viewGroup(): HTMLElement {
  return screen.getByRole('group', { name: TABLE_VIEW_COPY.group })
}

function viewButtons(): HTMLButtonElement[] {
  return Array.from(viewGroup().querySelectorAll('button'))
}

describe('HilosTableBar views', () => {
  it.each([false, true])(
    'keeps one native view group with no other controls, bulk enabled: %s',
    (bulk) => {
      const { controller, sent } = makeController(
        viewsFrame(bulk ? BULK_ARCHIVE : {}),
      )
      controller.ingestWindow(
        [{ rowKey: 'a', slots: { name: 'A' } }],
        1,
        true,
        null,
        null,
        10,
      )
      const rendered = renderBar(controller)

      const group = viewGroup()
      expect(group.getAttribute('data-id')).toBe('hilos-table-view')
      expect(group.getAttribute('aria-label')).toBe(TABLE_VIEW_COPY.group)
      expect(
        viewButtons().map((button) => [
          button.textContent,
          button.type,
          button.getAttribute('aria-pressed'),
          button.getAttribute('data-id'),
          button.disabled,
        ]),
      ).toEqual([
        ['Table', 'button', 'true', 'hilos-table-view-table', false],
        ['Tiles', 'button', 'false', 'hilos-table-view-tiles', false],
      ])
      expect(screen.getByRole('button', { name: TABLE_VIEW_COPY.tiles })).toBe(
        byId('hilos-table-view-tiles'),
      )
      expect(
        Array.from(group.querySelectorAll('i')).map((icon) => [
          icon.classList.contains('bi-list-ul'),
          icon.classList.contains('bi-grid-3x2-gap'),
          icon.getAttribute('aria-hidden'),
        ]),
      ).toEqual([
        [true, false, 'true'],
        [false, true, 'true'],
      ])
      if (bulk) {
        // With rows marked the controls go invisible and aria-hidden, and the
        // choice must not go with them: it stands beside the stack, not in it.
        act(() => controller.selectRow('a', true))
        expect(byId('hilos-table-selection')?.classList).not.toContain(
          'invisible',
        )
        expect(group.closest('.invisible, [aria-hidden="true"], [inert]')).toBe(
          null,
        )
        expect(byId('hilos-table-bar-slot')?.contains(group)).toBe(false)
        expect(byId('hilos-table-controls')).toBeNull()
      } else {
        expect(byId('hilos-table-controls')?.contains(group)).toBe(true)
      }

      const tiles = byId('hilos-table-view-tiles') as HTMLButtonElement
      tiles.focus()
      fireEvent.click(tiles)
      // The same button, not a remounted one: the focus stays where it was.
      expect(byId('hilos-table-view-tiles')).toBe(tiles)
      expect(document.activeElement).toBe(tiles)
      expect(controller.frame.view.get()).toBe('tiles')
      expect(tiles.getAttribute('aria-pressed')).toBe('true')
      expect(byId('hilos-table-view-table')?.getAttribute('aria-pressed')).toBe(
        'false',
      )
      expect(
        document.querySelectorAll('[data-id="hilos-table-view"]'),
      ).toHaveLength(1)
      expect(sent).toEqual([])
      expect(controller.selection.count.get()).toBe(bulk ? 1 : 0)
      rendered.unmount()
      controller.dispose()
    },
  )

  it.each([
    {
      order: ['table', 'tiles'] as HilosTableViews['order'],
      words: ['Table', 'Tiles'],
    },
    {
      order: ['tiles', 'table'] as HilosTableViews['order'],
      words: ['Tiles', 'Table'],
    },
  ])(
    'follows the declared order $order and opens on its first view',
    ({ order, words }) => {
      const { controller, sent } = makeController(viewsFrame({}, order))
      const rendered = renderBar(controller)

      const state = () =>
        viewButtons().map((button) => [
          button.textContent,
          button.getAttribute('aria-pressed'),
        ])
      expect(state()).toEqual([
        [words[0], 'true'],
        [words[1], 'false'],
      ])

      fireEvent.click(viewButtons()[1] as HTMLElement)
      expect(controller.frame.view.get()).toBe(order[1])
      expect(state()).toEqual([
        [words[0], 'false'],
        [words[1], 'true'],
      ])

      fireEvent.click(viewButtons()[0] as HTMLElement)
      expect(controller.frame.view.get()).toBe(order[0])
      expect(state()).toEqual([
        [words[0], 'true'],
        [words[1], 'false'],
      ])
      expect(sent).toEqual([])
      rendered.unmount()
      controller.dispose()
    },
  )

  it('does nothing when the view already shown is pressed again', () => {
    const { controller, sent } = makeController(viewsFrame())
    const rendered = renderBar(controller)
    const changes: string[] = []
    const stop = subscribeSignal(controller.frame.view, () =>
      changes.push(controller.frame.view.get()),
    )

    fireEvent.click(byId('hilos-table-view-table') as HTMLElement)
    expect(changes).toEqual([])
    expect(byId('hilos-table-view-table')?.getAttribute('aria-pressed')).toBe(
      'true',
    )

    fireEvent.click(byId('hilos-table-view-tiles') as HTMLElement)
    fireEvent.click(byId('hilos-table-view-tiles') as HTMLElement)
    expect(changes).toEqual(['tiles'])
    expect(byId('hilos-table-view-tiles')?.getAttribute('aria-pressed')).toBe(
      'true',
    )
    expect(sent).toEqual([])
    stop()
    rendered.unmount()
    controller.dispose()
  })

  it('leaves search, filter, order and marks alone, and asks the server for nothing', () => {
    const { controller, sent } = makeController(
      viewsFrame({
        ...BULK_ARCHIVE,
        search: {},
        filters: [{ kind: 'toggle', key: 'unread', label: 'Unread', on: true }],
      }),
    )
    controller.ingestWindow(
      [
        { rowKey: 'a', slots: { name: 'A' } },
        { rowKey: 'b', slots: { name: 'B' } },
      ],
      2,
      true,
      null,
      null,
      10,
    )
    const rendered = renderBar(controller)
    act(() => controller.setSearch('needle'))
    act(() => controller.selectRow('b', true))
    const asked = sent.length
    const filter = controller.filter.get()
    const orderLabel = controller.frame.orderLabel.get()

    fireEvent.click(byId('hilos-table-view-tiles') as HTMLElement)
    fireEvent.click(byId('hilos-table-view-table') as HTMLElement)
    fireEvent.click(byId('hilos-table-view-tiles') as HTMLElement)

    expect(sent).toHaveLength(asked)
    expect(controller.search.get()).toBe('needle')
    expect(controller.filter.get()).toEqual(filter)
    expect(controller.frame.orderLabel.get()).toBe(orderLabel)
    expect(controller.selection.count.get()).toBe(1)
    expect(controller.frame.view.get()).toBe('tiles')
    rendered.unmount()
    controller.dispose()
  })

  it.each(['empty', 'empty_filtered'] as const)(
    'keeps the choice available when the body is %s',
    (body) => {
      const { controller, sent } = makeController(
        viewsFrame({ search: {} }),
        body === 'empty_filtered' ? { search: 'missing' } : undefined,
      )
      controller.ingestWindow([], 0, true, null, null, 10)
      expect(controller.frame.body.get()).toBe(body)
      const rendered = renderBar(controller)

      expect(viewButtons().map((button) => button.disabled)).toEqual([
        false,
        false,
      ])
      fireEvent.click(byId('hilos-table-view-tiles') as HTMLElement)
      expect(controller.frame.view.get()).toBe('tiles')
      expect(sent).toEqual([])
      rendered.unmount()
      controller.dispose()
    },
  )

  it('keeps the choice enabled while the window controls wait for recovery', () => {
    const sent: TableViewportDescriptor[] = []
    const controller = new TableViewportController<unknown>({
      resolve: (row) => row,
      sendViewport: (descriptor) => sent.push(descriptor),
      initialFilter: { unread: true },
      initialFilterInSubscription: true,
      frame: viewsFrame({
        search: {},
        filters: [
          { kind: 'toggle', key: 'unread', label: 'Unread only', on: true },
        ],
      }),
    })
    controller.ingestRefusal('internal_error')
    const rendered = renderBar(controller)

    expect((byId('hilos-table-search') as HTMLInputElement).disabled).toBe(true)
    expect(viewButtons().map((button) => button.disabled)).toEqual([
      false,
      false,
    ])
    fireEvent.click(byId('hilos-table-view-tiles') as HTMLElement)
    expect(controller.frame.view.get()).toBe('tiles')
    expect(sent).toEqual([])
    rendered.unmount()
    controller.dispose()
  })

  it.each([false, true])(
    'offers no view group without a views declaration, bulk enabled: %s',
    (bulk) => {
      const { controller } = makeController({
        columns: COLUMNS,
        search: {},
        ...(bulk ? BULK_ARCHIVE : {}),
      })
      const rendered = renderBar(controller)

      expect(byId('hilos-table-view')).toBeNull()
      expect(
        document.querySelector('[data-id^="hilos-table-view-"]'),
      ).toBeNull()
      expect(
        screen.queryByRole('group', { name: TABLE_VIEW_COPY.group }),
      ).toBeNull()
      expect(byId('hilos-table-search')).not.toBeNull()
      expect(byId('hilos-table-bar-slot') !== null).toBe(bulk)
      rendered.unmount()
      controller.dispose()
    },
  )

  it('draws neither controls nor group for a bare table', () => {
    const { controller } = makeController({ columns: COLUMNS })
    renderBar(controller)

    expect(byId('hilos-table-view')).toBeNull()
    expect(byId('hilos-table-controls')).toBeNull()
  })

  it('shows all core order options at every width in Tiles and restores narrow-only items in Table', () => {
    const { controller, sent } = makeController(
      {
        columns: ORDERED_COLUMNS,
        views: {
          tableKey: 'ordered-pictures',
          order: ['table', 'tiles'],
          imageColumn: 'channel',
        },
      },
      undefined,
      [],
    )
    controller.ingestSubscriptionWindow(
      [],
      0,
      true,
      null,
      null,
      20,
      OPENING_ORDER,
      [],
    )
    const rendered = renderBar(controller)
    const narrowOnly = () =>
      orderItems().map((item) => item.classList.contains('d-md-none'))

    expect(narrowOnly()).toEqual([true, true, true, true])
    // The choice of view keeps the strip on display above md: it is a control
    // there, where a bar of the order menu alone would hide with it.
    expect(byId('hilos-table-controls')?.classList.contains('d-md-none')).toBe(
      false,
    )

    fireEvent.click(byId('hilos-table-view-tiles') as HTMLElement)
    expect(orderItems().map((item) => item.textContent)).toEqual([
      'Date ↓',
      'Kind ↑',
      'Kind ↓',
      'Date ↑',
    ])
    expect(narrowOnly()).toEqual([false, false, false, false])
    expect(byId('hilos-table-order')?.classList.contains('d-md-none')).toBe(
      false,
    )

    // An order picked in Tiles is the ordinary one: the common window is
    // re-sorted by a request, the same as from the table.
    fireEvent.click(byId('hilos-table-order-channel-asc') as HTMLElement)
    expect(sent.at(-1)).toMatchObject({
      sort: [{ field: 'channel', direction: 'asc' }],
    })

    fireEvent.click(byId('hilos-table-view-table') as HTMLElement)
    expect(narrowOnly()).toEqual([true, true, true, true])
    rendered.unmount()
    controller.dispose()
  })

  it('still hides a bar of the narrow-only order menu above md without views', () => {
    const { controller } = makeController(
      { columns: ORDERED_COLUMNS },
      undefined,
      [],
    )
    controller.ingestSubscriptionWindow(
      [],
      0,
      true,
      null,
      null,
      20,
      OPENING_ORDER,
      [],
    )
    renderBar(controller)

    expect(byId('hilos-table-controls')?.classList.contains('d-md-none')).toBe(
      true,
    )
    expect(byId('hilos-table-view')).toBeNull()
  })
})

/** A table declaring views beside list actions, a main action and a search box. */
function makeBoth(bulk: boolean) {
  const copy = vi.fn()
  const download = vi.fn()
  const main = vi.fn()
  const { controller, sent } = makeController(
    viewsFrame({
      search: {},
      auxiliaryActions: [
        { key: 'copy', label: 'Copy the list', press: copy },
        { key: 'download', label: 'Download as a file', press: download },
      ],
      mainAction: { label: 'Create', press: main },
      ...(bulk ? BULK_ARCHIVE : {}),
    }),
  )
  controller.ingestWindow(
    [{ rowKey: 'a', slots: { name: 'A' } }],
    1,
    true,
    null,
    null,
    10,
  )

  return { controller, sent, copy, download, main }
}

describe.each([false, true])(
  'HilosTableBar views next to list actions, bulk enabled: %s',
  (bulk) => {
    it('keeps both named groups, each in its own place', () => {
      const { controller, copy, download, main } = makeBoth(bulk)
      const rendered = renderBar(controller)

      const views = viewGroup()
      const actions = screen.getByRole('group', { name: 'List actions' })
      const mainAction = byId('hilos-table-main-action') as HTMLElement
      expect(actions.getAttribute('data-id')).toBe('hilos-table-actions')
      const follows = (first: HTMLElement, second: HTMLElement) =>
        (first.compareDocumentPosition(second) &
          Node.DOCUMENT_POSITION_FOLLOWING) !==
        0
      if (bulk) {
        // The choice stands beside the stack that holds the controls, after it.
        expect(follows(actions, views)).toBe(true)
        expect(follows(mainAction, views)).toBe(true)
      } else {
        // In the bar alone the choice comes after the filters and before the
        // list actions and the main action.
        expect(follows(views, actions)).toBe(true)
      }
      expect(follows(actions, mainAction)).toBe(true)
      expect(views.contains(actions) || actions.contains(views)).toBe(false)
      expect(
        Array.from(actions.querySelectorAll('button')).map(
          (button) => button.textContent,
        ),
      ).toEqual(['Copy the list', 'Download as a file'])
      expect(viewButtons().map((button) => button.textContent)).toEqual([
        'Table',
        'Tiles',
      ])
      expect(copy).not.toHaveBeenCalled()
      expect(download).not.toHaveBeenCalled()
      expect(main).not.toHaveBeenCalled()
      rendered.unmount()
      controller.dispose()
    })

    it('presses only its own group', () => {
      const { controller, sent, copy, download, main } = makeBoth(bulk)
      const rendered = renderBar(controller)

      fireEvent.click(byId('hilos-table-view-tiles') as HTMLElement)
      expect(controller.frame.view.get()).toBe('tiles')
      expect(copy).not.toHaveBeenCalled()
      expect(download).not.toHaveBeenCalled()
      expect(main).not.toHaveBeenCalled()

      fireEvent.click(byId('hilos-table-action-copy') as HTMLElement)
      fireEvent.click(byId('hilos-table-main-action') as HTMLElement)
      expect(copy).toHaveBeenCalledTimes(1)
      expect(download).not.toHaveBeenCalled()
      expect(main).toHaveBeenCalledTimes(1)
      expect(controller.frame.view.get()).toBe('tiles')
      expect(byId('hilos-table-view-tiles')?.getAttribute('aria-pressed')).toBe(
        'true',
      )
      expect(sent).toEqual([])
      rendered.unmount()
      controller.dispose()
    })

    it('keeps the view choice when list actions are declared empty', () => {
      const { controller } = makeController(
        viewsFrame({ auxiliaryActions: [], ...(bulk ? BULK_ARCHIVE : {}) }),
      )
      const rendered = renderBar(controller)

      expect(byId('hilos-table-actions')).toBeNull()
      expect(viewButtons()).toHaveLength(2)
      rendered.unmount()
      controller.dispose()
    })
  },
)

describe('HilosTableBar views while rows are marked', () => {
  it('keeps the view choice reachable while the marked rows hide the controls and list actions', () => {
    const { controller, sent } = makeBoth(true)
    const rendered = renderBar(controller)

    act(() => controller.selectRow('a', true))

    const controls = byId('hilos-table-controls') as HTMLElement
    expect(controls.getAttribute('aria-hidden')).toBe('true')
    expect(controls.contains(byId('hilos-table-actions'))).toBe(true)
    expect(controls.contains(viewGroup())).toBe(false)
    expect(
      viewGroup().closest('.invisible, [aria-hidden="true"], [inert]'),
    ).toBeNull()
    fireEvent.click(byId('hilos-table-view-tiles') as HTMLElement)
    expect(controller.frame.view.get()).toBe('tiles')
    expect(controller.selection.count.get()).toBe(1)
    expect(sent).toEqual([])
    rendered.unmount()
    controller.dispose()
  })
})

describe.each([false, true])(
  'HilosTableBar list actions, bulk enabled: %s',
  (bulk) => {
    function makeActions() {
      const copy = vi.fn()
      const download = vi.fn()
      const frame: HilosTableFrame = {
        columns: COLUMNS,
        auxiliaryActions: [
          {
            key: 'copy',
            label: 'Copy the list',
            icon: 'bi-clipboard',
            press: copy,
          },
          {
            key: 'download',
            label: 'Download as a file',
            icon: 'bi-download',
            press: download,
          },
        ],
        ...(bulk
          ? {
              bulkActions: [
                {
                  key: 'archive',
                  label: 'Archive',
                  refusalTitle: 'Cannot archive',
                  run: () => {
                    throw new Error('Not pressed')
                  },
                },
              ],
            }
          : {}),
      }

      return { frame, copy, download }
    }

    it('offers a named group of buttons and calls only the pressed action', () => {
      const { frame, copy, download } = makeActions()
      const { controller, sent } = makeController(frame)
      const rendered = renderBar(controller)

      const group = screen.getByRole('group', { name: 'List actions' })
      expect(group.getAttribute('data-id')).toBe('hilos-table-actions')
      expect(
        Array.from(group.querySelectorAll('button')).map((button) => [
          button.textContent,
          button.type,
          button.getAttribute('data-id'),
          button.disabled,
        ]),
      ).toEqual([
        ['Copy the list', 'button', 'hilos-table-action-copy', false],
        ['Download as a file', 'button', 'hilos-table-action-download', false],
      ])
      expect(
        Array.from(group.querySelectorAll('i')).map((icon) =>
          icon.getAttribute('aria-hidden'),
        ),
      ).toEqual(['true', 'true'])
      expect(screen.getByRole('button', { name: 'Copy the list' })).toBeTruthy()
      expect(
        screen.getByRole('button', { name: 'Download as a file' }),
      ).toBeTruthy()
      expect(copy).not.toHaveBeenCalled()
      expect(download).not.toHaveBeenCalled()
      expect(byId('hilos-table-search')).toBeNull()
      expect(byId('hilos-table-main-action')).toBeNull()

      fireEvent.click(byId('hilos-table-action-copy') as HTMLElement)
      expect(copy).toHaveBeenCalledTimes(1)
      expect(download).not.toHaveBeenCalled()

      fireEvent.click(byId('hilos-table-action-download') as HTMLElement)
      expect(copy).toHaveBeenCalledTimes(1)
      expect(download).toHaveBeenCalledTimes(1)
      expect(sent).toEqual([])
      rendered.unmount()
      controller.dispose()
    })

    it.each(['empty', 'empty_filtered'] as const)(
      'keeps both list actions available when the body is %s',
      (body) => {
        const { frame, copy, download } = makeActions()
        const { controller } = makeController(
          { ...frame, search: {} },
          body === 'empty_filtered' ? { search: 'missing' } : undefined,
        )
        controller.ingestWindow([], 0, true, null, null, 10)
        expect(controller.frame.body.get()).toBe(body)
        const rendered = renderBar(controller)

        expect(copy).not.toHaveBeenCalled()
        expect(download).not.toHaveBeenCalled()
        for (const key of ['copy', 'download']) {
          const button = screen.getByRole('button', {
            name: key === 'copy' ? 'Copy the list' : 'Download as a file',
          })
          expect(button).toBeTruthy()
          expect((button as HTMLButtonElement).disabled).toBe(false)
          fireEvent.click(button)
        }
        expect(copy).toHaveBeenCalledTimes(1)
        expect(download).toHaveBeenCalledTimes(1)
        rendered.unmount()
        controller.dispose()
      },
    )

    it('keeps list actions in the bar while the mobile filters open and close', () => {
      const { frame, copy, download } = makeActions()
      const { controller } = makeController({
        ...FILTERED,
        ...frame,
        columns: COLUMNS,
      })
      const rendered = renderBar(controller)
      const group = byId('hilos-table-actions')
      expect(group).not.toBeNull()

      fireEvent.click(byId('hilos-table-filters-open') as HTMLElement)
      const dialog = byId('modal')
      expect(dialog).not.toBeNull()
      expect(
        dialog?.querySelector('[data-id="hilos-table-actions"]'),
      ).toBeNull()
      expect(byId('hilos-table-actions')).toBe(group)
      expect(copy).not.toHaveBeenCalled()
      expect(download).not.toHaveBeenCalled()

      fireEvent.click(byId('hilos-table-filters-done') as HTMLElement)
      expect(byId('modal')).toBeNull()
      expect(byId('hilos-table-actions')).toBe(group)
      fireEvent.click(byId('hilos-table-action-copy') as HTMLElement)
      expect(copy).toHaveBeenCalledTimes(1)
      expect(download).not.toHaveBeenCalled()
      rendered.unmount()
      controller.dispose()
    })

    it('keeps the main action independent of list actions', () => {
      const { frame, copy, download } = makeActions()
      const main = vi.fn()
      const { controller } = makeController({
        ...frame,
        mainAction: { label: 'Create backup', press: main },
      })
      const rendered = renderBar(controller)

      expect(byId('hilos-table-main-action')?.textContent).toBe('Create backup')
      fireEvent.click(byId('hilos-table-main-action') as HTMLElement)
      expect(main).toHaveBeenCalledTimes(1)
      expect(copy).not.toHaveBeenCalled()
      expect(download).not.toHaveBeenCalled()

      fireEvent.click(byId('hilos-table-action-copy') as HTMLElement)
      expect(copy).toHaveBeenCalledTimes(1)
      expect(download).not.toHaveBeenCalled()
      rendered.unmount()
      controller.dispose()
    })

    it.each([undefined, []])(
      'omits the group and preserves main action for auxiliaryActions=%j',
      (auxiliaryActions) => {
        const { frame } = makeActions()
        const main = vi.fn()
        const { controller } = makeController({
          ...frame,
          auxiliaryActions,
          mainAction: { label: 'Create backup', press: main },
        })
        const rendered = renderBar(controller)

        expect(byId('hilos-table-actions')).toBeNull()
        expect(byId('hilos-table-main-action')?.textContent).toBe(
          'Create backup',
        )
        fireEvent.click(byId('hilos-table-main-action') as HTMLElement)
        expect(main).toHaveBeenCalledTimes(1)
        rendered.unmount()
        controller.dispose()
      },
    )

    it('creates no empty group or controls for an empty action array', () => {
      const { frame } = makeActions()
      const { controller } = makeController({ ...frame, auxiliaryActions: [] })
      const rendered = renderBar(controller)

      expect(byId('hilos-table-actions')).toBeNull()
      expect(byId('hilos-table-controls')).toBeNull()
      rendered.unmount()
      controller.dispose()
    })

    it('renders a text-only action without a decorative icon', () => {
      const { frame } = makeActions()
      const press = vi.fn()
      const { controller } = makeController({
        ...frame,
        auxiliaryActions: [{ key: 'copy', label: 'Copy the list', press }],
      })
      const rendered = renderBar(controller)

      const button = screen.getByRole('button', { name: 'Copy the list' })
      expect(button.querySelector('i')).toBeNull()
      expect(press).not.toHaveBeenCalled()
      fireEvent.click(button)
      expect(press).toHaveBeenCalledTimes(1)
      rendered.unmount()
      controller.dispose()
    })
  },
)

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
      {
        key: 'delete',
        label: 'Delete',
        refusalTitle: "Couldn't delete",
        danger: true,
        run: neverRun,
      },
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
  function strips(): {
    controlsVisible: boolean
    panelVisible: boolean
  } {
    const title = byId('hilos-table-title')
    expect(title?.textContent).toBe('Backups')
    expect(title?.id).toBe('table-title')

    const controls = byId('hilos-table-controls')
    const panel = byId('hilos-table-selection')

    return {
      controlsVisible:
        controls !== null &&
        !controls.classList.contains('invisible') &&
        controls.getAttribute('aria-hidden') === null,
      panelVisible:
        panel !== null &&
        !panel.classList.contains('invisible') &&
        panel.getAttribute('aria-hidden') === null,
    }
  }

  it('stacks the controls and panel, keeping the controls visible while nothing is marked', () => {
    renderBar(windowed())

    expect(byId('hilos-table-bar-slot')).not.toBeNull()
    expect(strips()).toEqual({ controlsVisible: true, panelVisible: false })

    const controls = byId('hilos-table-controls')
    const panel = byId('hilos-table-selection')
    expect(controls?.classList.contains('invisible')).toBe(false)
    expect(controls?.getAttribute('aria-hidden')).toBeNull()
    expect(panel?.classList.contains('invisible')).toBe(true)
    expect(panel?.getAttribute('aria-hidden')).toBe('true')
  })

  it('a table declaring no bulk actions has no stack slot', () => {
    const noBulk: HilosTableFrame = {
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    }
    const { controller } = makeController(noBulk)
    renderBar(controller)

    expect(byId('hilos-table-bar-slot')).toBeNull()
    expect(byId('hilos-table-controls')).not.toBeNull()
    expect(byId('hilos-table-controls')?.classList.contains('mb-3')).toBe(true)
    expect(byId('hilos-table-selection')).toBeNull()
  })

  it('holds an open confirmation when the marks go and the strip with them', () => {
    const controller = windowed()
    renderBar(controller)

    act(() => controller.selectRow('a', true))
    fireEvent.click(byId('hilos-table-bulk-delete') as HTMLElement)
    expect(byId('modal')).not.toBeNull()

    act(() => controller.clearSelection())

    expect(strips()).toEqual({ controlsVisible: true, panelVisible: false })
    expect(byId('modal')).not.toBeNull()
  })

  it('swaps the controls for the panel from the first marked row', () => {
    const controller = windowed()
    renderBar(controller)

    act(() => controller.selectRow('a', true))

    expect(strips()).toEqual({ controlsVisible: false, panelVisible: true })
    const controls = byId('hilos-table-controls')
    const panel = byId('hilos-table-selection')
    expect(controls?.classList.contains('invisible')).toBe(true)
    expect(controls?.getAttribute('aria-hidden')).toBe('true')
    expect(panel?.classList.contains('invisible')).toBe(false)
    expect(panel?.getAttribute('aria-hidden')).toBeNull()
  })

  it('marks alone hold the panel: bulk progress and report leave controls visible when nothing is marked', () => {
    const controller = windowed()
    renderBar(controller)

    act(() =>
      controller.ingestProgress({
        scope: 'bulk',
        progressKey: 'run-1',
        current: 12,
        total: 40,
      }),
    )

    expect(controller.selection.count.get()).toBe(0)
    expect(strips()).toEqual({ controlsVisible: true, panelVisible: false })

    act(() =>
      controller.ingestBulkReport({
        progressKey: 'run-1',
        touched: 39,
        untouched: [],
        untouchedOmitted: 0,
      }),
    )

    expect(strips()).toEqual({ controlsVisible: true, panelVisible: false })
  })
})
