import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  type TableAnchor,
  type TableViewportDescriptor,
} from '../../src/connection/HilosConnection.js'
import { type TableRow } from '../../src/state/TableRowsStore.js'
import { type HilosTableProgressFrame } from '../../src/table/tableProgress.js'
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

  it('reports the orders the table declares in the sequence a menu offers them', () => {
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

    expect(controller.orders).toEqual(declaredOrders)
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

    // A header click leaves an order the menu does not offer: nothing is active, and
    // the button still says what the rows are sorted by.
    controller.setSort('channel')
    expect(activeKeys()).toEqual([])
    expect(controller.frame.orderLabel.get()).toBe('Kind ↑')

    controller.resetOrder()
    expect(activeKeys()).toEqual([HILOS_TABLE_OPENING_ORDER_KEY])
    expect(controller.frame.orderLabel.get()).toBe('Date ↓')
  })

  it('a table declaring no orders reports an empty list rather than nothing', () => {
    const { controller } = makeController()

    expect(controller.orders).toEqual([])
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
        highlighted: false,
        selected: false,
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

  it('applies a live row update at once, with nothing left pending', () => {
    const { controller, open } = makeController()
    open([{ rowKey: 'a', slots: { name: 'old' } }], 1, true, null, null)
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
      live: true,
    })

    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'new' },
    })
  })

  it('takes a live removal out of the window instead of leaving a placeholder', () => {
    const { controller, open } = makeController()
    open(
      [
        { rowKey: 'progress', slots: { name: 'running' } },
        { rowKey: 'a', slots: { name: 'stored' } },
      ],
      2,
      true,
      null,
      null,
    )
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'progress',
      reason: 'deleted',
      live: true,
    })

    // A status row that ended has nothing to hold a place for: it goes, and no
    // Apply badge is left behind for a change the user never made.
    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get().map((row) => row.rowKey)).toEqual(['a'])
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
      highlighted: false,
      selected: false,
      staleSources: [],
    })
    expect(rows[1]?.placeholder).toBe(false)
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
      reason: 'gone',
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
      reason: 'deleted',
      own: true,
    })

    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get()[0]).toEqual({
      rowKey: 'a',
      row: null,
      placeholder: true,
      pending: null,
      highlighted: false,
      selected: false,
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
