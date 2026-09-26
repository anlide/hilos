import { afterEach, describe, expect, it, vi } from 'vitest'
import { type ActionHandle } from '../../src/connection/actionLifecycle.js'
import {
  type TableAnchor,
  type TableViewportDescriptor,
} from '../../src/connection/HilosConnection.js'
import { type TableRow } from '../../src/state/TableRowsStore.js'
import { type HilosTableBulkAccepted } from '../../src/table/tableBulk.js'
import { HILOS_TABLE_ACTIONS_KEY } from '../../src/table/hilosTableColumn.js'
import { type HilosTableBulkAction } from '../../src/table/tableFrame.js'
import { type HilosTableProgressFrame } from '../../src/table/tableProgress.js'
import { createSignal } from '../../src/state/signal.js'
import {
  type TableSortOrder,
  TableViewportController,
} from '../../src/table/TableViewportController.js'
import { HILOS_TABLE_OPENING_ORDER_KEY } from '../../src/table/tableSortOrder.js'

function makeController(pageSize = 10, initialOrder?: TableSortOrder) {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<TableRow>({
    resolve: (row) => row,
    sendViewport: (descriptor) => sent.push(descriptor),
  })

  // Deliver a window the way the page's own answer does — which is also the only frame that
  // says what size and what order the table was opened at, since HIL-642 moved both onto the
  // backend's declaration. The controller holds no opinion about either until one arrives.
  const open = (
    rows: readonly TableRow[] = [],
    totalCount = 0,
    totalExact = true,
    firstAnchor: TableAnchor | null = null,
    lastAnchor: TableAnchor | null = null,
    progress: readonly HilosTableProgressFrame[] = [],
  ): void =>
    controller.ingestSubscriptionWindow(
      rows,
      totalCount,
      totalExact,
      firstAnchor,
      lastAnchor,
      pageSize,
      initialOrder,
      progress,
    )

  return { controller, sent, open }
}

