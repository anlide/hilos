import { describe, expect, it } from 'vitest'
import {
  type TableAnchor,
  type TableViewportDescriptor,
} from '../../src/connection/HilosConnection.js'
import { type ActionHandle } from '../../src/connection/actionLifecycle.js'
import { type TableRow } from '../../src/state/TableRowsStore.js'
import { TableViewportController } from '../../src/table/TableViewportController.js'
import { type HilosTableBulkAccepted } from '../../src/table/tableBulk.js'
import { createSignal } from '../../src/state/signal.js'
import {
  HILOS_TABLE_FACET_OPTION_LIMIT,
  type HilosTableFilterOption,
  type HilosTableFrame,
} from '../../src/table/tableFrame.js'

function makeController(
  frame?: HilosTableFrame,
  initialFilter?: Record<string, unknown>,
  pageSize = 10,
) {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<TableRow>({
    resolve: (row) => row,
    sendViewport: (descriptor) => sent.push(descriptor),
    initialFilter,
    frame,
  })

  // The window's size comes with the window since HIL-642, so a test that wants one hands it
  // over the same way the page's answer does.
  const open = (
    rows: readonly TableRow[] = [],
    totalCount = 0,
    totalExact = true,
    firstAnchor: TableAnchor | null = null,
    lastAnchor: TableAnchor | null = null,
  ): void =>
    controller.ingestWindow(
      rows,
      totalCount,
      totalExact,
      firstAnchor,
      lastAnchor,
      pageSize,
    )

  return { controller, sent, open }
}

/**
 * The sender of the declared operation, which these tests never press: what they
 * are about is the state behind the run, not the run itself.
 */
function neverRun(): ActionHandle<HilosTableBulkAccepted> {
  throw new Error('the declaration is only read here')
}

const backupsFrame: HilosTableFrame = {
  title: 'Backups',
  subtitle: 'Nightly and manual copies',
  search: { placeholder: 'Search backups' },
  filters: [
    {
      kind: 'select',
      key: 'kind',
      label: 'Kind',
      anyLabel: 'Any kind',
      options: () => [
        { value: 'full', label: 'Full' },
        { value: 'partial', label: 'Partial' },
      ],
    },
    {
      kind: 'date_range',
      fromKey: 'createdFrom',
      toKey: 'createdTo',
      label: 'Period',
    },
    { kind: 'toggle', key: 'failedOnly', label: 'Failed only', on: true },
  ],
  mainAction: { label: 'Create backup', press: () => undefined },
  columns: [
    { key: 'createdAt', label: 'Date', sortable: true },
    { key: 'kind', label: 'Kind' },
  ],
  bulkActions: [
    { key: 'delete', label: 'Delete', danger: true, run: neverRun },
  ],
  empty: {
    title: 'Nothing here yet',
    hint: 'Your first backup will show up here',
  },
}

describe('TableViewportController frame declaration', () => {
  it('reads the declaration back unchanged', () => {
    const { controller } = makeController(backupsFrame)

    expect(controller.frame.declaration).toBe(backupsFrame)
  })

  it('gives the unchanging parts of the frame straight off the declaration', () => {
    const { controller } = makeController(backupsFrame)
    const declaration = controller.frame.declaration

    expect(declaration?.title).toBe('Backups')
    expect(declaration?.subtitle).toBe('Nightly and manual copies')
    expect(declaration?.search?.placeholder).toBe('Search backups')
    expect(declaration?.mainAction?.label).toBe('Create backup')
    expect(declaration?.columns.map((column) => column.key)).toEqual([
      'createdAt',
      'kind',
    ])
    expect(declaration?.bulkActions).toEqual([
      { key: 'delete', label: 'Delete', danger: true, run: neverRun },
    ])
    expect(declaration?.empty?.title).toBe('Nothing here yet')
  })

  it('reads a select filter its options fresh, not frozen at declaration time', () => {
    let channels = [{ value: 'email', label: 'Email' }]
    const { controller } = makeController({
      title: 'Deliveries',
      columns: [{ key: 'channel', label: 'Channel' }],
      filters: [
        {
          kind: 'select',
          key: 'channel',
          label: 'Channel',
          options: () => channels,
        },
      ],
    })
    const declared = controller.frame.declaration?.filters?.[0]
    if (declared?.kind !== 'select') {
      throw new Error('the declared filter should be a select')
    }

    expect(declared.options()).toEqual([{ value: 'email', label: 'Email' }])

    channels = [
      { value: 'email', label: 'Email' },
      { value: 'sms', label: 'SMS' },
    ]

    expect(declared.options().map((option) => option.value)).toEqual([
      'email',
      'sms',
    ])
  })

  it('has a frame state even when the page declared no frame', () => {
    const { controller } = makeController()

    expect(controller.frame.declaration).toBeNull()
    expect(controller.frame.filters.get()).toEqual([])
    expect(controller.frame.activeFilterCount.get()).toBe(0)
  })

  it('gives the same frame state object on every read', () => {
    const { controller } = makeController(backupsFrame)

    expect(controller.frame).toBe(controller.frame)
  })
})

