// The Angular port of vue/src/HilosTableBar.test.ts, under the same case names the
// Vue reference and the React port run, for the layer the Angular bar draws:
// title, search, filters, badge, the order menu, main action, and the filters
// modal. Every case
// mounts a host that binds the two inputs.
import { Component } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { afterEach, describe, expect, it } from 'vitest'
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

  it('draws no order menu at all for a table that declared no composite order', () => {
    const { controller } = makeController({
      title: 'Backups',
      search: {},
      columns: COLUMNS,
    })
    const fixture = mountBar(controller)

    expect(byId(fixture, 'hilos-table-order')).toBeNull()
  })

  it('offers the way home first and every declared order after it', () => {
    const { controller } = makeOrdered()
    const fixture = mountBar(controller)

    expect(orderItems(fixture).map((item) => item.textContent?.trim())).toEqual(
      ['Date ↓', 'Kind ↑, then Date ↓', 'Date ↑, then Kind ↑'],
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

  it('marks no item at all once a header click has left an order of one column', () => {
    const { controller } = makeOrdered()
    const fixture = mountBar(controller)

    controller.setSort('channel')
    fixture.detectChanges()

    expect(
      orderItems(fixture).filter((item) => item.classList.contains('active')),
    ).toEqual([])
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
  function strips(fixture: ComponentFixture<BarHost>): {
    controls: boolean
    panel: boolean
  } {
    fixture.detectChanges()
    const title = byId(fixture, 'hilos-table-title')
    expect(title?.textContent?.trim()).toBe('Backups')
    expect(title?.id).toBe('table-title')

    return {
      controls: byId(fixture, 'hilos-table-search') !== null,
      panel: byId(fixture, 'hilos-table-selection') !== null,
    }
  }

  it('keeps the ordinary controls while nothing is marked', () => {
    const fixture = mountBar(windowed())

    expect(strips(fixture)).toEqual({ controls: true, panel: false })
  })

  it('holds an open confirmation when the marks go and the strip with them', () => {
    const controller = windowed()
    const fixture = mountBar(controller)

    controller.selectRow('a', true)
    fixture.detectChanges()
    click(fixture, 'hilos-table-bulk-delete')
    expect(byId(fixture, 'modal')).not.toBeNull()

    controller.clearSelection()

    // The strip goes, the dialog stays: it holds the focus and the page's scroll
    // lock, and a window arriving with none of the marked rows left is not a
    // reason to take a dialog the reader is standing in off the screen.
    expect(strips(fixture)).toEqual({ controls: true, panel: false })
    expect(byId(fixture, 'modal')).not.toBeNull()
  })

  it('swaps the controls for the panel from the first marked row', () => {
    const controller = windowed()
    const fixture = mountBar(controller)

    controller.selectRow('a', true)

    expect(strips(fixture)).toEqual({ controls: false, panel: true })
  })

  it('holds the panel on a running bar with nothing marked', () => {
    const controller = windowed()
    const fixture = mountBar(controller)

    // Which is the case the rule exists for: a run deletes the rows it was given,
    // they drop out of the selection by themselves, and the bar of the work must
    // not leave with them.
    controller.ingestProgress({
      scope: 'bulk',
      progressKey: 'run-1',
      current: 12,
      total: 40,
    })

    expect(controller.selection.count.get()).toBe(0)
    expect(strips(fixture)).toEqual({ controls: false, panel: true })
  })

  it('holds the panel on a report with nothing marked, and lets it go when dismissed', () => {
    const controller = windowed()
    const fixture = mountBar(controller)

    controller.ingestBulkReport({
      progressKey: 'run-1',
      touched: 39,
      untouched: [],
      untouchedOmitted: 0,
    })
    expect(strips(fixture)).toEqual({ controls: false, panel: true })

    click(fixture, 'hilos-table-bulk-report-close')

    // The core still holds the report — it is cleared by the next run and by
    // nothing else — and the panel goes all the same, because what the reader
    // dismissed is off their screen.
    expect(controller.bulk.report.get()).not.toBeNull()
    expect(strips(fixture)).toEqual({ controls: true, panel: false })
  })

  it('shows the report of the next run after the previous one was dismissed', () => {
    const controller = windowed()
    const fixture = mountBar(controller)

    controller.ingestBulkReport({
      progressKey: 'run-1',
      touched: 1,
      untouched: [],
      untouchedOmitted: 0,
    })
    fixture.detectChanges()
    click(fixture, 'hilos-table-bulk-report-close')

    controller.ingestBulkReport({
      progressKey: 'run-2',
      touched: 2,
      untouched: [],
      untouchedOmitted: 0,
    })

    expect(strips(fixture)).toEqual({ controls: false, panel: true })
    expect(byId(fixture, 'hilos-table-bulk-report')?.textContent).toContain(
      'Changed 2 rows',
    )
  })
})