describe('TableViewportController', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('asks for nothing on its own — the first window arrives with the page', () => {
    const { controller, sent, open } = makeController()

    // Nothing is sent until the reader changes the window: the first one is part of the
    // page's own answer, so the round trip that used to fetch it is gone (HIL-642).
    expect(sent).toEqual([])
    expect(controller.descriptor()).toBeNull()

    open()
    expect(sent).toEqual([])
    expect(controller.descriptor()).toEqual({
      filter: {},
      sort: null,
      limit: 10,
      anchor: null,
      anchorDirection: 'after',
      pageIndex: null,
    })
  })

  it('reports the fields its declared columns draw, with every window', () => {
    const sent: TableViewportDescriptor[] = []
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: (descriptor) => sent.push(descriptor),
      frame: {
        columns: [
          { key: 'name', label: 'Name', sortable: true },
          { key: HILOS_TABLE_ACTIONS_KEY, label: '', reads: ['id'] },
        ],
      },
    })
    controller.ingestSubscriptionWindow(
      [],
      0,
      true,
      null,
      null,
      10,
      undefined,
      [],
    )

    controller.setSort('name')

    // The same list rides the frame asking for a window and the report of the window held,
    // so a reconnect keeps comparing rows the way the mounted table did (HIL-880).
    expect(sent.at(-1)?.rendered).toEqual(['name', 'id'])
    expect(controller.descriptor()?.rendered).toEqual(['name', 'id'])
  })

  it('tells the server what it draws once, over the window the page brought', () => {
    const sent: TableViewportDescriptor[] = []
    const declared: (readonly string[])[] = []
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: (descriptor) => sent.push(descriptor),
      sendRendered: (rendered) => declared.push(rendered),
      frame: {
        columns: [
          { key: 'name', label: 'Name', sortable: true },
          { key: HILOS_TABLE_ACTIONS_KEY, label: '', reads: ['id'] },
        ],
      },
    })
    const answer = () =>
      controller.ingestSubscriptionWindow(
        [],
        0,
        true,
        null,
        null,
        10,
        undefined,
        [],
      )

    answer()

    // The cold window was built before the table mounted and holds no list; no window frame
    // went out to carry one, so the declaration is a frame of its own (HIL-880).
    expect(declared).toEqual([['name', 'id']])
    expect(sent).toEqual([])

    // A later answer to the same page finds the table loaded: its window already knows.
    answer()
    controller.setSort('name')
    expect(declared).toEqual([['name', 'id']])
  })

  it('a table opening with a preset declares nothing apart from its own window frame', () => {
    const sent: TableViewportDescriptor[] = []
    const declared: (readonly string[])[] = []
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: (descriptor) => sent.push(descriptor),
      sendRendered: (rendered) => declared.push(rendered),
      initialFilter: { channel: 'email' },
      frame: { columns: [{ key: 'name', label: 'Name', sortable: true }] },
    })

    controller.ingestSubscriptionWindow(
      [],
      0,
      true,
      null,
      null,
      10,
      undefined,
      [],
    )

    expect(declared).toEqual([])
    expect(sent.at(-1)?.rendered).toEqual(['name'])
  })

  it('a table that declares no frame carries no drawn fields at all', () => {
    const { controller, sent, open } = makeController()

    open()
    controller.setSort('name')

    // Absent rather than empty: absence is what asks the server to compare the whole row.
    expect(sent.at(-1)).not.toHaveProperty('rendered')
    expect(controller.descriptor()).not.toHaveProperty('rendered')
  })

  it('takes the size and the order of its window from the window itself', () => {
    const { controller, open } = makeController(25, [
      { field: 'id', direction: 'desc' },
    ])

    open()

    // Neither is declared on this side any more: the table states both on the backend and
    // every window says which ones it ran at, so there is one declaration and one reader.
    expect(controller.order.get()).toEqual([{ field: 'id', direction: 'desc' }])
    expect(controller.descriptor()?.limit).toBe(25)
  })

  it('a table opened with a preset asks for its own window rather than showing the page one', () => {
    const sent: TableViewportDescriptor[] = []
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: (descriptor) => sent.push(descriptor),
      initialFilter: { channel: 'mail' },
    })
    const everyChannel: TableRow[] = [
      { rowKey: 'mail.host', slots: {} },
      { rowKey: 'sms.token', slots: {} },
    ]

    // The page's answer was served by the table's declaration alone, which knows nothing of
    // the channel a route names: its rows are every channel's, so none of them is drawn.
    controller.ingestSubscriptionWindow(
      everyChannel,
      2,
      true,
      null,
      null,
      25,
      [{ field: 'field', direction: 'asc' }],
      [],
    )

    expect(controller.rows.get()).toEqual([])
    expect(controller.frame.body.get()).toBe('loading')
    expect(sent).toEqual([
      {
        filter: { channel: 'mail' },
        sort: [{ field: 'field', direction: 'asc' }],
        limit: 25,
        anchor: null,
        anchorDirection: 'after',
        pageIndex: null,
      },
    ])

    controller.ingestWindow([everyChannel[0]], 1, true, null, null, 25)

    expect(controller.rows.get().map((view) => view.rowKey)).toEqual([
      'mail.host',
    ])
  })

  it('a preset left empty does not hold the page window back', () => {
    const sent: TableViewportDescriptor[] = []
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: (descriptor) => sent.push(descriptor),
      initialFilter: { channel: '' },
    })

    controller.ingestSubscriptionWindow(
      [{ rowKey: 'mail.host', slots: {} }],
      1,
      true,
      null,
      null,
      25,
      undefined,
      [],
    )

    expect(sent).toEqual([])
    expect(controller.rows.get()).toHaveLength(1)
  })

  it('setSearch sets the search filter, resets to page 0, and resends', () => {
    const { controller, sent, open } = makeController()
    open([], 50, true, null, null)
    controller.setPage(2)
    controller.setSearch('theme')

    expect(controller.search.get()).toBe('theme')
    expect(sent.at(-1)).toEqual({
      filter: { search: 'theme' },
      sort: null,
      limit: 10,
      anchor: null,
      anchorDirection: 'after',
      pageIndex: null,
    })
  })

  it('an empty search drops the filter', () => {
    const { controller, sent, open } = makeController()
    open()
    controller.setSearch('theme')
    controller.setSearch('   ')
    expect(sent.at(-1)).toEqual({
      filter: {},
      sort: null,
      limit: 10,
      anchor: null,
      anchorDirection: 'after',
      pageIndex: null,
    })
  })

  it('setFilter sets a domain filter entry, resets to page 0, and resends', () => {
    const { controller, sent, open } = makeController()
    open([], 50, true, null, null)
    controller.setPage(2)
    controller.setFilter('status', 'failed')

    expect(sent.at(-1)).toEqual({
      filter: { status: 'failed' },
      sort: null,
      limit: 10,
      anchor: null,
      anchorDirection: 'after',
      pageIndex: null,
    })
  })

  it('setFilter keeps other filter entries and the search filter', () => {
    const { controller, sent, open } = makeController()
    open()
    controller.setSearch('mail')
    controller.setFilter('status', 'failed')
    controller.setFilter('channel', 'sms')

    expect(sent.at(-1)).toEqual({
      filter: { search: 'mail', status: 'failed', channel: 'sms' },
      sort: null,
      limit: 10,
      anchor: null,
      anchorDirection: 'after',
      pageIndex: null,
    })
  })

  it('setFilter with an empty value drops just that entry', () => {
    const { controller, sent, open } = makeController()
    open()
    controller.setFilter('status', 'failed')
    controller.setFilter('channel', 'sms')
    controller.setFilter('status', '')

    expect(sent.at(-1)).toEqual({
      filter: { channel: 'sms' },
      sort: null,
      limit: 10,
      anchor: null,
      anchorDirection: 'after',
      pageIndex: null,
    })
  })

  it('shows its filter map read-only: the preset, a change, and the reset back to the preset', () => {
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: () => {},
      initialFilter: { state: 'due' },
    })

    expect(controller.filter.get()).toEqual({ state: 'due' })

    controller.setFilter('state', '')
    expect(controller.filter.get()).toEqual({})

    controller.resetFilters()
    expect(controller.filter.get()).toEqual({ state: 'due' })
  })

  it('setSort toggles the direction on the same field', () => {
    const { controller, sent } = makeController()
    controller.setSort('key')
    expect(sent.at(-1)).toMatchObject({
      sort: [{ field: 'key', direction: 'asc' }],
    })
    controller.setSort('key')
    expect(sent.at(-1)).toMatchObject({
      sort: [{ field: 'key', direction: 'desc' }],
    })
  })

  it('setSort cycles ascending, descending, then back to the table initial sort', () => {
    const { controller, sent, open } = makeController(10, [
      { field: 'id', direction: 'asc' },
    ])
    open()
    controller.setSort('name')
    expect(sent.at(-1)).toMatchObject({
      sort: [{ field: 'name', direction: 'asc' }],
    })

    controller.setSort('name')
    expect(sent.at(-1)).toMatchObject({
      sort: [{ field: 'name', direction: 'desc' }],
    })

    controller.setSort('name')
    expect(sent.at(-1)).toMatchObject({
      sort: [{ field: 'id', direction: 'asc' }],
    })
  })

  it('setSort clears the sort when the table has no initial sort', () => {
    const { controller, sent, open } = makeController()
    open()
    controller.setSort('name')
    controller.setSort('name')
    controller.setSort('name')

    expect(sent.at(-1)).toEqual({
      filter: {},
      sort: null,
      limit: 10,
      anchor: null,
      anchorDirection: 'after',
      pageIndex: null,
    })
  })

  it('setSort never repeats the shown state on the initially sorted column', () => {
    const { controller, sent, open } = makeController(10, [
      { field: 'created', direction: 'desc' },
    ])
    open()
    controller.setSort('created')
    expect(sent.at(-1)).toMatchObject({
      sort: [{ field: 'created', direction: 'asc' }],
    })

    controller.setSort('created')
    expect(sent.at(-1)).toMatchObject({
      sort: [{ field: 'created', direction: 'desc' }],
    })

    const sorts = sent.map((descriptor) => JSON.stringify(descriptor.sort))
    expect(
      sorts.filter((sort, index) => index > 0 && sort === sorts[index - 1]),
    ).toEqual([])
  })

  it('resetOrder returns to the initial order and the first page', () => {
    const { controller, sent, open } = makeController(10, [
      { field: 'id', direction: 'asc' },
    ])
    open([], 50, true, null, null) // 5 pages of 10
    controller.setSort('name')
    controller.setPage(2)
    controller.resetOrder()

    expect(sent.at(-1)).toEqual({
      filter: {},
      sort: [{ field: 'id', direction: 'asc' }],
      limit: 10,
      anchor: null,
      anchorDirection: 'after',
      pageIndex: null,
    })
  })

  it('setOrder sends a declared order whole and returns to the first page', () => {
    const { controller, sent, open } = makeController()
    open([], 50, true, null, null) // 5 pages of 10
    controller.setPage(2)
    controller.setOrder([
      { field: 'channel', direction: 'desc' },
      { field: 'created', direction: 'desc' },
    ])

    expect(sent.at(-1)).toEqual({
      filter: {},
      sort: [
        { field: 'channel', direction: 'desc' },
        { field: 'created', direction: 'desc' },
      ],
      limit: 10,
      anchor: null,
      anchorDirection: 'after',
      pageIndex: null,
    })
    expect(controller.page.get()).toBe(0)
  })

  it('a header click leaves a composite order rather than adding to it', () => {
    const { controller, sent } = makeController()
    controller.setOrder([
      { field: 'channel', direction: 'desc' },
      { field: 'created', direction: 'desc' },
    ])
    controller.setSort('channel')

    // The backend serves only orders a table declared, so a click that added a
    // column to the order would ask for one nobody offered.
    expect(sent.at(-1)).toMatchObject({
      sort: [{ field: 'channel', direction: 'asc' }],
    })
  })

  it('reports the orders a menu offers — each declared order followed by its mirror', () => {
    const declaredOrders = [
      {
        key: 'by_channel',
        components: [
          { field: 'channel', direction: 'desc' },
          { field: 'created', direction: 'desc' },
        ],
      },
    ] as const
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: () => {},
      declaredOrders,
    })

    // The table declares one order and the menu offers two: the mirror is the
    // framework's, derived once from the declaration, never declared by a page.
    expect(controller.orders).toEqual([
      declaredOrders[0],
      {
        key: 'by_channel-mirror',
        components: [
          { field: 'channel', direction: 'asc' },
          { field: 'created', direction: 'asc' },
        ],
      },
    ])
  })

  it('a pick of the mirror lights up the mirror item and nothing else', () => {
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: () => {},
      declaredOrders: [
        {
          key: 'by_channel',
          components: [
            { field: 'channel', direction: 'desc' },
            { field: 'created', direction: 'desc' },
          ],
        },
      ],
      frame: {
        title: 'Messages',
        columns: [
          { key: 'channel', label: 'Kind', sortable: true },
          { key: 'created', label: 'Date', sortable: true },
        ],
      },
    })

    controller.setOrder([
      { field: 'channel', direction: 'asc' },
      { field: 'created', direction: 'asc' },
    ])

    expect(
      controller.frame.orders
        .get()
        .filter(({ active }) => active)
        .map(({ key }) => key),
    ).toEqual(['by_channel-mirror'])
    expect(controller.frame.orderLabel.get()).toBe('Kind ↑, then Date ↑')
  })

  it('the menu follows every way the order changes — a pick, a header click, a way home', () => {
    const declaredOrders = [
      {
        key: 'by_channel',
        components: [
          { field: 'channel', direction: 'desc' },
          { field: 'created', direction: 'desc' },
        ],
      },
    ] as const
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: () => {},
      declaredOrders,
      frame: {
        title: 'Messages',
        columns: [
          { key: 'channel', label: 'Kind', sortable: true },
          { key: 'created', label: 'Date', sortable: true },
        ],
      },
    })
    const activeKeys = (): readonly string[] =>
      controller.frame.orders
        .get()
        .filter(({ active }) => active)
        .map(({ key }) => key)

    // The table opens in the order the first window ran in — and that is where the
    // menu's first item points, from the first frame on.
    controller.ingestSubscriptionWindow(
      [],
      0,
      true,
      null,
      null,
      10,
      [{ field: 'created', direction: 'desc' }],
      [],
    )
    expect(controller.frame.orders.get()[0]?.label).toBe('Date ↓')
    expect(activeKeys()).toEqual([HILOS_TABLE_OPENING_ORDER_KEY])
    expect(controller.frame.orderLabel.get()).toBe('Date ↓')

    controller.setOrder([
      { field: 'channel', direction: 'desc' },
      { field: 'created', direction: 'desc' },
    ])
    expect(activeKeys()).toEqual(['by_channel'])
    expect(controller.frame.orderLabel.get()).toBe('Kind ↓, then Date ↓')

    // A header click leaves an order of one column: the menu offers it below md, so
    // its item is the active one, and the button says what the rows are sorted by.
    controller.setSort('channel')
    expect(activeKeys()).toEqual(['channel-asc'])
    expect(controller.frame.orderLabel.get()).toBe('Kind ↑')

    controller.resetOrder()
    expect(activeKeys()).toEqual([HILOS_TABLE_OPENING_ORDER_KEY])
    expect(controller.frame.orderLabel.get()).toBe('Date ↓')
  })

  it('a table declaring no orders reports an empty list rather than nothing', () => {
    const { controller } = makeController()

    expect(controller.orders).toEqual([])
  })

  it('reports the sortable columns in both directions ahead of the declared orders', () => {
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: () => {},
      declaredOrders: [
        {
          key: 'by_channel',
          components: [
            { field: 'channel', direction: 'desc' },
            { field: 'created', direction: 'desc' },
          ],
        },
      ],
      frame: {
        title: 'Messages',
        columns: [
          { key: 'channel', label: 'Kind', sortable: true },
          { key: 'body', label: 'Text' },
          { key: 'created', label: 'Date', sortable: true },
        ],
      },
    })

    expect(controller.orders.map(({ key }) => key)).toEqual([
      'channel-asc',
      'channel-desc',
      'created-asc',
      'created-desc',
      'by_channel',
      'by_channel-mirror',
    ])
  })

  it('a table with sortable columns and no declared order still has a menu, below md alone', () => {
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: () => {},
      frame: {
        title: 'Settings',
        columns: [
          { key: 'key', label: 'Key', sortable: true },
          { key: 'value', label: 'Value' },
        ],
      },
    })

    expect(controller.orders).toEqual([
      { key: 'key-asc', components: [{ field: 'key', direction: 'asc' }] },
      { key: 'key-desc', components: [{ field: 'key', direction: 'desc' }] },
    ])
    expect(
      controller.frame.orders.get().map(({ key, narrowOnly }) => ({
        key,
        narrowOnly,
      })),
    ).toEqual([
      { key: HILOS_TABLE_OPENING_ORDER_KEY, narrowOnly: true },
      { key: 'key-asc', narrowOnly: true },
      { key: 'key-desc', narrowOnly: true },
    ])
  })

  it('once the first window names the opening order, the menu drops the item of that order', () => {
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: () => {},
      frame: {
        title: 'Settings',
        columns: [{ key: 'key', label: 'Key', sortable: true }],
      },
    })

    controller.ingestSubscriptionWindow(
      [],
      0,
      true,
      null,
      null,
      10,
      [{ field: 'key', direction: 'asc' }],
      [],
    )

    // The way home names the opening order already; a second item would name it twice.
    expect(controller.frame.orders.get().map(({ key }) => key)).toEqual([
      HILOS_TABLE_OPENING_ORDER_KEY,
      'key-desc',
    ])
    expect(controller.frame.orders.get()[0]?.label).toBe('Key ↑')
  })

  it('setPage asks for the page by number, clamped to the page count', () => {
    const { controller, sent, open } = makeController()
    open([], 50, true, null, null) // 5 pages of 10
    controller.setPage(3)
    expect(sent.at(-1)).toMatchObject({ pageIndex: 3, anchor: null, limit: 10 })

    controller.setPage(99)
    expect(sent.at(-1)).toMatchObject({ pageIndex: 4 }) // clamped to the last page
  })

  it('nextPage asks for the rows after the window rather than for a page number', () => {
    const { controller, sent, open } = makeController()
    open([], 50, true, { id: 1 }, { id: 10 }) // 5 pages of 10

    controller.nextPage()

    expect(controller.page.get()).toBe(1)
    expect(sent.at(-1)).toMatchObject({
      anchor: { id: 10 },
      anchorDirection: 'after',
      pageIndex: null,
    })
  })

  it('prevPage asks for the rows before the window', () => {
    const { controller, sent, open } = makeController()
    open([], 50, true, { id: 1 }, { id: 10 }) // 5 pages of 10
    controller.setPage(3)
    open([], 50, true, { id: 31 }, { id: 40 })

    controller.prevPage()

    expect(controller.page.get()).toBe(2)
    expect(sent.at(-1)).toMatchObject({
      anchor: { id: 31 },
      anchorDirection: 'before',
      pageIndex: null,
    })
  })

  it('neither neighbour is asked for past the edge of the set', () => {
    const { controller, sent, open } = makeController()
    open([], 10, true, { id: 1 }, { id: 10 }) // one page

    controller.prevPage()
    controller.nextPage()

    expect(controller.page.get()).toBe(0)
    expect(sent).toEqual([])
  })

  it('an empty window is not paged from, however many pages the count claims', () => {
    const { controller, sent, open } = makeController()
    open([], 50, true, null, null)

    controller.nextPage()

    expect(controller.page.get()).toBe(0)
    expect(sent).toEqual([])
  })

  it('a new filter sends the window back to the start of the set', () => {
    const { controller, sent, open } = makeController()
    open([], 50, true, { id: 1 }, { id: 10 })
    controller.nextPage()
    controller.setFilter('status', 'failed')

    expect(controller.page.get()).toBe(0)
    expect(sent.at(-1)).toMatchObject({
      anchor: null,
      anchorDirection: 'after',
      pageIndex: null,
    })
  })

  it('ingestWindow sets the resolved rows and the total / page counts', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 42, true, null, null)

    expect(controller.rows.get()).toEqual([
      {
        rowKey: 'a',
        row: { rowKey: 'a', slots: {} },
        placeholder: false,
        pending: null,
        removal: null,
        highlighted: false,
        selected: false,
        expanded: false,
        staleSources: [],
      },
    ])
    expect(controller.totalCount.get()).toBe(42)
    expect(controller.pageCount.get()).toBe(5)
  })

  it('marks a shown row stale without touching its values or its pending change', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: { name: 'old' } }], 1, true, null, null)
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })
    controller.ingestDelta({
      kind: 'row_stale',
      rowKey: 'a',
      staleSources: ['presence'],
    })

    // The freshness mark is not a change to the row, so what the reader has not
    // accepted stays unaccepted and what is on screen stays on screen.
    expect(controller.pendingCount.get()).toBe(1)
    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'old' },
      staleSources: ['presence'],
    })
  })

  it('re-stamps a queued move, so Apply cannot take the mark off frozen values', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: { name: 'old' } }], 1, true, null, null)
    // The move was built and sent before the source went quiet, so it carries
    // the freshness of that earlier moment.
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })
    controller.ingestDelta({
      kind: 'row_stale',
      rowKey: 'a',
      staleSources: ['presence'],
    })
    controller.apply()

    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'new' },
      staleSources: ['presence'],
    })
  })

  it('clears the mark when the sources are current again', () => {
    const { controller, open } = makeController()
    open(
      [{ rowKey: 'a', slots: { name: 'old' }, staleSources: ['presence'] }],
      1,
      true,
      null,
      null,
    )
    controller.ingestDelta({
      kind: 'row_stale',
      rowKey: 'a',
      staleSources: [],
    })

    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'old' },
      staleSources: [],
    })
  })

  it("carries the raw row's frozen sources onto the view row", () => {
    const { controller, open } = makeController()
    open(
      [
        { rowKey: 'a', slots: {}, staleSources: ['connections'] },
        { rowKey: 'b', slots: {} },
      ],
      2,
      true,
      null,
      null,
    )

    const rows = controller.rows.get()
    expect(rows[0]?.staleSources).toEqual(['connections'])
    // A row nobody said anything about carries the empty list, not undefined: the
    // views read the field on every row they draw.
    expect(rows[1]?.staleSources).toEqual([])
  })

  it('leaves a placeholder with no frozen sources of its own', () => {
    const { controller, open } = makeController()
    open(
      [{ rowKey: 'a', slots: {}, staleSources: ['connections'] }],
      1,
      true,
      null,
      null,
    )
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()

    // The record is gone; there are no values left whose freshness could be spoken of.
    expect(controller.rows.get()[0]?.placeholder).toBe(true)
    expect(controller.rows.get()[0]?.staleSources).toEqual([])
  })

  it("moves the view row's frozen sources on a mark, leaving its pending change up", () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: { name: 'old' } }], 1, true, null, null)
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })
    controller.ingestDelta({
      kind: 'row_stale',
      rowKey: 'a',
      staleSources: ['connections'],
    })

    expect(controller.rows.get()[0]?.staleSources).toEqual(['connections'])
    expect(controller.rows.get()[0]?.pending).toBe('move')
    controller.ingestDelta({
      kind: 'row_stale',
      rowKey: 'a',
      staleSources: [],
    })

    expect(controller.rows.get()[0]?.staleSources).toEqual([])
    expect(controller.rows.get()[0]?.pending).toBe('move')
  })

  it('ignores a freshness mark for a row it is not showing', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: { name: 'old' } }], 1, true, null, null)
    controller.ingestDelta({
      kind: 'row_stale',
      rowKey: 'b',
      staleSources: ['presence'],
    })

    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'old' },
    })
  })

  it('gates every removal, the door a status row used to have being gone', () => {
    const { controller, open } = makeController()
    open(
      [
        { rowKey: 'a', slots: { name: 'stored' } },
        { rowKey: 'b', slots: { name: 'stored' } },
      ],
      2,
      true,
      null,
      null,
    )
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'b',
      reason: 'deleted',
    })

    // Until HIL-820 a backend could declare a removal live and take the row out from
    // under the reader; the one row that needed it is a bar of its own now, so the gate
    // holds every removal and only the author's own echo walks past it.
    expect(controller.pendingCount.get()).toBe(1)
    expect(controller.rows.get().map((row) => row.rowKey)).toEqual(['a', 'b'])
  })

  it('applies a value that left the row where it stood, raising no badge', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: { name: 'old' } }], 1, true, null, null)
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })

    // Nothing moved, so there is nothing to hold: the row takes the value and is
    // marked as just changed, and no badge is raised for an Apply that would do
    // nothing (HIL-793).
    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'new' },
    })
    expect(controller.rows.get()[0]?.highlighted).toBe(true)
  })

  it('a value landing resolves what was waiting on the same row', () => {
    const { controller, open } = makeController()
    open(
      [
        { rowKey: 'a', slots: { name: 'old' } },
        { rowKey: 'b', slots: {} },
      ],
      2,
      true,
      null,
      null,
    )
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'moving' } },
      position: 1,
    })
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'newest' } },
    })

    // The value is the later word about the row, and the slot the queued move named
    // was computed for a row that no longer exists.
    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get().map((row) => row.rowKey)).toEqual(['a', 'b'])
    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'newest' },
    })
  })

  it('holds a move until Apply, then puts the row in the slot the server named', () => {
    const { controller, open } = makeController()
    open(
      [
        { rowKey: 'a', slots: { name: 'first' } },
        { rowKey: 'b', slots: {} },
        { rowKey: 'c', slots: {} },
      ],
      3,
      true,
      null,
      null,
    )
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'last' } },
      position: 2,
    })

    // A move is movement under the reader: nothing changes until they ask for it.
    expect(controller.pendingCount.get()).toBe(1)
    expect(controller.rows.get()[0]?.pending).toBe('move')
    expect(controller.rows.get().map((row) => row.rowKey)).toEqual([
      'a',
      'b',
      'c',
    ])

    controller.apply()
    expect(controller.rows.get().map((row) => row.rowKey)).toEqual([
      'b',
      'c',
      'a',
    ])
    expect(controller.rows.get()[2]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'last' },
    })
    expect(controller.rows.get()[2]?.highlighted).toBe(true)
    expect(controller.pendingCount.get()).toBe(0)
  })

  it('a move with no slot updates the row where it already stands', () => {
    const { controller, open } = makeController()
    open(
      [
        { rowKey: 'a', slots: { name: 'old' } },
        { rowKey: 'b', slots: {} },
      ],
      2,
      true,
      null,
      null,
    )
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })
    controller.apply()

    // The table could not name a place, and an index nobody computed would move the
    // row away from the one a reload gives it.
    expect(controller.rows.get().map((row) => row.rowKey)).toEqual(['a', 'b'])
    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'new' },
    })
  })

  it('applies a row that moved out of the window as a placeholder', () => {
    const { controller, open } = makeController()
    open(
      [
        { rowKey: 'a', slots: {} },
        { rowKey: 'b', slots: {} },
      ],
      2,
      true,
      null,
      null,
    )
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'moved_out',
    })
    expect(controller.rows.get()[0]?.pending).toBe('remove')

    controller.apply()
    expect(controller.rows.get()[0]?.placeholder).toBe(true)
    expect(controller.rows.get()).toHaveLength(2)
  })

  it('applies a removal as a placeholder in its slot', () => {
    const { controller, open } = makeController()
    open(
      [
        { rowKey: 'a', slots: {} },
        { rowKey: 'b', slots: {} },
      ],
      2,
      true,
      null,
      null,
    )
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()

    const rows = controller.rows.get()
    expect(rows).toHaveLength(2) // layout not collapsed
    expect(rows[0]).toEqual({
      rowKey: 'a',
      row: null,
      placeholder: true,
      pending: null,
      removal: 'deleted',
      highlighted: false,
      selected: false,
      expanded: false,
      staleSources: [],
    })
    expect(rows[1]?.placeholder).toBe(false)
  })

  it('keeps each removal reason on the waiting row and its placeholder', () => {
    const { controller, open } = makeController()
    open(
      [
        { rowKey: 'deleted', slots: {} },
        { rowKey: 'moved', slots: {} },
        { rowKey: 'left', slots: {} },
      ],
      3,
      true,
      null,
      null,
    )

    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'deleted',
      reason: 'deleted',
    })
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'moved',
      reason: 'moved_out',
    })
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'left',
      reason: 'left_set',
    })

    expect(controller.rows.get().map((row) => row.removal)).toEqual([
      'deleted',
      'moved_out',
      'left_set',
    ])

    controller.apply()

    expect(controller.rows.get().map((row) => row.removal)).toEqual([
      'deleted',
      'moved_out',
      'left_set',
    ])
    expect(controller.rows.get().every((row) => row.placeholder)).toBe(true)
  })

  it('empties a window whose last live row leaves an empty set on Apply', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, { id: 1 }, { id: 1 })
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.ingestCount(0, true)

    controller.apply()

    expect(controller.rows.get()).toEqual([])
    expect(controller.frame.body.get()).toBe('empty')
  })

  it('shows filtered emptiness when a searched window converges', () => {
    const { controller, open } = makeController()
    controller.setSearch('nightly')
    open([{ rowKey: 'a', slots: {} }], 1, true, { id: 1 }, { id: 1 })
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'left_set',
    })
    controller.ingestCount(0, true)

    controller.apply()

    expect(controller.rows.get()).toEqual([])
    expect(controller.frame.body.get()).toBe('empty_filtered')
  })

  it('converges when a zero count arrives after Apply', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, { id: 1 }, { id: 1 })
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()
    expect(controller.rows.get()[0]?.placeholder).toBe(true)

    controller.ingestCount(0, true)

    expect(controller.rows.get()).toEqual([])
    expect(controller.frame.body.get()).toBe('empty')
  })

  it('keeps a window of placeholders while the set still has rows', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 3, true, { id: 1 }, { id: 1 })
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'moved_out',
    })

    controller.apply()

    expect(controller.rows.get()[0]).toMatchObject({
      placeholder: true,
      removal: 'moved_out',
    })
    expect(controller.frame.body.get()).toBe('rows')
  })

  it('keeps work and its report while convergence clears window marks', () => {
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: () => {},
      frame: {
        columns: [],
        bulkActions: [
          {
            key: 'delete',
            label: 'Delete',
            run: () => {
              throw new Error('not called')
            },
          },
        ],
      },
    })
    controller.ingestSubscriptionWindow(
      [{ rowKey: 'a', slots: {} }],
      1,
      true,
      { id: 1 },
      { id: 1 },
      10,
      undefined,
      [],
      5,
    )
    controller.selectAllByFilter()
    controller.expandRow('a', true)
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 1,
      total: 2,
    })
    controller.ingestBulkReport({
      progressKey: 'delete-1',
      touched: 1,
      untouched: [],
      untouchedOmitted: 0,
    })
    controller.ingestAnnounce('b', 'above', 2, true)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.ingestCount(0, true)

    controller.apply()

    expect(controller.rows.get()).toEqual([])
    expect(controller.rowsBefore.get()).toBe(0)
    expect(controller.selection.target.get()).toBeNull()
    expect(controller.announced.get().total).toBe(0)
    expect(controller.progress.table.get()?.progressKey).toBe('nightly')
    expect(controller.bulk.report.get()?.progressKey).toBe('delete-1')
  })

  it('keeps an unknown window place unknown when it converges', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, { id: 1 }, { id: 1 })
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.ingestCount(0, true)
    controller.apply()

    expect(controller.rowsBefore.get()).toBeNull()
  })

  it('does not bring old placeholders back with a tail append after convergence', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, { id: 1 }, { id: 1 })
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.ingestCount(0, true)
    controller.apply()

    controller.ingestAppend({ rowKey: 'b', slots: {} }, 1, true)

    expect(controller.rows.get().map((row) => row.rowKey)).toEqual(['b'])
    expect(controller.rows.get()[0]?.placeholder).toBe(false)
  })

  it('applies a live count update at once without pending', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)
    controller.ingestCount(5, true)

    expect(controller.totalCount.get()).toBe(5)
    expect(controller.pendingCount.get()).toBe(0)
  })

  it('counts an announced row under its place and takes its counts', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)
    controller.ingestAnnounce('b', 'above', 2, true)
    controller.ingestAnnounce('c', 'inside', 3, true)

    expect(controller.announced.get()).toEqual({
      above: 1,
      inside: 1,
      total: 2,
    })
    expect(controller.totalCount.get()).toBe(3)
    expect(controller.rows.get().map((row) => row.rowKey)).toEqual(['a'])
    expect(controller.pendingCount.get()).toBe(0)
  })

  it('counts a row announced twice once, wherever the repeat places it', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)
    controller.ingestAnnounce('b', 'above', 2, true)
    controller.ingestAnnounce('b', 'above', 2, true)
    controller.ingestAnnounce('b', 'inside', 2, true)

    expect(controller.announced.get()).toEqual({
      above: 1,
      inside: 0,
      total: 1,
    })
  })

  it('takes back an announced row from either place, leaving the count alone', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)
    controller.ingestAnnounce('b', 'above', 2, true)
    controller.ingestAnnounce('c', 'inside', 3, true)
    controller.ingestCount(1, true)

    controller.ingestUnannounce('b')
    controller.ingestUnannounce('c')

    expect(controller.announced.get()).toEqual({
      above: 0,
      inside: 0,
      total: 0,
    })
    expect(controller.totalCount.get()).toBe(1)
  })

  it('moves nothing when the same row is taken back twice', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)
    controller.ingestAnnounce('b', 'above', 2, true)
    controller.ingestAnnounce('c', 'above', 3, true)
    controller.ingestUnannounce('b')
    const after = controller.announced.get()

    controller.ingestUnannounce('b')

    expect(controller.announced.get()).toBe(after)
    expect(after).toEqual({ above: 1, inside: 0, total: 1 })
  })

  it('drops a row it was never told about without touching the strip', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)
    controller.ingestAnnounce('b', 'above', 2, true)
    const before = controller.announced.get()

    controller.ingestUnannounce('x')

    // The same object, not an equal one: the signal was not rebuilt, so its readers
    // were not woken by a delete that changes nothing on the strip.
    expect(controller.announced.get()).toBe(before)
    expect(before).toEqual({ above: 1, inside: 0, total: 1 })
    expect(controller.totalCount.get()).toBe(2)
  })

  it('forgets what was announced when any window arrives', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)
    controller.ingestAnnounce('b', 'above', 2, true)

    // The window nobody asked for: a refresh, or a re-subscribe after a broken socket.
    controller.ingestWindow(
      [
        { rowKey: 'a', slots: {} },
        { rowKey: 'b', slots: {} },
      ],
      2,
      true,
      null,
      null,
      10,
    )

    expect(controller.announced.get()).toEqual({
      above: 0,
      inside: 0,
      total: 0,
    })
  })

  it('shows what was announced by asking for the window again at the same address', () => {
    const { controller, sent, open } = makeController()
    // Three pages of ten, so the page the reader is standing on is one it can stand on.
    open([{ rowKey: 'a', slots: {} }], 25, true, null, null)
    controller.setPage(2)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.ingestAnnounce('b', 'above', 26, true)
    const before = sent.length

    controller.show()

    expect(controller.announced.get().total).toBe(0)
    expect(controller.pendingCount.get()).toBe(0)
    expect(sent).toHaveLength(before + 1)
    expect(sent[sent.length - 1]?.pageIndex).toBe(2)
  })

  it('appends a live tail row at once and bumps the total', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)
    controller.ingestAppend({ rowKey: 'b', slots: {} }, 2, true)

    const rows = controller.rows.get()
    expect(rows.map((row) => row.rowKey)).toEqual(['a', 'b'])
    expect(controller.totalCount.get()).toBe(2)
    expect(controller.pendingCount.get()).toBe(0)
  })

  it('inserts the author own new row at the position the server computed', () => {
    const { controller, open } = makeController()
    open(
      [
        { rowKey: 'a', slots: {} },
        { rowKey: 'c', slots: {} },
      ],
      2,
      true,
      null,
      null,
    )
    controller.ingestOwnCreate({ rowKey: 'b', slots: {} }, 1, 3, true, 'req-1')

    const rows = controller.rows.get()
    expect(rows.map((row) => row.rowKey)).toEqual(['a', 'b', 'c'])
    expect(controller.totalCount.get()).toBe(3)
    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.ownCreateRequestId.get()).toBe('req-1')
  })

  it('keeps the window at its page size, dropping what the insert pushes past the end', () => {
    const { controller, open } = makeController(2)
    open(
      [
        { rowKey: 'a', slots: {} },
        { rowKey: 'c', slots: {} },
      ],
      2,
      true,
      null,
      null,
    )
    controller.ingestOwnCreate({ rowKey: 'b', slots: {} }, 1, 3, true)

    expect(controller.rows.get().map((row) => row.rowKey)).toEqual(['a', 'b'])
    expect(controller.totalCount.get()).toBe(3)
  })

  it('an evicted row takes its pending change and its placeholder with it', () => {
    const { controller, open } = makeController(2)
    open(
      [
        { rowKey: 'a', slots: {} },
        { rowKey: 'c', slots: {} },
      ],
      2,
      true,
      null,
      null,
    )
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'c',
      row: { rowKey: 'c', slots: { name: 'edited' } },
    })
    expect(controller.pendingCount.get()).toBe(1)

    controller.ingestOwnCreate({ rowKey: 'b', slots: {} }, 1, 3, true)

    // 'c' left the window, so the change waiting on it is no longer anyone's to apply.
    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get().map((row) => row.rowKey)).toEqual(['a', 'b'])
  })

  it('an own create clears the tombstone a reused row key left in the window', () => {
    const { controller, open } = makeController()
    open(
      [
        { rowKey: 'a', slots: {} },
        { rowKey: 'theme', slots: {} },
      ],
      2,
      true,
      null,
      null,
    )
    // The row is deleted and its removal applied, so a placeholder stands in its slot.
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'theme',
      reason: 'deleted',
      own: true,
    })
    expect(controller.rows.get()[1]?.placeholder).toBe(true)

    // The same key is created again. The server forgot the deleted row, so it sends
    // this as an own create rather than as an update.
    controller.ingestOwnCreate(
      { rowKey: 'theme', slots: { name: 'dark' } },
      1,
      2,
      true,
    )

    const rows = controller.rows.get()
    expect(rows.map((row) => row.rowKey)).toEqual(['a', 'theme'])
    expect(rows[1]?.placeholder).toBe(false)
    expect(rows[1]?.row).toEqual({ rowKey: 'theme', slots: { name: 'dark' } })
  })

  it('an own create with no action behind it reports no request id', () => {
    const { controller, open } = makeController()
    open([], 0, true, null, null)
    controller.ingestOwnCreate({ rowKey: 'a', slots: {} }, 0, 1, true, null)

    expect(controller.rows.get().map((row) => row.rowKey)).toEqual(['a'])
    expect(controller.ownCreateRequestId.get()).toBeNull()
  })

  it('ignores a delta for a row outside the window', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'z',
      row: { rowKey: 'z', slots: {} },
      position: 0,
    })

    expect(controller.pendingCount.get()).toBe(0)
  })

  it('discards pending when the window changes', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    expect(controller.pendingCount.get()).toBe(1)

    controller.setSearch('x')
    expect(controller.pendingCount.get()).toBe(0)
  })

  it('applies a server-tagged own change at once, with no pre-mark', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: { name: 'old' } }], 1, true, null, null)
    // The backend tags the delta `own` for the author connection; the client keeps
    // no per-row mark and simply trusts it.
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
      own: true,
    })

    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'new' },
    })
  })

  it('a server-tagged own change resolves a pending change racing on the same row', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: { name: 'old' } }], 1, true, null, null)
    // A concurrent change for the same row lands first as pending (not own)...
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'other' } },
    })
    expect(controller.pendingCount.get()).toBe(1)
    // ...then the server tags this tab as the winning author, so it applies at once
    // and clears the pending — the race a client-side row-key mark left open is gone.
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'mine' } },
      own: true,
    })

    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'mine' },
    })
  })

  it('applies a server-tagged own removal at once as a placeholder', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'left_set',
      own: true,
    })

    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get()[0]).toEqual({
      rowKey: 'a',
      row: null,
      placeholder: true,
      pending: null,
      removal: 'left_set',
      highlighted: false,
      selected: false,
      expanded: false,
      staleSources: [],
    })
  })

  it('applies a server-tagged own edit to a server-minted create key the client never pre-marked', () => {
    const { controller, open } = makeController()
    open([], 0, true, null, null)
    // The server mints the new row's key and appends it live — the old client-side
    // mark could never have named it up front.
    controller.ingestAppend(
      { rowKey: 'srv-1', slots: { name: 'fresh' } },
      1,
      true,
    )
    // A follow-up own edit to that minted key still applies at once via the server tag.
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'srv-1',
      row: { rowKey: 'srv-1', slots: { name: 'edited' } },
      own: true,
    })

    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'srv-1',
      slots: { name: 'edited' },
    })
  })

  it('queues an untagged delta as pending — only the server can grant own', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: { name: 'old' } }], 1, true, null, null)
    // No own tag: another connection's move gates as pending, never auto-applies.
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'theirs' } },
    })

    expect(controller.pendingCount.get()).toBe(1)
    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'old' },
    })
  })

  it('is not loaded until the first window arrives', () => {
    const { controller, open } = makeController()
    expect(controller.loaded.get()).toBe(false)
    open([], 0, true, null, null)
    expect(controller.loaded.get()).toBe(true)
  })

  it('keeps the rows through a quick window change and draws the skeleton only past the threshold', () => {
    vi.useFakeTimers()
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)

    controller.setSort('name')

    // An answer on a near machine lands in tens of milliseconds: until the threshold the
    // previous rows are what the body shows, or every press would flash a skeleton.
    vi.advanceTimersByTime(399)
    expect(controller.frame.body.get()).toBe('rows')

    vi.advanceTimersByTime(1)
    expect(controller.frame.body.get()).toBe('loading')
  })

  it('takes the skeleton down and stops its countdown when the window arrives', () => {
    vi.useFakeTimers()
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)

    controller.setSort('name')
    vi.advanceTimersByTime(400)
    expect(controller.frame.body.get()).toBe('loading')
    open([{ rowKey: 'b', slots: {} }], 1, true, null, null)
    expect(controller.frame.body.get()).toBe('rows')

    // A window that arrives before the threshold leaves no countdown behind to fire into
    // the rows that came.
    controller.setSort('name')
    vi.advanceTimersByTime(100)
    open([{ rowKey: 'c', slots: {} }], 1, true, null, null)
    vi.advanceTimersByTime(1000)
    expect(controller.frame.body.get()).toBe('rows')
  })

  it('restarts the countdown on a second window change rather than keeping the first', () => {
    vi.useFakeTimers()
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)

    controller.setSort('name')
    vi.advanceTimersByTime(300)
    controller.setSort('name')
    vi.advanceTimersByTime(300)
    expect(controller.frame.body.get()).toBe('rows')

    vi.advanceTimersByTime(100)
    expect(controller.frame.body.get()).toBe('loading')
  })

  it('says loading before it says nothing was found, and nothing found once the window lands', () => {
    vi.useFakeTimers()
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)

    controller.setSearch('no such key')
    vi.advanceTimersByTime(400)
    // The search is already active, but the answer to it has not come: the empty set it
    // might name is not known yet.
    expect(controller.frame.body.get()).toBe('loading')

    open([], 0, true, null, null)
    expect(controller.frame.body.get()).toBe('empty_filtered')
  })

  it('stands unavailable on a refusal, with the code on the frame and an empty footer range', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, { id: 1 }, { id: 1 })

    controller.ingestRefusal('internal_error')

    expect(controller.frame.body.get()).toBe('unavailable')
    expect(controller.frame.refusal.get()).toBe('internal_error')
    expect(controller.frame.footer.get().firstRow).toBe(0)
    expect(controller.rows.get()).toEqual([])
    expect(controller.loaded.get()).toBe(true)
  })

  it('takes a window after a refusal as the way out', () => {
    const { controller, open } = makeController()
    controller.ingestRefusal('internal_error')
    open([{ rowKey: 'a', slots: {} }], 1, true, { id: 1 }, { id: 1 })

    expect(controller.frame.body.get()).toBe('rows')
    expect(controller.frame.refusal.get()).toBeNull()
    expect(controller.rows.get()[0]?.rowKey).toBe('a')
  })

  it('drops window frames while refused and still takes a progress bar', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, { id: 1 }, { id: 1 })
    controller.ingestRefusal('table_not_served')

    controller.ingestCount(9, true)
    controller.ingestAnnounce('b', 'above', 9, true)
    controller.ingestUnannounce('b')
    controller.ingestAppend({ rowKey: 'c', slots: {} }, 2, true)
    controller.ingestOwnCreate({ rowKey: 'd', slots: {} }, 0, 2, true)
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 3,
      total: 11,
    })

    expect(controller.frame.body.get()).toBe('unavailable')
    expect(controller.rows.get()).toEqual([])
    expect(controller.totalCount.get()).toBe(0)
    expect(controller.progress.table.get()?.progressKey).toBe('nightly')
  })

  it('says loading after the skeleton threshold when the window changes under a refusal', () => {
    vi.useFakeTimers()
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, { id: 1 }, { id: 1 })
    controller.ingestRefusal('internal_error')

    controller.setSort('name')
    vi.advanceTimersByTime(399)
    expect(controller.frame.body.get()).toBe('unavailable')

    vi.advanceTimersByTime(1)
    expect(controller.frame.body.get()).toBe('loading')
  })

  it('gives the window size the last window was served at', () => {
    const { controller, open } = makeController(25)
    expect(controller.pageSize.get()).toBe(1)

    open([], 0, true, null, null)
    expect(controller.pageSize.get()).toBe(25)
  })

  it('exposes the pending kind on the affected rows', () => {
    const { controller, open } = makeController()
    open(
      [
        { rowKey: 'a', slots: {} },
        { rowKey: 'b', slots: {} },
        { rowKey: 'c', slots: {} },
      ],
      3,
      true,
      null,
      null,
    )
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: {} },
      position: 2,
    })
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'b',
      reason: 'deleted',
    })

    const rows = controller.rows.get()
    expect(rows[0]?.pending).toBe('move')
    expect(rows[1]?.pending).toBe('remove')
    expect(rows[2]?.pending).toBeNull()
  })

  it('applyAndResolve applies pending and returns the fresh row', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: { name: 'old' } }], 1, true, null, null)
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })

    expect(controller.applyAndResolve('a')).toEqual({
      rowKey: 'a',
      slots: { name: 'new' },
    })
    expect(controller.pendingCount.get()).toBe(0)
  })

  it('takes the mark off the row when its couple of seconds are up', () => {
    vi.useFakeTimers()
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: { name: 'old' } }], 1, true, null, null)
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })
    expect(controller.rows.get()[0]?.highlighted).toBe(true)

    vi.advanceTimersByTime(2000)

    // The value stays; only the mark that pointed at it goes.
    expect(controller.rows.get()[0]?.highlighted).toBe(false)
    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'new' },
    })
  })

  it('a window change takes the marks off and stops their countdowns', () => {
    vi.useFakeTimers()
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: { name: 'old' } }], 1, true, null, null)
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })

    controller.setSearch('x')
    expect(controller.rows.get()[0]?.highlighted).toBe(false)

    // The countdown went with the mark: the rows of the window that arrives next are
    // not the rows it was started for, and it must not reach into them.
    open([{ rowKey: 'a', slots: { name: 'new' } }], 1, true, null, null)
    vi.advanceTimersByTime(2000)
    expect(controller.rows.get()[0]?.highlighted).toBe(false)
  })

  it('a window arriving on its own puts the marks out too', () => {
    vi.useFakeTimers()
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: { name: 'old' } }], 1, true, null, null)
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })
    expect(controller.rows.get()[0]?.highlighted).toBe(true)

    // Nobody asked for this one: it is the window a tab gets served back after a broken
    // socket, and the mark it would keep was started for the rows it replaced.
    open([{ rowKey: 'a', slots: { name: 'newer' } }], 1, true, null, null)

    expect(controller.rows.get()[0]?.highlighted).toBe(false)
    vi.advanceTimersByTime(2000)
    expect(controller.rows.get()[0]?.highlighted).toBe(false)
  })

  it('applies the author own move at once, in the slot the sort gives it', () => {
    const { controller, open } = makeController()
    open(
      [
        { rowKey: 'a', slots: { name: 'mine' } },
        { rowKey: 'b', slots: {} },
        { rowKey: 'c', slots: {} },
      ],
      3,
      true,
      null,
      null,
    )
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'renamed' } },
      position: 2,
      own: true,
    })

    // They pressed the button and are looking at the result: the row standing where
    // the sort no longer puts it would be the surprise, not the movement.
    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get().map((row) => row.rowKey)).toEqual([
      'b',
      'c',
      'a',
    ])
    expect(controller.rows.get()[2]?.highlighted).toBe(true)
  })

  it('applyAndResolve returns null for a row whose removal it applies', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })

    expect(controller.applyAndResolve('a')).toBeNull()
  })

  it('holds the three bars apart, each in its own place', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)

    controller.ingestProgress({
      scope: 'row',
      progressKey: 'backup-17',
      rowKey: 'a',
      current: 3,
      total: 11,
    })
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 34,
      total: 120,
    })
    controller.ingestProgress({
      scope: 'bulk',
      progressKey: 'delete-40',
      current: 12,
      total: 40,
    })

    expect(controller.progress.rows.get().get('a')?.progressKey).toBe(
      'backup-17',
    )
    expect(controller.progress.table.get()?.progressKey).toBe('nightly')
    expect(controller.progress.bulk.get()?.progressKey).toBe('delete-40')
  })

  it('recomputes the live room whenever any of its six sources changes', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: { name: 'old' } }], 1, true, null, null)
    expect(controller.live.get()).toEqual({ top: null, rest: [] })

    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 34,
      total: 120,
    })
    expect(controller.live.get()).toEqual({ top: 'progress', rest: [] })

    controller.ingestDelta({
      kind: 'row_stale',
      rowKey: 'a',
      staleSources: ['presence'],
    })
    expect(controller.live.get()).toEqual({
      top: 'stale',
      rest: ['progress'],
    })

    controller.ingestAnnounce('b', 'above', 2, true)
    expect(controller.live.get()).toEqual({
      top: 'announce',
      rest: ['stale', 'progress'],
    })

    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })
    expect(controller.live.get()).toEqual({
      top: 'pending',
      rest: ['announce', 'stale', 'progress'],
    })

    controller.ingestProgress({
      scope: 'bulk',
      progressKey: 'delete-40',
      current: 12,
      total: 40,
    })
    expect(controller.live.get()).toEqual({
      top: 'bulk',
      rest: ['pending', 'announce', 'stale', 'progress'],
    })

    controller.ingestBulkReport({
      progressKey: 'delete-40',
      touched: 38,
      untouched: [{ rowKey: 'x', reason: 'locked' }],
      untouchedOmitted: 0,
    })
    expect(controller.live.get()).toEqual({
      top: 'report',
      rest: ['bulk', 'pending', 'announce', 'stale', 'progress'],
    })

    controller.dismissBulkReport('delete-40')
    expect(controller.live.get()).toEqual({
      top: 'bulk',
      rest: ['pending', 'announce', 'stale', 'progress'],
    })

    // Taking a source away takes its kind out of the room, and the next one moves up.
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 120,
      total: 120,
      ended: true,
    })
    expect(controller.live.get()).toEqual({
      top: 'bulk',
      rest: ['pending', 'announce', 'stale'],
    })
  })

  it('raises the announce message for rows announced inside the window alone', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)

    controller.ingestAnnounce('b', 'inside', 2, true)

    expect(controller.live.get()).toEqual({ top: 'announce', rest: [] })
  })

  it('runBulk sets bulk.started on acceptance and ignores refusal without unhandled rejection', async () => {
    const { controller } = makeController()
    expect(controller.bulk.started.get()).toBeNull()

    // 1. Success case
    let resolveSuccess!: (result: { reply: HilosTableBulkAccepted }) => void
    const successHandle: ActionHandle<HilosTableBulkAccepted> = {
      requestId: 'req-1',
      loading: createSignal(false),
      done: new Promise<{ reply: HilosTableBulkAccepted }>((res) => {
        resolveSuccess = res
      }),
    }
    const successAction: HilosTableBulkAction = {
      key: 'delete',
      label: 'Delete rows',
      run: vi.fn(() => successHandle),
    }

    const retHandle = controller.runBulk(successAction, {
      kind: 'rows',
      rowKeys: ['a'],
    })
    expect(retHandle).toBe(successHandle)
    expect(controller.bulk.started.get()).toBeNull()

    resolveSuccess({ reply: { progressKey: 'del-1', total: 1 } })
    await successHandle.done
    await Promise.resolve()

    expect(controller.bulk.started.get()).toEqual({
      progressKey: 'del-1',
      label: 'Delete rows',
    })

    // 2. Refusal case
    let rejectFail!: (err: Error) => void
    const failHandle: ActionHandle<HilosTableBulkAccepted> = {
      requestId: 'req-2',
      loading: createSignal(false),
      done: new Promise<never>((_, rej) => {
        rejectFail = rej
      }),
    }
    const failAction: HilosTableBulkAction = {
      key: 'delete',
      label: 'Delete rows',
      run: vi.fn(() => failHandle),
    }

    controller.runBulk(failAction, {
      kind: 'rows',
      rowKeys: ['b'],
    })
    rejectFail(new Error('Refused'))

    await failHandle.done.catch(() => undefined)
    await Promise.resolve()

    expect(controller.bulk.started.get()).toEqual({
      progressKey: 'del-1',
      label: 'Delete rows',
    })
  })

  it('dismissBulkReport clears report matching progressKey and leaves fresher report untouched', () => {
    const { controller } = makeController()
    controller.ingestBulkReport({
      progressKey: 'run-1',
      touched: 5,
      untouched: [],
      untouchedOmitted: 0,
    })
    expect(controller.bulk.report.get()?.progressKey).toBe('run-1')

    controller.dismissBulkReport('run-2')
    expect(controller.bulk.report.get()?.progressKey).toBe('run-1')

    controller.dismissBulkReport('run-1')
    expect(controller.bulk.report.get()).toBeNull()
  })

  it('computes the fraction, clamps it, and leaves it out where there is no total', () => {
    const { controller, open } = makeController()
    open([], 0, true, null, null)

    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 30,
      total: 120,
    })
    expect(controller.progress.table.get()?.fraction).toBe(0.25)

    // A wrong number still has to say that work is running, so it is clamped rather
    // than thrown away.
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 500,
      total: 120,
    })
    expect(controller.progress.table.get()?.fraction).toBe(1)

    // No total is the indeterminate bar of design debt D-045, not a bar at zero.
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 30,
    })
    expect(controller.progress.table.get()?.fraction).toBeNull()
    expect(controller.progress.table.get()?.total).toBeNull()
    expect(controller.progress.table.get()?.detail).toEqual({})
  })

  it('lets a new run replace the bar, and a late end of the old one leave it alone', () => {
    const { controller, open } = makeController()
    open([], 0, true, null, null)

    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 34,
      total: 120,
    })
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'morning',
      current: 1,
      total: 5,
    })
    expect(controller.progress.table.get()?.progressKey).toBe('morning')

    // The end of the run that is over must not take down the run that has started:
    // that is the same break this channel exists to fix, only mirrored.
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 120,
      total: 120,
      ended: true,
    })
    expect(controller.progress.table.get()?.progressKey).toBe('morning')

    controller.ingestProgress({
      scope: 'table',
      progressKey: 'morning',
      current: 5,
      total: 5,
      ended: true,
    })
    expect(controller.progress.table.get()).toBeNull()
  })

  it('takes a row bar down when its own run ends, and not on another key', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)

    controller.ingestProgress({
      scope: 'row',
      progressKey: 'backup-17',
      rowKey: 'a',
      current: 3,
      total: 11,
    })
    controller.ingestProgress({
      scope: 'row',
      progressKey: 'backup-16',
      rowKey: 'a',
      current: 11,
      total: 11,
      ended: true,
    })
    expect(controller.progress.rows.get().get('a')?.progressKey).toBe(
      'backup-17',
    )

    controller.ingestProgress({
      scope: 'row',
      progressKey: 'backup-17',
      rowKey: 'a',
      current: 11,
      total: 11,
      ended: true,
    })
    expect(controller.progress.rows.get().has('a')).toBe(false)
  })

  it('leaves every bar standing when the window changes', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)
    controller.ingestProgress({
      scope: 'row',
      progressKey: 'backup-17',
      rowKey: 'a',
      current: 3,
      total: 11,
    })
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 34,
      total: 120,
    })
    controller.ingestProgress({
      scope: 'bulk',
      progressKey: 'delete-40',
      current: 12,
      total: 40,
    })

    // Unlike the marks, which a window change takes off: the work goes on whichever
    // page is being looked at, and a bulk operation outlives the window that started it.
    controller.setSearch('x')
    expect(controller.progress.rows.get().has('a')).toBe(true)
    expect(controller.progress.table.get()).not.toBeNull()
    expect(controller.progress.bulk.get()).not.toBeNull()
  })

  it('replaces every bar with the snapshot a subscription answer names', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)
    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 34,
      total: 120,
    })
    controller.ingestProgress({
      scope: 'row',
      progressKey: 'backup-17',
      rowKey: 'a',
      current: 3,
      total: 11,
    })

    open([{ rowKey: 'a', slots: {} }], 1, true, null, null, [
      { scope: 'bulk', progressKey: 'delete-40', current: 12, total: 40 },
    ])

    // The one cure for a bar left standing by a socket that broke mid-run: resubscribing
    // is the moment the server names the whole truth, and what it does not name is over.
    expect(controller.progress.bulk.get()?.progressKey).toBe('delete-40')
    expect(controller.progress.table.get()).toBeNull()
    expect(controller.progress.rows.get().size).toBe(0)

    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)
    expect(controller.progress.bulk.get()).toBeNull()
  })

  it('takes a row bar down with the word that its row is gone', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)
    controller.ingestProgress({
      scope: 'row',
      progressKey: 'backup-17',
      rowKey: 'a',
      current: 3,
      total: 11,
    })

    // At once, without waiting for Apply: the row is gone on the server, and the end of
    // its work may never arrive to take the bar down from under the placeholder.
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    expect(controller.progress.rows.get().has('a')).toBe(false)
    expect(controller.pendingCount.get()).toBe(1)
  })

  it('moves neither the count nor the marks when a bar arrives', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1, true, null, null)

    controller.ingestProgress({
      scope: 'row',
      progressKey: 'backup-17',
      rowKey: 'b',
      current: 3,
      total: 11,
    })

    // A bar is not a record of the set: it enters no count, takes no checkbox, and the
    // key it names need not be a row of the window at all.
    expect(controller.totalCount.get()).toBe(1)
    expect(controller.rows.get().map((row) => row.rowKey)).toEqual(['a'])
    expect(controller.selection.count.get()).toBe(0)
  })
})