describe('TableViewportController frame filters', () => {
  it('shows a filter with a value as active and one without as not', () => {
    const { controller } = makeController(backupsFrame)

    expect(controller.frame.filters.get().map((view) => view.active)).toEqual([
      false,
      false,
      false,
    ])

    controller.setFilter('kind', 'full')

    const views = controller.frame.filters.get()
    expect(views[0]?.active).toBe(true)
    expect(views[0]?.value).toBe('full')
    expect(views[1]?.active).toBe(false)
    expect(controller.frame.activeFilterCount.get()).toBe(1)
  })

  it('counts a date range active on either bound alone', () => {
    const { controller } = makeController(backupsFrame)
    controller.setFilter('createdTo', '2026-09-01')

    const range = controller.frame.filters.get()[1]
    expect(range?.active).toBe(true)
    expect(range?.value).toEqual({ from: undefined, to: '2026-09-01' })
    expect(controller.frame.activeFilterCount.get()).toBe(1)
  })

  it('counts neither the search nor a filter-map key nobody declared', () => {
    const { controller } = makeController(backupsFrame, { tenant: 'acme' })
    controller.setSearch('nightly')

    expect(controller.frame.activeFilterCount.get()).toBe(0)

    controller.setFilter('failedOnly', true)

    expect(controller.frame.activeFilterCount.get()).toBe(1)
  })

  it('counts a route preset only when a control is declared for its key', () => {
    const { controller } = makeController(backupsFrame, { kind: 'full' })

    expect(controller.frame.activeFilterCount.get()).toBe(1)
  })

  it('sends one window when a filter value changes', () => {
    const { controller, sent, open } = makeController(backupsFrame)
    open()
    controller.setFilter('kind', 'full')

    expect(sent).toHaveLength(1)
    // A declared frame sends the fields its columns draw with every window (HIL-880).
    expect(sent[0]).toEqual({
      filter: { kind: 'full' },
      sort: null,
      limit: 10,
      anchor: null,
      anchorDirection: 'after',
      pageIndex: null,
      rendered: ['createdAt', 'kind'],
    })
  })

  it('sets both bounds of a date range in ONE window change', () => {
    const { controller, sent } = makeController(backupsFrame)
    controller.setFilters({
      createdFrom: '2026-08-01',
      createdTo: '2026-09-01',
    })

    expect(sent).toHaveLength(1)
    expect(sent[0]?.filter).toEqual({
      createdFrom: '2026-08-01',
      createdTo: '2026-09-01',
    })
  })

  it('clears the keys given an empty value and leaves the rest alone', () => {
    const { controller, sent } = makeController(backupsFrame)
    controller.setFilters({
      createdFrom: '2026-08-01',
      createdTo: '2026-09-01',
    })
    controller.setFilter('kind', 'full')
    controller.setFilters({ createdFrom: null, createdTo: '' })

    expect(sent.at(-1)?.filter).toEqual({ kind: 'full' })
  })

  it('returns a window change to the first page and drops the anchor', () => {
    const { controller, sent, open } = makeController(backupsFrame)
    open([], 50, true, null, null)
    controller.setPage(2)
    controller.setFilters({ kind: 'full' })

    expect(controller.page.get()).toBe(0)
    expect(sent.at(-1)?.pageIndex).toBeNull()
    expect(sent.at(-1)?.anchor).toBeNull()
  })

  it('resets the filters back to the initial ones, not to an empty map', () => {
    const { controller, sent } = makeController(backupsFrame, {
      channel: 'email',
    })
    controller.setFilter('kind', 'full')
    controller.setSearch('nightly')
    controller.resetFilters()

    expect(sent).toHaveLength(3)
    expect(sent.at(-1)?.filter).toEqual({ channel: 'email' })
    expect(controller.search.get()).toBe('')
    expect(controller.frame.activeFilterCount.get()).toBe(0)
  })
})

