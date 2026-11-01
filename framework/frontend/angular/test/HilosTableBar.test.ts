// The Angular port of vue/src/HilosTableBar.test.ts, under the same case names the
// Vue reference and the React port run, for the layer the Angular bar draws:
// title, search, filters, badge, the order menu, main and auxiliary actions, and
// the filters modal. Every case
// mounts a host that binds the two inputs.
import { Component, signal } from '@angular/core'
import type { WritableSignal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { afterEach, describe, expect, it, vi } from 'vitest'
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

const COLUMNS = [{ key: 'name', label: 'Name' }]

/** A host binding the controller and the id the title carries. */
@Component({
  selector: 'test-table-bar-host',
  imports: [HilosTableBar],
  template: `<hilos-table-bar
    [controller]="controller"
    titleId="table-title"
  />`,
})
class BarHost {
  controller!: TableViewportController<unknown>
}

/** A host whose controller can be replaced while the bar stands. */
@Component({
  selector: 'test-table-bar-swap-host',
  imports: [HilosTableBar],
  template: `<hilos-table-bar
    [controller]="controller()"
    titleId="table-title"
  />`,
})
class SwapHost {
  controller!: WritableSignal<TableViewportController<unknown>>
}

afterEach(() => {
  document.body.classList.remove('modal-open')
})

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

function mountBar(
  controller: TableViewportController<unknown>,
): ComponentFixture<BarHost> {
  const fixture = TestBed.createComponent(BarHost)
  fixture.componentInstance.controller = controller
  fixture.detectChanges()

  return fixture
}

function byId(
  fixture: ComponentFixture<unknown>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

function type(
  fixture: ComponentFixture<unknown>,
  id: string,
  value: string,
): void {
  const field = byId(fixture, id) as HTMLInputElement
  field.value = value
  field.dispatchEvent(new Event('input'))
  fixture.detectChanges()
}

function click(fixture: ComponentFixture<unknown>, id: string): void {
  byId(fixture, id)?.click()
  fixture.detectChanges()
}

// Every item of the order menu — its snowflakes, which carry the same prefix, left
// out.
function orderItems(fixture: ComponentFixture<unknown>): HTMLElement[] {
  return Array.from(
    (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLElement>(
      '[data-id^="hilos-table-order-"]:not([data-id^="hilos-table-order-stale-"])',
    ),
  )
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
      const fixture = mountBar(controller)
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
            (fixture.nativeElement.querySelector(selector) as HTMLButtonElement)
              .disabled,
          ).toBe(true)
        }
        expect(
          (byId(fixture, 'hilos-table-main-action') as HTMLButtonElement)
            .disabled,
        ).toBe(false)
        expect(controller.filter.get()).toEqual({
          document: 'terms',
          unread: true,
        })
        expect(sent).toEqual([])
        controller.ingestSubscriptionWindow(
          [],
          0,
          true,
          null,
          null,
          37,
          undefined,
          [],
        )
        fixture.detectChanges()
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
            (fixture.nativeElement.querySelector(selector) as HTMLButtonElement)
              .disabled,
          ).toBe(false)
        }
        type(fixture, 'hilos-table-search', 'retention')
        expect(sent.at(-1)).toMatchObject({
          filter: { document: 'terms', unread: true, search: 'retention' },
          limit: 37,
          sort: null,
        })
      } finally {
        fixture.destroy()
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
    const fixture = mountBar(controller)

    const title = byId(fixture, 'hilos-table-title')
    expect(title?.textContent?.trim()).toBe('Backups')
    expect(title?.id).toBe('table-title')
    expect(byId(fixture, 'hilos-table-subtitle')?.textContent?.trim()).toBe(
      'Every copy this installation keeps',
    )
  })

  it('draws no subtitle when the declaration carries none', () => {
    const { controller } = makeController({
      title: 'Backups',
      columns: COLUMNS,
    })
    const fixture = mountBar(controller)

    expect(byId(fixture, 'hilos-table-subtitle')).toBeNull()
  })

  it('draws no heading and no subtitle when the declaration carries no title', () => {
    // The page heading names such a table; an empty h2 would be a heading with
    // nothing to say, and a subtitle would hang under a heading that is not there.
    const { controller } = makeController({
      subtitle: 'Every copy this installation keeps',
      search: {},
      columns: COLUMNS,
    })
    const fixture = mountBar(controller)

    expect(byId(fixture, 'hilos-table-title')).toBeNull()
    expect(
      (fixture.nativeElement as HTMLElement).querySelector('h2'),
    ).toBeNull()
    expect(byId(fixture, 'hilos-table-subtitle')).toBeNull()
    expect(byId(fixture, 'hilos-table-search')).not.toBeNull()
  })

  it('draws no search box when the table does not declare search', () => {
    const { controller } = makeController({
      title: 'Backups',
      columns: COLUMNS,
    })
    const fixture = mountBar(controller)

    expect(byId(fixture, 'hilos-table-search')).toBeNull()
  })

  it('names the search field with its declared placeholder', () => {
    const { controller } = makeController({
      title: 'Backups',
      search: { placeholder: 'Search backups…' },
      columns: COLUMNS,
    })
    const field = byId(mountBar(controller), 'hilos-table-search')

    expect(field?.getAttribute('placeholder')).toBe('Search backups…')
    expect(field?.getAttribute('aria-label')).toBe('Search backups…')
  })

  it('falls back to "Search…" when the declaration names no placeholder', () => {
    const { controller } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    const field = byId(mountBar(controller), 'hilos-table-search')

    expect(field?.getAttribute('placeholder')).toBe('Search…')
  })

  it('sends a window on every keystroke of the search field', () => {
    const { controller, sent } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    const fixture = mountBar(controller)

    type(fixture, 'hilos-table-search', 'nig')

    expect(sent).toHaveLength(1)
    expect(sent.at(-1)?.filter).toMatchObject({ search: 'nig' })
  })

  it('offers the clear button only while the search holds text, and clearing drops the key', () => {
    const { controller, sent } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    const fixture = mountBar(controller)
    expect(byId(fixture, 'hilos-table-search-clear')).toBeNull()

    type(fixture, 'hilos-table-search', 'nig')
    click(fixture, 'hilos-table-search-clear')

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
    const fixture = mountBar(controller)

    expect(byId(fixture, 'hilos-table-main-action')?.textContent?.trim()).toBe(
      'Create backup',
    )
    click(fixture, 'hilos-table-main-action')

    expect(pressed).toBe(1)
  })

  it('draws a control per declared filter', () => {
    const { controller } = makeController(FILTERED)
    const fixture = mountBar(controller)

    expect(byId(fixture, 'hilos-table-filter-state')).not.toBeNull()
    expect(byId(fixture, 'hilos-table-filter-unread')).not.toBeNull()
  })

  it('counts the filters holding a value in the badge, and never the search', () => {
    const { controller } = makeController(FILTERED, { state: 'failed' })
    const fixture = mountBar(controller)
    const badge = (): string | undefined =>
      byId(fixture, 'hilos-table-filter-badge')?.textContent?.trim()
    expect(badge()).toBe('1 filter')

    type(fixture, 'hilos-table-search', 'nig')
    expect(badge()).toBe('1 filter')

    click(fixture, 'hilos-table-filter-unread')
    expect(badge()).toBe('2 filters')
  })

  it('shows no badge while no filter holds a value', () => {
    const { controller } = makeController(FILTERED)
    const fixture = mountBar(controller)

    expect(byId(fixture, 'hilos-table-filter-badge')).toBeNull()
  })

  it('resets to the filters the table opened with and clears the search with them', () => {
    const { controller, sent } = makeController(FILTERED, { state: 'failed' })
    const fixture = mountBar(controller)
    type(fixture, 'hilos-table-search', 'nig')
    click(fixture, 'hilos-table-filter-unread')

    click(fixture, 'hilos-table-filter-reset')

    expect(sent.at(-1)?.filter).toEqual({ state: 'failed' })
    expect(
      (byId(fixture, 'hilos-table-search') as HTMLInputElement).value,
    ).toBe('')
  })

  it('opens the filters in a modal and closes it with Done', () => {
    const { controller } = makeController(FILTERED)
    const fixture = mountBar(controller)
    expect(byId(fixture, 'modal')).toBeNull()

    click(fixture, 'hilos-table-filters-open')
    expect(byId(fixture, 'modal')).not.toBeNull()
    expect(
      (fixture.nativeElement as HTMLElement).querySelectorAll(
        '[data-id="hilos-table-filter-state-modal"]',
      ),
    ).toHaveLength(1)

    click(fixture, 'hilos-table-filters-done')

    expect(byId(fixture, 'modal')).toBeNull()
  })

  it('keeps the button that opens the filters free of their number', () => {
    const { controller } = makeController(FILTERED, { state: 'failed' })
    const fixture = mountBar(controller)
    click(fixture, 'hilos-table-filter-unread')

    const open = byId(fixture, 'hilos-table-filters-open')
    expect(open?.textContent?.trim()).toBe('Filters')
    expect(open?.querySelector('.badge')).toBeNull()
  })

  it('offers no way into the filters when the table declares none, and mounts no modal either', () => {
    const { controller } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    const fixture = mountBar(controller)

    expect(byId(fixture, 'hilos-table-filters-open')).toBeNull()
    expect(
      (fixture.nativeElement as HTMLElement).querySelector('hilos-modal'),
    ).toBeNull()
  })

  it('draws no order menu at all for a table with neither a sortable column nor a composite order', () => {
    const { controller } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    const fixture = mountBar(controller)

    expect(byId(fixture, 'hilos-table-order')).toBeNull()
  })

  it('offers a table with sortable columns and no composite order a menu below md alone', () => {
    const { controller } = makeOrdered(ORDERED_COLUMNS, [])
    const fixture = mountBar(controller)

    // Above md the header gives every one of these, so the menu, and the bar holding
    // nothing else, hide there rather than stand as an empty row.
    expect(
      byId(fixture, 'hilos-table-order')?.classList.contains('d-md-none'),
    ).toBe(true)
    expect(
      byId(fixture, 'hilos-table-controls')?.classList.contains('d-md-none'),
    ).toBe(true)
    expect(
      orderItems(fixture).map((item) => [
        item.textContent?.trim(),
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
    const fixture = mountBar(controller)

    expect(
      byId(fixture, 'hilos-table-order')?.classList.contains('d-md-none'),
    ).toBe(true)
    expect(
      byId(fixture, 'hilos-table-controls')?.classList.contains('d-md-none'),
    ).toBe(false)
  })

  it('hides the items of one column above md and keeps the composite orders on both widths', () => {
    const { controller } = makeOrdered()
    const fixture = mountBar(controller)
    const narrowOnly = (key: string): boolean | undefined =>
      byId(fixture, `hilos-table-order-${key}`)?.classList.contains('d-md-none')

    expect(
      byId(fixture, 'hilos-table-order')?.classList.contains('d-md-none'),
    ).toBe(false)
    expect(narrowOnly(HILOS_TABLE_OPENING_ORDER_KEY)).toBe(false)
    expect(narrowOnly('channel-asc')).toBe(true)
    expect(narrowOnly('createdAt-asc')).toBe(true)
    expect(narrowOnly('by_channel')).toBe(false)
    expect(narrowOnly('by_channel-mirror')).toBe(false)
  })

  it('runs the window by one column when its item is picked', () => {
    const { controller, sent } = makeOrdered(ORDERED_COLUMNS, [])
    const fixture = mountBar(controller)

    click(fixture, 'hilos-table-order-channel-desc')

    expect(sent.at(-1)).toMatchObject({
      sort: [{ field: 'channel', direction: 'desc' }],
    })
  })

  it('offers the way home first, then the columns both ways, then every declared order with its mirror', () => {
    const { controller } = makeOrdered()
    const fixture = mountBar(controller)

    // The columns stand in the order they are declared, the one the table opened in
    // left out — the way home names it. The mirror is the framework's: the same
    // columns with every direction turned, offered right after the order it mirrors,
    // and declared by no table (HIL-1095).
    expect(orderItems(fixture).map((item) => item.textContent?.trim())).toEqual(
      [
        'Date ↓',
        'Kind ↑',
        'Kind ↓',
        'Date ↑',
        'Kind ↑, then Date ↓',
        'Kind ↓, then Date ↑',
        'Date ↑, then Kind ↑',
        'Date ↓, then Kind ↓',
      ],
    )
    expect(
      byId(fixture, `hilos-table-order-${HILOS_TABLE_OPENING_ORDER_KEY}`),
    ).not.toBeNull()
  })

  it('names the order the window runs in on the face of the button', () => {
    const { controller } = makeOrdered()
    const fixture = mountBar(controller)

    expect(byId(fixture, 'hilos-dropdown-toggle')?.textContent?.trim()).toBe(
      'Order: Date ↓',
    )

    click(fixture, 'hilos-table-order-by_channel')

    expect(byId(fixture, 'hilos-dropdown-toggle')?.textContent?.trim()).toBe(
      'Order: Kind ↑, then Date ↓',
    )
  })

  it('takes a declared order whole rather than a column of it', () => {
    const { controller, sent } = makeOrdered()
    const fixture = mountBar(controller)

    click(fixture, 'hilos-table-order-by_channel')

    expect(sent.at(-1)).toMatchObject({
      sort: [
        { field: 'channel', direction: 'asc' },
        { field: 'createdAt', direction: 'desc' },
      ],
    })
  })

  it('comes home through the first item, whatever order the window left in', () => {
    const { controller, sent } = makeOrdered()
    const fixture = mountBar(controller)

    click(fixture, 'hilos-table-order-by_channel')
    click(fixture, `hilos-table-order-${HILOS_TABLE_OPENING_ORDER_KEY}`)

    expect(sent.at(-1)).toMatchObject({ sort: OPENING_ORDER })
  })

  it('asks for nothing when the item picked is the one already running', () => {
    const { controller, sent } = makeOrdered()
    const fixture = mountBar(controller)

    click(fixture, `hilos-table-order-${HILOS_TABLE_OPENING_ORDER_KEY}`)

    expect(sent).toEqual([])
  })

  it('marks the item of the column, hidden above md, once a header click has left an order of one column', () => {
    const { controller } = makeOrdered()
    const fixture = mountBar(controller)

    controller.setSort('channel')
    fixture.detectChanges()

    const active = orderItems(fixture).filter((item) =>
      item.classList.contains('active'),
    )
    expect(active.map((item) => item.dataset['id'])).toEqual([
      'hilos-table-order-channel-asc',
    ])
    expect(active[0]?.classList.contains('d-md-none')).toBe(true)
    expect(byId(fixture, 'hilos-dropdown-toggle')?.textContent?.trim()).toBe(
      'Order: Kind ↑',
    )
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
    const fixture = mountBar(controller)

    const staleMark = byId(fixture, 'hilos-table-order-stale-by_channel')
    expect(staleMark).not.toBeNull()
    expect(staleMark?.classList.contains('bi-snow')).toBe(true)
    expect(
      byId(fixture, 'hilos-table-order-by_channel')?.textContent?.trim(),
    ).toContain('Sorting by this column may be wrong')

    expect(byId(fixture, 'hilos-table-order-stale-by_state')).toBeNull()
    // The column of the frozen source carries the mark on its own items too.
    expect(byId(fixture, 'hilos-table-order-stale-channel-desc')).not.toBeNull()
    expect(byId(fixture, 'hilos-table-order-stale-state-asc')).toBeNull()

    click(fixture, 'hilos-table-order-by_channel')
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
    const fixture = mountBar(controller)

    expect(
      (fixture.nativeElement as HTMLElement).querySelectorAll(
        '[data-id^="hilos-table-order-stale-"]',
      ),
    ).toHaveLength(0)
  })

  it('draws no main action when the table declares none', () => {
    const { controller } = makeController({
      title: 'Backups',
      columns: COLUMNS,
    })
    const fixture = mountBar(controller)

    expect(byId(fixture, 'hilos-table-main-action')).toBeNull()
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
      const fixture = mountBar(controller)
      const group = byId(fixture, 'hilos-table-actions') as HTMLElement

      expect(group.getAttribute('role')).toBe('group')
      expect(group.getAttribute('aria-label')).toBe('List actions')
      expect(
        Array.from(group.querySelectorAll('button')).map((button) => [
          button.textContent?.trim(),
          (button as HTMLButtonElement).type,
          button.getAttribute('data-id'),
          (button as HTMLButtonElement).disabled,
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
      expect(copy).not.toHaveBeenCalled()
      expect(download).not.toHaveBeenCalled()
      expect(byId(fixture, 'hilos-table-search')).toBeNull()
      expect(byId(fixture, 'hilos-table-main-action')).toBeNull()

      click(fixture, 'hilos-table-action-copy')
      expect(copy).toHaveBeenCalledTimes(1)
      expect(download).not.toHaveBeenCalled()

      click(fixture, 'hilos-table-action-download')
      expect(copy).toHaveBeenCalledTimes(1)
      expect(download).toHaveBeenCalledTimes(1)
      expect(sent).toEqual([])
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
        const fixture = mountBar(controller)

        expect(copy).not.toHaveBeenCalled()
        expect(download).not.toHaveBeenCalled()
        click(fixture, 'hilos-table-action-copy')
        click(fixture, 'hilos-table-action-download')
        expect(copy).toHaveBeenCalledTimes(1)
        expect(download).toHaveBeenCalledTimes(1)
      },
    )

    it('keeps list actions in the bar while the mobile filters open and close', () => {
      const { frame, copy, download } = makeActions()
      const { controller } = makeController({
        ...FILTERED,
        ...frame,
        columns: COLUMNS,
      })
      const fixture = mountBar(controller)
      const group = byId(fixture, 'hilos-table-actions')
      expect(group).not.toBeNull()

      click(fixture, 'hilos-table-filters-open')
      const modal = byId(fixture, 'modal')
      expect(modal).not.toBeNull()
      expect(modal?.querySelector('[data-id="hilos-table-actions"]')).toBeNull()
      expect(byId(fixture, 'hilos-table-actions')).toBe(group)
      expect(copy).not.toHaveBeenCalled()
      expect(download).not.toHaveBeenCalled()

      click(fixture, 'hilos-table-filters-done')
      expect(byId(fixture, 'modal')).toBeNull()
      expect(byId(fixture, 'hilos-table-actions')).toBe(group)
      click(fixture, 'hilos-table-action-copy')
      expect(copy).toHaveBeenCalledTimes(1)
      expect(download).not.toHaveBeenCalled()
    })

    it('keeps the main action independent of list actions', () => {
      const { frame, copy, download } = makeActions()
      const main = vi.fn()
      const { controller } = makeController({
        ...frame,
        mainAction: { label: 'Create backup', press: main },
      })
      const fixture = mountBar(controller)

      expect(
        byId(fixture, 'hilos-table-main-action')?.textContent?.trim(),
      ).toBe('Create backup')
      click(fixture, 'hilos-table-main-action')
      expect(main).toHaveBeenCalledTimes(1)
      expect(copy).not.toHaveBeenCalled()
      expect(download).not.toHaveBeenCalled()

      click(fixture, 'hilos-table-action-copy')
      expect(copy).toHaveBeenCalledTimes(1)
      expect(download).not.toHaveBeenCalled()
    })

    it.each([undefined, []])(
      'omits the group and preserves the main action for auxiliaryActions=%j',
      (auxiliaryActions) => {
        const { frame } = makeActions()
        const main = vi.fn()
        const { controller } = makeController({
          ...frame,
          auxiliaryActions,
          mainAction: { label: 'Create backup', press: main },
        })
        const fixture = mountBar(controller)

        expect(byId(fixture, 'hilos-table-actions')).toBeNull()
        expect(
          byId(fixture, 'hilos-table-main-action')?.textContent?.trim(),
        ).toBe('Create backup')
        click(fixture, 'hilos-table-main-action')
        expect(main).toHaveBeenCalledTimes(1)
      },
    )

    it('creates no empty group or ordinary controls for an empty action array', () => {
      const { frame } = makeActions()
      const { controller } = makeController({ ...frame, auxiliaryActions: [] })
      const fixture = mountBar(controller)

      expect(byId(fixture, 'hilos-table-actions')).toBeNull()
      expect(byId(fixture, 'hilos-table-controls')).toBeNull()
    })

    it('renders a text-only action without a decorative icon', () => {
      const { frame } = makeActions()
      const press = vi.fn()
      const { controller } = makeController({
        ...frame,
        auxiliaryActions: [{ key: 'copy', label: 'Copy the list', press }],
      })
      const fixture = mountBar(controller)
      const button = byId(fixture, 'hilos-table-action-copy')

      expect(button?.textContent?.trim()).toBe('Copy the list')
      expect(button?.querySelector('i')).toBeNull()
      expect(press).not.toHaveBeenCalled()
      click(fixture, 'hilos-table-action-copy')
      expect(press).toHaveBeenCalledTimes(1)
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
  function strips(fixture: ComponentFixture<BarHost>): {
    controlsVisible: boolean
    panelVisible: boolean
  } {
    fixture.detectChanges()
    const title = byId(fixture, 'hilos-table-title')
    expect(title?.textContent?.trim()).toBe('Backups')
    expect(title?.id).toBe('table-title')

    const controls = byId(fixture, 'hilos-table-controls')
    const panel = byId(fixture, 'hilos-table-selection')

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
    const fixture = mountBar(windowed())

    expect(byId(fixture, 'hilos-table-bar-slot')).not.toBeNull()
    expect(strips(fixture)).toEqual({
      controlsVisible: true,
      panelVisible: false,
    })

    const controls = byId(fixture, 'hilos-table-controls')
    const panel = byId(fixture, 'hilos-table-selection')
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
    const fixture = mountBar(controller)

    expect(byId(fixture, 'hilos-table-bar-slot')).toBeNull()
    expect(byId(fixture, 'hilos-table-controls')).not.toBeNull()
    expect(
      byId(fixture, 'hilos-table-controls')?.classList.contains('mb-3'),
    ).toBe(true)
    expect(byId(fixture, 'hilos-table-selection')).toBeNull()
  })

  it('holds an open confirmation when the marks go and the strip with them', () => {
    const controller = windowed()
    const fixture = mountBar(controller)

    controller.selectRow('a', true)
    fixture.detectChanges()
    click(fixture, 'hilos-table-bulk-delete')
    expect(byId(fixture, 'modal')).not.toBeNull()

    controller.clearSelection()

    expect(strips(fixture)).toEqual({
      controlsVisible: true,
      panelVisible: false,
    })
    expect(byId(fixture, 'modal')).not.toBeNull()
  })

  it('swaps the controls for the panel from the first marked row', () => {
    const controller = windowed()
    const fixture = mountBar(controller)

    controller.selectRow('a', true)

    expect(strips(fixture)).toEqual({
      controlsVisible: false,
      panelVisible: true,
    })
    const controls = byId(fixture, 'hilos-table-controls')
    const panel = byId(fixture, 'hilos-table-selection')
    expect(controls?.classList.contains('invisible')).toBe(true)
    expect(controls?.getAttribute('aria-hidden')).toBe('true')
    expect(panel?.classList.contains('invisible')).toBe(false)
    expect(panel?.getAttribute('aria-hidden')).toBeNull()
  })

  it('marks alone hold the panel: bulk progress and report leave controls visible when nothing is marked', () => {
    const controller = windowed()
    const fixture = mountBar(controller)

    controller.ingestProgress({
      scope: 'bulk',
      progressKey: 'run-1',
      current: 12,
      total: 40,
    })

    expect(controller.selection.count.get()).toBe(0)
    expect(strips(fixture)).toEqual({
      controlsVisible: true,
      panelVisible: false,
    })

    controller.ingestBulkReport({
      progressKey: 'run-1',
      touched: 39,
      untouched: [],
      untouchedOmitted: 0,
    })

    expect(strips(fixture)).toEqual({
      controlsVisible: true,
      panelVisible: false,
    })
  })
})

describe('HilosTableBar view group', () => {
  const PICTURE_VIEWS: HilosTableViews = {
    tableKey: 'pictures',
    order: ['table', 'tiles'],
    imageColumn: 'name',
  }

  /** One declared operation, never run: the bar only reads that bulk exists. */
  const BULK_ACTIONS: NonNullable<HilosTableFrame['bulkActions']> = [
    {
      key: 'archive',
      label: 'Archive',
      refusalTitle: 'Cannot archive',
      run: () => {
        throw new Error('Not pressed')
      },
    },
  ]

  /** A table declaring views, with whatever else the case adds around them. */
  function viewed(
    extra: Partial<HilosTableFrame> = {},
    views: HilosTableViews = PICTURE_VIEWS,
  ): ReturnType<typeof makeController> & { rendered: string[][] } {
    const rendered: string[][] = []
    const sent: TableViewportDescriptor[] = []
    const controller = new TableViewportController<unknown>({
      resolve: (raw) => ({ name: String(raw.slots['name']) }),
      sendViewport: (descriptor) => sent.push(descriptor),
      sendRendered: (keys) => rendered.push([...keys]),
      frame: { title: 'Pictures', columns: COLUMNS, views, ...extra },
    })

    return { controller, sent, rendered }
  }

  function withRows(controller: TableViewportController<unknown>): void {
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
  }

  function viewGroup(fixture: ComponentFixture<unknown>): HTMLElement {
    const group = byId(fixture, 'hilos-table-view')
    expect(group).not.toBeNull()

    return group as HTMLElement
  }

  function viewButtons(
    fixture: ComponentFixture<unknown>,
  ): HTMLButtonElement[] {
    return Array.from(viewGroup(fixture).querySelectorAll('button'))
  }

  /** What a reader meets in the group: each choice's words, and whether it is pressed. */
  function viewReading(
    fixture: ComponentFixture<unknown>,
  ): [string | undefined, string | null][] {
    return viewButtons(fixture).map((button) => [
      button.textContent?.trim(),
      button.getAttribute('aria-pressed'),
    ])
  }

  /**
   * jsdom does not synthesize the click the browser sends for Enter on a native
   * button, so the key events and that click (detail 0, as a keyboard click
   * carries) are sent by hand; the bar has no key handler of its own to stand in.
   */
  function pressWithKeyboard(
    fixture: ComponentFixture<unknown>,
    button: HTMLElement,
  ): void {
    button.focus()
    button.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
    )
    button.dispatchEvent(new MouseEvent('click', { bubbles: true, detail: 0 }))
    button.dispatchEvent(
      new KeyboardEvent('keyup', { key: 'Enter', bubbles: true }),
    )
    fixture.detectChanges()
  }

  it.each([false, true])(
    'keeps one native view group with no other controls, bulk enabled: %s',
    (bulk) => {
      const { controller, sent } = viewed(
        bulk ? { bulkActions: BULK_ACTIONS } : {},
      )
      withRows(controller)
      const fixture = mountBar(controller)
      try {
        const group = viewGroup(fixture)
        expect(group.getAttribute('role')).toBe('group')
        expect(group.getAttribute('aria-label')).toBe(TABLE_VIEW_COPY.group)
        expect(
          Array.from(group.querySelectorAll('button')).map((button) => [
            button.textContent?.trim(),
            button.getAttribute('type'),
            button.getAttribute('aria-pressed'),
          ]),
        ).toEqual([
          ['Table', 'button', 'true'],
          ['Tiles', 'button', 'false'],
        ])
        expect(
          Array.from(group.querySelectorAll('i')).map((icon) =>
            icon.getAttribute('aria-hidden'),
          ),
        ).toEqual(['true', 'true'])
        expect(
          fixture.nativeElement.querySelectorAll(
            '[data-id="hilos-table-view"]',
          ),
        ).toHaveLength(1)
        if (bulk) {
          controller.selectRow('a', true)
          fixture.detectChanges()
          expect(
            byId(fixture, 'hilos-table-selection')?.closest(
              '[aria-hidden="true"], [inert], [hidden]',
            ),
          ).toBeNull()
          // The panel stands in the controls' place, and the group is not one of
          // the things it hides.
          expect(
            group.closest('[aria-hidden="true"], [inert], [hidden]'),
          ).toBeNull()
        }

        const tiles = byId(fixture, 'hilos-table-view-tiles') as HTMLElement
        pressWithKeyboard(fixture, tiles)

        expect(document.activeElement).toBe(tiles)
        expect(controller.frame.view.get()).toBe('tiles')
        expect(viewReading(fixture)).toEqual([
          ['Table', 'false'],
          ['Tiles', 'true'],
        ])
        expect(sent).toEqual([])
        expect(controller.selection.count.get()).toBe(bulk ? 1 : 0)
      } finally {
        fixture.destroy()
        controller.dispose()
      }
    },
  )

  it('lists the choices in the order the table declared them and opens on the first', () => {
    const { controller } = viewed(
      {},
      { ...PICTURE_VIEWS, order: ['tiles', 'table'] },
    )
    const fixture = mountBar(controller)
    try {
      expect(viewReading(fixture)).toEqual([
        ['Tiles', 'true'],
        ['Table', 'false'],
      ])
      expect(
        viewButtons(fixture).map((button) => button.dataset['id']),
      ).toEqual(['hilos-table-view-tiles', 'hilos-table-view-table'])
    } finally {
      fixture.destroy()
      controller.dispose()
    }
  })

  it('offers no view group without a views declaration', () => {
    const { controller } = makeController({ columns: COLUMNS })
    const fixture = mountBar(controller)

    expect(byId(fixture, 'hilos-table-view')).toBeNull()
    expect(byId(fixture, 'hilos-table-controls')).toBeNull()
  })

  it.each([false, true])(
    'keeps the group next to every other control and over an empty window, bulk enabled: %s',
    (bulk) => {
      const main = vi.fn()
      const { controller, sent } = viewed({
        search: {},
        filters: [
          { kind: 'toggle', key: 'unread', label: 'Unread only', on: true },
        ],
        mainAction: { label: 'Create', press: main },
        ...(bulk ? { bulkActions: BULK_ACTIONS } : {}),
      })
      const fixture = mountBar(controller)
      try {
        // No window has arrived yet: the controls wait, the layout choice does not.
        expect(byId(fixture, 'hilos-table-search')).not.toBeNull()
        expect(byId(fixture, 'hilos-table-main-action')).not.toBeNull()
        expect(viewReading(fixture)).toEqual([
          ['Table', 'true'],
          ['Tiles', 'false'],
        ])

        click(fixture, 'hilos-table-view-tiles')

        expect(controller.frame.view.get()).toBe('tiles')
        expect(viewReading(fixture)).toEqual([
          ['Table', 'false'],
          ['Tiles', 'true'],
        ])
        expect(main).not.toHaveBeenCalled()
        expect(sent).toEqual([])
      } finally {
        fixture.destroy()
        controller.dispose()
      }
    },
  )

  it('keeps the group usable while the window controls are unavailable', () => {
    const controller = new TableViewportController<unknown>({
      resolve: (row) => row,
      sendViewport: () => undefined,
      initialFilter: { unread: true },
      initialFilterInSubscription: true,
      frame: {
        columns: COLUMNS,
        search: {},
        filters: [
          { kind: 'toggle', key: 'unread', label: 'Unread only', on: true },
        ],
        views: PICTURE_VIEWS,
      },
    })
    controller.ingestRefusal('internal_error')
    const fixture = mountBar(controller)
    try {
      // The same state the first case of this file holds: the window is unknown,
      // so the search waits. The layout choice is local and does not.
      expect(
        (byId(fixture, 'hilos-table-search') as HTMLInputElement).disabled,
      ).toBe(true)
      expect(viewButtons(fixture).map((button) => button.disabled)).toEqual([
        false,
        false,
      ])

      click(fixture, 'hilos-table-view-tiles')

      expect(controller.frame.view.get()).toBe('tiles')
    } finally {
      fixture.destroy()
      controller.dispose()
    }
  })

  it.each([false, true])(
    'changes only the layout: nothing is sent, applied, reordered or reset, and a repeat of the active choice is a no-op, bulk enabled: %s',
    (bulk) => {
      const { controller, sent, rendered } = viewed({
        columns: [{ key: 'name', label: 'Name', sortable: true }],
        search: {},
        ...(bulk ? { bulkActions: BULK_ACTIONS } : {}),
      })
      withRows(controller)
      const fixture = mountBar(controller)
      try {
        type(fixture, 'hilos-table-search', 'retention')
        controller.setOrder([{ field: 'name', direction: 'asc' }])
        if (bulk) {
          controller.selectRow('b', true)
        }
        fixture.detectChanges()
        const search = byId(fixture, 'hilos-table-search')
        const before = {
          search: controller.search.get(),
          filter: controller.filter.get(),
          orderLabel: controller.frame.orderLabel.get(),
          marked: controller.selection.count.get(),
          sent: sent.length,
          rendered: rendered.length,
        }
        const spies = [
          vi.spyOn(controller, 'apply'),
          vi.spyOn(controller, 'setOrder'),
          vi.spyOn(controller, 'resetOrder'),
          vi.spyOn(controller, 'setSearch'),
          vi.spyOn(controller, 'resetFilters'),
        ]
        const changes: string[] = []
        const stop = subscribeSignal(controller.frame.view, (view) =>
          changes.push(view),
        )

        click(fixture, 'hilos-table-view-table')
        click(fixture, 'hilos-table-view-tiles')
        click(fixture, 'hilos-table-view-tiles')
        click(fixture, 'hilos-table-view-table')
        click(fixture, 'hilos-table-view-table')
        stop()

        expect(changes).toEqual(['tiles', 'table'])
        expect(viewReading(fixture)).toEqual([
          ['Table', 'true'],
          ['Tiles', 'false'],
        ])
        for (const spy of spies) {
          expect(spy).not.toHaveBeenCalled()
        }
        expect({
          search: controller.search.get(),
          filter: controller.filter.get(),
          orderLabel: controller.frame.orderLabel.get(),
          marked: controller.selection.count.get(),
          sent: sent.length,
          rendered: rendered.length,
        }).toEqual(before)
        expect(byId(fixture, 'hilos-table-search')).toBe(search)
        expect((search as HTMLInputElement).value).toBe('retention')
      } finally {
        fixture.destroy()
        controller.dispose()
      }
    },
  )

  it('keeps focus on the pressed choice through both switches', () => {
    const { controller } = viewed()
    const fixture = mountBar(controller)
    try {
      const tiles = byId(fixture, 'hilos-table-view-tiles') as HTMLElement
      const table = byId(fixture, 'hilos-table-view-table') as HTMLElement

      pressWithKeyboard(fixture, tiles)
      expect(document.activeElement).toBe(tiles)

      pressWithKeyboard(fixture, table)
      expect(document.activeElement).toBe(table)
      expect(byId(fixture, 'hilos-table-view-table')).toBe(table)
    } finally {
      fixture.destroy()
      controller.dispose()
    }
  })

  it('follows a replaced controller and lets the old one go', () => {
    const first = viewed()
    const second = viewed({}, { ...PICTURE_VIEWS, order: ['tiles', 'table'] })
    const plain = makeController({ columns: COLUMNS })
    const fixture = TestBed.createComponent(SwapHost)
    const current = signal<TableViewportController<unknown>>(first.controller)
    fixture.componentInstance.controller = current
    fixture.detectChanges()
    try {
      click(fixture, 'hilos-table-view-tiles')
      expect(viewReading(fixture)).toEqual([
        ['Table', 'false'],
        ['Tiles', 'true'],
      ])

      current.set(second.controller)
      fixture.detectChanges()
      expect(viewReading(fixture)).toEqual([
        ['Tiles', 'true'],
        ['Table', 'false'],
      ])
      // The old controller is no longer mirrored.
      first.controller.setView('table')
      fixture.detectChanges()
      expect(viewReading(fixture)).toEqual([
        ['Tiles', 'true'],
        ['Table', 'false'],
      ])

      current.set(plain.controller)
      fixture.detectChanges()
      expect(byId(fixture, 'hilos-table-view')).toBeNull()
    } finally {
      fixture.destroy()
      first.controller.dispose()
      second.controller.dispose()
      plain.controller.dispose()
    }
  })

  describe('with sortable columns and no composite order', () => {
    function sortable(): ReturnType<typeof viewed> {
      const made = viewed(
        { columns: ORDERED_COLUMNS },
        {
          ...PICTURE_VIEWS,
          tableKey: 'ordered-pictures',
          imageColumn: 'channel',
        },
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

    // Which widths an item is offered on is the stylesheet's business, and so is
    // whether the bar stays above md: the earlier tests of this file read that for
    // a table without views, and the browser proves that a bar holding the view
    // choice alone stays on a desktop in Table (table-geometry.spec.ts, the last
    // step of T01). Here the menu is read as a reader meets it: its items, in
    // order, with their roles and choice.
    const EVERY_ORDER = [
      ['Date ↓', 'option', 'true'],
      ['Kind ↑', 'option', 'false'],
      ['Kind ↓', 'option', 'false'],
      ['Date ↑', 'option', 'false'],
    ]

    /** The order menu as a reader meets it: each item's words, role and choice. */
    function orderReading(fixture: ComponentFixture<unknown>): unknown[][] {
      return orderItems(fixture).map((item) => [
        item.textContent?.trim(),
        item.getAttribute('role'),
        item.getAttribute('aria-selected'),
      ])
    }

    /** Whether any of the order menu, or the bar holding it, is withdrawn from a reader. */
    function anyOrderWithdrawn(fixture: ComponentFixture<unknown>): boolean {
      return [
        byId(fixture, 'hilos-table-controls'),
        byId(fixture, 'hilos-table-order'),
        ...orderItems(fixture),
      ].some(
        (node) =>
          node === null ||
          node.closest('[aria-hidden="true"], [inert], [hidden]') !== null,
      )
    }

    it('offers every order in Tiles, and in Table the items a table without views offers', () => {
      const { controller, sent } = sortable()
      const fixture = mountBar(controller)
      try {
        expect(orderReading(fixture)).toEqual(EVERY_ORDER)

        click(fixture, 'hilos-table-view-tiles')

        expect(orderReading(fixture)).toEqual(EVERY_ORDER)
        expect(anyOrderWithdrawn(fixture)).toBe(false)

        click(fixture, 'hilos-table-view-table')

        expect(orderReading(fixture)).toEqual(EVERY_ORDER)
        expect(sent).toEqual([])
      } finally {
        fixture.destroy()
        controller.dispose()
      }
    })

    it('runs an order picked in Tiles through the window, as in Table', () => {
      const { controller, sent } = sortable()
      const fixture = mountBar(controller)
      try {
        click(fixture, 'hilos-table-view-tiles')
        expect(sent).toEqual([])

        click(fixture, 'hilos-table-order-channel-desc')

        expect(sent.at(-1)).toMatchObject({
          sort: [{ field: 'channel', direction: 'desc' }],
        })
        expect(controller.frame.view.get()).toBe('tiles')
      } finally {
        fixture.destroy()
        controller.dispose()
      }
    })

    it('keeps the order items of a table without views, with no view group', () => {
      const { controller } = makeOrdered(ORDERED_COLUMNS, [])
      const fixture = mountBar(controller)

      expect(orderReading(fixture)).toEqual(EVERY_ORDER)
      expect(byId(fixture, 'hilos-table-view')).toBeNull()
    })
  })
})