/**
 * Where the window sits in its set, and everything the reader reads out of that (HIL-1093).
 *
 * The scenario these are cut from is the one the ticket was opened on: a reader standing on
 * the second page of twenty rows, a row created above the window by somebody else, Show
 * pressed. The window that comes back holds the same rows it held — that is what Show is for
 * — but they are now rows 12 through 21 of twenty-one, and a client counting presses of Next
 * still calls that "page 2 of 3", offers a Next that leads nowhere, and leaves no way back.
 */
describe('TableViewportController window place', () => {
  /** Page size every window below is served at, which is what makes 21 rows three pages. */
  const PAGE_SIZE = 10

  /** Rows of the tail window after Show: rows 12 through 21 of the set. */
  const tail = (): readonly TableRow[] =>
    Array.from({ length: PAGE_SIZE }, (_, index) => ({
      rowKey: `row-${index + 12}`,
      slots: {},
    }))

  function makePlaced() {
    const sent: TableViewportDescriptor[] = []
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: (descriptor) => sent.push(descriptor),
    })
    const open = (
      rows: readonly TableRow[],
      totalCount: number,
      rowsBefore: number | null,
      firstAnchor: TableAnchor | null = null,
      lastAnchor: TableAnchor | null = null,
    ): void =>
      controller.ingestSubscriptionWindow(
        rows,
        totalCount,
        true,
        firstAnchor,
        lastAnchor,
        PAGE_SIZE,
        undefined,
        [],
        rowsBefore,
      )

    return { controller, sent, open }
  }

  it('reads the page number and the shown range out of the place, not out of presses', () => {
    const { controller, open } = makePlaced()

    open(tail(), 21, 11, { id: 12 }, { id: 21 })

    const footer = controller.frame.footer.get()
    expect(footer.page).toBe(1)
    expect(footer.firstRow).toBe(12)
    expect(footer.lastRow).toBe(21)
    expect(footer.totalCount).toBe(21)
  })

  it('turns Next off on a window that ends the set, however it got there', () => {
    const { controller, open } = makePlaced()

    open(tail(), 21, 11, { id: 12 }, { id: 21 })

    // Eleven before it and ten in it is the whole set: there is nothing behind this window,
    // even though its place is not the last page number the count allows.
    expect(controller.hasNextPage.get()).toBe(false)
    expect(controller.frame.footer.get().hasNextPage).toBe(false)
  })

  it('keeps Back on wherever anything stands to the left', () => {
    const { controller, open } = makePlaced()

    open(tail(), 21, 11, { id: 12 }, { id: 21 })

    expect(controller.hasPreviousPage.get()).toBe(true)
  })

  it('pages back from the window standing between two pages by its own first row', () => {
    const { controller, sent, open } = makePlaced()
    open(tail(), 21, 11, { id: 12 }, { id: 21 })

    controller.prevPage()

    expect(sent.at(-1)?.anchor).toEqual({ id: 12 })
    expect(sent.at(-1)?.anchorDirection).toBe('before')
  })

  it('asks for the start of the set when less than a page stands to the left', () => {
    const { controller, sent, open } = makePlaced()
    // The window one press back from the tail: rows 2 through 11, one row to its left.
    open(tail(), 21, 1, { id: 2 }, { id: 11 })

    controller.prevPage()

    // Not "the rows before row 2", which would be a first page of one row while the row the
    // reader came back for sits at the top of the set.
    expect(sent.at(-1)?.anchor).toBeNull()
    expect(sent.at(-1)?.anchorDirection).toBe('after')
    expect(sent.at(-1)?.pageIndex).toBeNull()
  })

  it('pages back from an empty numbered page by number, that address having no anchor', () => {
    const { controller, sent, open } = makePlaced()
    open(tail(), 21, 11, { id: 12 }, { id: 21 })
    controller.setPage(2)
    // The rows the third page held went away while it was being asked for, so what comes
    // back is empty and sits where that page would have begun.
    open([], 21, 20)

    controller.prevPage()

    expect(sent.at(-1)?.pageIndex).toBe(1)
    expect(sent.at(-1)?.anchor).toBeNull()
  })

  it('pages back from an empty anchored window by reading its address the other way', () => {
    const { controller, sent, open } = makePlaced()
    const first = Array.from({ length: PAGE_SIZE }, (_, index) => ({
      rowKey: `row-${index + 1}`,
      slots: {},
    }))
    open(first, 21, 0, { id: 1 }, { id: 10 })
    controller.nextPage()
    // Asked for the rows after row 10 and given none: an address past the end of the set
    // stands behind the whole of it.
    open([], 21, 21)

    controller.prevPage()

    expect(sent.at(-1)?.anchor).toEqual({ id: 10 })
    expect(sent.at(-1)?.anchorDirection).toBe('before')
  })

  it('offers a way on from an empty window that was asked for backwards', () => {
    const { controller, sent, open } = makePlaced()
    const second = Array.from({ length: PAGE_SIZE }, (_, index) => ({
      rowKey: `row-${index + 11}`,
      slots: {},
    }))
    open(second, 21, 10, { id: 11 }, { id: 20 })
    controller.prevPage()
    // Asked for the rows before row 11 and given none — somebody deleted the first page
    // while it was being asked for. Nothing stands to the left of an address like that.
    open([], 11, 0)

    expect(controller.hasPreviousPage.get()).toBe(false)
    expect(controller.hasNextPage.get()).toBe(true)

    controller.nextPage()

    expect(sent.at(-1)?.anchor).toEqual({ id: 11 })
    expect(sent.at(-1)?.anchorDirection).toBe('after')
  })

  it('leaves the place where the window put it when a row is announced above it', () => {
    const { controller, open } = makePlaced()
    open(tail(), 21, 11, { id: 12 }, { id: 21 })

    controller.ingestAnnounce('row-1', 'above', 22, true)

    // The footer would otherwise travel under a reader who pressed nothing. The next window
    // is what makes the place true again.
    expect(controller.rowsBefore.get()).toBe(11)
    expect(controller.frame.footer.get().firstRow).toBe(12)
  })

  it('leaves Next off on an empty window with nothing to page from', () => {
    const { controller, sent, open } = makePlaced()
    // The first window of the set came back empty while the count still says the set has
    // rows — it raced a delete. Its address is the start of the set, so neither control has
    // an anchor or a page number to move to, and an offer that cannot be taken is worse
    // than no offer: the place says where this window sits, not what lies behind it.
    open([], 4, 0)
    const asked = sent.length

    expect(controller.hasNextPage.get()).toBe(false)
    expect(controller.hasPreviousPage.get()).toBe(false)

    controller.nextPage()

    expect(sent.length).toBe(asked)
  })

  it('counts presses where the window reports no place at all', () => {
    const { controller, open } = makePlaced()
    open(tail(), 21, null, { id: 12 }, { id: 21 })

    expect(controller.rowsBefore.get()).toBeNull()
    expect(controller.frame.footer.get().page).toBe(0)
    expect(controller.frame.footer.get().firstRow).toBe(1)
  })
})