function rows(count: number, from = 0): TableRow[] {
  return Array.from({ length: count }, (_, index) => ({
    rowKey: `row-${from + index}`,
    slots: {},
  }))
}

describe('TableViewportController frame facets', () => {
  /**
   * A controller that asks for counts, its frame opening with a channel dropdown
   * whose options the test supplies ahead of the backups filters.
   *
   * @param options The channel dropdown's options, read fresh like a page's own.
   * @return The controller, the declarations it sent, the windows it sent, and a window opener.
   */
  function makeCounted(options: () => readonly HilosTableFilterOption[]) {
    const declared: Array<Readonly<Record<string, readonly unknown[]>>> = []
    const sent: TableViewportDescriptor[] = []
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: (descriptor) => sent.push(descriptor),
      sendFacets: (facets) => declared.push(facets),
      frame: {
        ...backupsFrame,
        filters: [
          { kind: 'select', key: 'channel', label: 'Channel', options },
          ...(backupsFrame.filters ?? []),
        ],
      },
    })
    const open = (): void =>
      controller.ingestWindow([], 0, true, null, null, 10)

    return { controller, declared, sent, open }
  }

  const twoChannels = (): readonly HilosTableFilterOption[] => [
    { value: 'email', label: 'Email' },
    { value: 'sms', label: 'SMS' },
  ]

  it('declares the options of its dropdown filters once the first window lands, and once', () => {
    const { declared, open } = makeCounted(twoChannels)
    expect(declared).toEqual([])

    open()
    open()

    expect(declared).toEqual([
      { channel: ['email', 'sms'], kind: ['full', 'partial'] },
    ])
  })
  it('leaves a dropdown offering more than twenty options out of the declaration', () => {
    const many = Array.from(
      { length: HILOS_TABLE_FACET_OPTION_LIMIT + 1 },
      (_, index) => ({ value: `channel-${index}`, label: `Channel ${index}` }),
    )
    const { declared, open } = makeCounted(() => many)

    open()

    expect(declared).toEqual([{ kind: ['full', 'partial'] }])
  })
  it('declares nothing while no dropdown offers anything to count', () => {
    const declared: Array<Readonly<Record<string, readonly unknown[]>>> = []
    const controller = new TableViewportController<TableRow>({
      resolve: (row) => row,
      sendViewport: () => undefined,
      sendFacets: (facets) => declared.push(facets),
      frame: { ...backupsFrame, filters: [] },
    })

    controller.ingestWindow([], 0, true, null, null, 10)

    expect(declared).toEqual([])
  })
  it('declares again when the options of a dropdown change after the first window', () => {
    const channels = createSignal<readonly HilosTableFilterOption[]>([])
    const { declared, open } = makeCounted(() => channels.get())
    open()

    channels.set(twoChannels())

    expect(declared).toEqual([
      { channel: [], kind: ['full', 'partial'] },
      { channel: ['email', 'sms'], kind: ['full', 'partial'] },
    ])
  })

  it('sends no frame for a change before the first window, which carries it instead', () => {
    const channels = createSignal<readonly HilosTableFilterOption[]>([])
    const { declared, open } = makeCounted(() => channels.get())

    channels.set(twoChannels())
    expect(declared).toEqual([])
    open()

    expect(declared).toEqual([
      { channel: ['email', 'sms'], kind: ['full', 'partial'] },
    ])
  })
  it('shows no numbers until counts arrive, and never beside a range or a toggle', () => {
    const { controller } = makeCounted(twoChannels)

    expect(controller.frame.filters.get().map((view) => view.facets)).toEqual([
      null,
      null,
      null,
      null,
    ])
  })

  it('lays each frame of counts over the ones held, filter by filter', () => {
    const { controller } = makeCounted(twoChannels)

    controller.ingestFacetCounts({
      channel: {
        any: { count: 500, exact: false },
        options: {
          email: { count: 412, exact: true },
          sms: { count: 88, exact: true },
        },
      },
      kind: {
        any: { count: 30, exact: true },
        options: { full: { count: 20, exact: true } },
      },
    })
    controller.ingestFacetCounts({
      kind: {
        any: { count: 12, exact: true },
        options: { full: { count: 0, exact: true } },
      },
    })

    const [channel, kind, range, toggle] = controller.frame.filters.get()
    expect(channel?.facets?.any).toEqual({ count: 500, exact: false })
    expect(channel?.facets?.options.get('email')).toEqual({
      count: 412,
      exact: true,
    })
    expect(kind?.facets?.any).toEqual({ count: 12, exact: true })
    expect(kind?.facets?.options.get('full')).toEqual({ count: 0, exact: true })
    expect(range?.facets).toBeNull()
    expect(toggle?.facets).toBeNull()
  })

  it('reports its options with the window it holds, and never inside a window it sends', () => {
    const { controller, sent, open } = makeCounted(twoChannels)
    expect(controller.descriptor()).toBeNull()

    open()
    controller.setFilter('channel', 'email')

    expect(controller.descriptor()?.facets).toEqual({
      channel: ['email', 'sms'],
      kind: ['full', 'partial'],
    })
    expect(sent.at(-1)?.facets).toBeUndefined()
  })

  it('reports no options with its window when it asks for no counts', () => {
    const { controller, open } = makeController(backupsFrame)
    open()

    expect(controller.descriptor()?.facets).toBeUndefined()
  })
})

