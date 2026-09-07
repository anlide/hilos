import { describe, expect, it } from 'vitest'
import {
  type TableAnchor,
  type TableViewportDescriptor,
} from '../../src/connection/HilosConnection.js'
import { type TableRow } from '../../src/state/TableRowsStore.js'
import { TableViewportController } from '../../src/table/TableViewportController.js'
import { type HilosTableFrame } from '../../src/table/tableFrame.js'

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
  bulkActions: [{ key: 'delete', label: 'Delete', danger: true }],
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
      { key: 'delete', label: 'Delete', danger: true },
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
    expect(sent[0]).toEqual({
      filter: { kind: 'full' },
      sort: null,
      limit: 10,
      anchor: null,
      anchorDirection: 'after',
      pageIndex: null,
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
    })
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
})