describe('TableViewportController focus', () => {
  // A table whose rows open a dialog: it wires sendFocus, and its window holds two rows.
  function makeFocusedController() {
    const focus: string[] = []
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: () => {},
      sendFocus: (rowKey) => focus.push(rowKey),
    })
    const rowA: TableRow = { rowKey: 'a', slots: { v: 1 } }
    const rowB: TableRow = { rowKey: 'b', slots: { v: 2 } }
    controller.ingestSubscriptionWindow(
      [rowA, rowB],
      2,
      true,
      null,
      null,
      10,
      undefined,
      [],
    )

    return { controller, focus, rowA, rowB }
  }

  it('takes a fresh row into focus and tells the server', () => {
    const { controller, focus, rowA } = makeFocusedController()

    expect(controller.focusedRow.get()).toBeUndefined()
    expect(controller.focusRow('a')).toEqual(rowA)
    expect(focus).toEqual(['a'])
    expect(controller.focusedRow.get()).toEqual(rowA)
  })

  it('resolves what waits on the row first, as applyAndResolve does', () => {
    const { controller } = makeFocusedController()
    const moved: TableRow = { rowKey: 'a', slots: { v: 9 } }
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: moved,
      position: 1,
    })
    expect(controller.rows.get()[0]?.pending).toBe('move')

    expect(controller.focusRow('a')).toEqual(moved)
    expect(controller.rows.get().map((row) => row.pending)).toEqual([
      null,
      null,
    ])
    expect(controller.rows.get()[1]?.row).toEqual(moved)
  })

  it('declines a placeholder and a key off the window, holding nothing', () => {
    const { controller, focus } = makeFocusedController()
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()

    expect(controller.focusRow('a')).toBeNull()
    expect(controller.focusRow('nowhere')).toBeNull()
    expect(focus).toEqual([])
    expect(controller.focusedRow.get()).toBeUndefined()
  })

  it('refuses a table that wired no sendFocus', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: {} }], 1)

    // The old silent hole — a dialog reading a row nobody follows — is not a state to fall
    // back into: a table whose rows open a dialog wires the sender, or the dialog throws.
    expect(() => controller.focusRow('a')).toThrow(/sendFocus/)
  })

  it('takes the body off every frame about the row, before the gate', () => {
    const { controller } = makeFocusedController()
    controller.focusRow('a')

    const updated: TableRow = { rowKey: 'a', slots: { v: 3 } }
    controller.ingestDelta({ kind: 'row_updated', rowKey: 'a', row: updated })
    expect(controller.focusedRow.get()).toEqual(updated)

    // A move waits for Apply on the screen; the dialog reads the moved row at once.
    const moved: TableRow = { rowKey: 'a', slots: { v: 4 } }
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: moved,
      position: 1,
    })
    expect(controller.focusedRow.get()).toEqual(moved)
    expect(controller.rows.get()[0]).toMatchObject({
      rowKey: 'a',
      row: updated,
      pending: 'move',
    })

    // So does a removal that carries the body: the row left the window, not the world.
    const left: TableRow = { rowKey: 'a', slots: { v: 5 } }
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'left_set',
      row: left,
    })
    expect(controller.focusedRow.get()).toEqual(left)
    expect(controller.rows.get()[0]).toMatchObject({
      rowKey: 'a',
      row: updated,
      pending: 'remove',
    })
  })

  it('reads undefined off a removal without a body: the row is gone', () => {
    const { controller } = makeFocusedController()
    controller.focusRow('a')

    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })

    expect(controller.focusedRow.get()).toBeUndefined()
  })

  it('gives a second departure of a row whose placeholder stands no wait', () => {
    const { controller } = makeFocusedController()
    controller.focusRow('a')
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'moved_out',
      row: { rowKey: 'a', slots: { v: 6 } },
    })
    controller.apply()
    expect(controller.rows.get()[0]).toMatchObject({
      rowKey: 'a',
      placeholder: true,
    })

    // The server follows the row past the window and says it left again; the placeholder is
    // all the screen has under that key, and a badge whose Apply changes nothing is a defect.
    const again: TableRow = { rowKey: 'a', slots: { v: 7 } }
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'left_set',
      row: again,
    })

    expect(controller.focusedRow.get()).toEqual(again)
    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get()[0]).toMatchObject({
      rowKey: 'a',
      placeholder: true,
      pending: null,
    })
  })

  it('says the focus again with every window, taking the body from it when the row came', () => {
    const { controller, focus } = makeFocusedController()
    controller.focusRow('b')

    const fresh: TableRow = { rowKey: 'b', slots: { v: 22 } }
    controller.ingestWindow([fresh], 1, true, null, null, 10)
    expect(focus).toEqual(['b', 'b'])
    expect(controller.focusedRow.get()).toEqual(fresh)

    // A window without the row keeps the body as it was: the server answers the re-sent focus
    // with the row's current body, or with nothing when the row is gone.
    controller.ingestWindow(
      [{ rowKey: 'c', slots: {} }],
      1,
      true,
      null,
      null,
      10,
    )
    expect(focus).toEqual(['b', 'b', 'b'])
    expect(controller.focusedRow.get()).toEqual(fresh)
  })

  it('takes an appended row as the body when it is the one in focus', () => {
    const { controller } = makeFocusedController()
    controller.focusRow('b')
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'b',
      reason: 'moved_out',
      row: { rowKey: 'b', slots: { v: 2 } },
    })
    controller.apply()

    const back: TableRow = { rowKey: 'b', slots: { v: 23 } }
    controller.ingestAppend(back, 2, true)

    expect(controller.focusedRow.get()).toEqual(back)
  })

  it('releases the focus with an empty key, once, and reads nothing after', () => {
    const { controller, focus } = makeFocusedController()
    controller.focusRow('a')

    controller.releaseFocus()
    expect(focus).toEqual(['a', ''])
    expect(controller.focusedRow.get()).toBeUndefined()

    // Nothing to let go of twice, and a frame about the row moves nothing any more.
    controller.releaseFocus()
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { v: 8 } },
    })
    expect(focus).toEqual(['a', ''])
    expect(controller.focusedRow.get()).toBeUndefined()
  })

  it('a second focus on the table replaces the first', () => {
    const { controller, focus, rowB } = makeFocusedController()
    controller.focusRow('a')

    expect(controller.focusRow('b')).toEqual(rowB)
    expect(focus).toEqual(['a', 'b'])
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { v: 8 } },
    })
    expect(controller.focusedRow.get()).toEqual(rowB)
  })
})