describe('TableViewportController frame footer', () => {
  it('numbers the shown rows from one, and says a first page has nothing before it', () => {
    const { controller, open } = makeController(backupsFrame)
    open(rows(10), 128, true, null, null)

    expect(controller.frame.footer.get()).toEqual({
      firstRow: 1,
      lastRow: 10,
      totalCount: 128,
      totalExact: true,
      page: 0,
      pageCount: 13,
      hasPreviousPage: false,
      hasNextPage: true,
      paginated: true,
    })
  })

  it('marks a set fitting on one page as not paginated', () => {
    const { controller, open } = makeController(backupsFrame)
    open(rows(10), 10, true, null, null)

    const footer = controller.frame.footer.get()
    expect(footer.pageCount).toBe(1)
    expect(footer.paginated).toBe(false)
  })

  it('moves the range with the page and opens the way back', () => {
    const { controller, open } = makeController(backupsFrame)
    open(rows(10), 128, true, null, null)
    controller.setPage(2)
    open(rows(10, 20), 128, true, null, null)

    const footer = controller.frame.footer.get()
    expect([footer.firstRow, footer.lastRow]).toEqual([21, 30])
    expect(footer.page).toBe(2)
    expect(footer.hasPreviousPage).toBe(true)
  })

  it('gives a range of zeroes on an empty window', () => {
    const { controller, open } = makeController(backupsFrame)
    open([], 0, true, null, null)

    const footer = controller.frame.footer.get()
    expect([footer.firstRow, footer.lastRow, footer.totalCount]).toEqual([
      0, 0, 0,
    ])
  })

  it('passes an inexact count on with no page count behind it', () => {
    const { controller, open } = makeController(backupsFrame)
    open(rows(10), 500, false, null, null)

    const footer = controller.frame.footer.get()
    expect(footer.totalExact).toBe(false)
    expect(footer.pageCount).toBeNull()
    expect(footer.hasNextPage).toBe(true)
    expect(footer.paginated).toBe(true)
  })
})

describe('TableViewportController frame body', () => {
  it('walks loading -> empty -> empty_filtered -> rows', () => {
    const { controller, open } = makeController(backupsFrame)

    expect(controller.frame.body.get()).toBe('loading')

    open([], 0, true, null, null)

    expect(controller.frame.body.get()).toBe('empty')

    controller.setFilter('kind', 'full')
    open([], 0, true, null, null)

    expect(controller.frame.body.get()).toBe('empty_filtered')

    open(rows(3), 3, true, null, null)

    expect(controller.frame.body.get()).toBe('rows')
  })

  it('calls an empty set under a search filtered, not empty', () => {
    const { controller, open } = makeController(backupsFrame)
    open([], 0, true, null, null)
    controller.setSearch('nightly')
    open([], 0, true, null, null)

    expect(controller.frame.body.get()).toBe('empty_filtered')
  })

  it('calls an empty window over a set that is not empty a page, not a set', () => {
    const { controller, open } = makeController(backupsFrame)

    // The rows this window was asked for moved out from under its address. Saying "no
    // backups yet" here would tell the reader the set is gone beside a footer counting it.
    open([], 21, true, null, null)

    expect(controller.frame.body.get()).toBe('empty_page')
  })

  it('still calls an empty filtered set filtered when no window of it could hold rows', () => {
    const { controller, open } = makeController(backupsFrame)
    controller.setFilter('kind', 'full')

    open([], 0, true, null, null)

    expect(controller.frame.body.get()).toBe('empty_filtered')
  })
})
