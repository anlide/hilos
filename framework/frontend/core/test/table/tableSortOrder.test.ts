import { describe, expect, it } from 'vitest'
import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumn,
} from '../../src/table/hilosTableColumn.js'
import { type TableSortOrder } from '../../src/table/TableViewportController.js'
import {
  HILOS_TABLE_MIRROR_ORDER_SUFFIX,
  HILOS_TABLE_OPENING_ORDER_KEY,
  hilosTableColumnOrders,
  hilosTableOfferedOrders,
  hilosTableOrderLabel,
  hilosTableOrderMenuNarrowOnly,
  hilosTableOrderPosition,
  hilosTableOrderViews,
  hilosTableSortPositionLabel,
  type HilosTableSortOrder,
  TABLE_ORDER_COPY,
} from '../../src/table/tableSortOrder.js'

const columns: readonly HilosTableColumn[] = [
  { key: 'channel', label: 'Kind', sortable: true },
  { key: 'createdAt', label: 'Date', sortable: true },
]

const byDate: TableSortOrder = [{ field: 'createdAt', direction: 'desc' }]

const byChannel: TableSortOrder = [
  { field: 'channel', direction: 'asc' },
  { field: 'createdAt', direction: 'desc' },
]

const declared: readonly HilosTableSortOrder[] = [
  { key: 'by_channel', components: byChannel },
  {
    key: 'by_state',
    components: [
      { field: 'state', direction: 'asc' },
      { field: 'createdAt', direction: 'desc' },
    ],
  },
]

describe('hilosTableOrderLabel', () => {
  it('words one column by its header and its direction', () => {
    expect(hilosTableOrderLabel(byDate, columns)).toBe('Date ↓')
  })

  it('words a composite order component by component, in the sequence it applies', () => {
    expect(hilosTableOrderLabel(byChannel, columns)).toBe('Kind ↑, then Date ↓')
  })

  it('names a component by its field when the page declared no column for it', () => {
    // Half an order would name an order nobody can get, so the field key stands in
    // for the label rather than the component being dropped.
    expect(hilosTableOrderLabel(declared[1]?.components, columns)).toBe(
      'state ↑, then Date ↓',
    )
  })

  it('words the absence of an order as the default one', () => {
    expect(hilosTableOrderLabel(undefined, columns)).toBe(
      TABLE_ORDER_COPY.defaultOrder,
    )
  })
})

const byChannelMirror: TableSortOrder = [
  { field: 'channel', direction: 'desc' },
  { field: 'createdAt', direction: 'asc' },
]

describe('hilosTableOfferedOrders', () => {
  it('puts the mirror of every declared order right after it, every direction turned', () => {
    const offered = hilosTableOfferedOrders(declared)

    expect(offered.map(({ key }) => key)).toEqual([
      'by_channel',
      `by_channel${HILOS_TABLE_MIRROR_ORDER_SUFFIX}`,
      'by_state',
      `by_state${HILOS_TABLE_MIRROR_ORDER_SUFFIX}`,
    ])
    expect(offered[0]).toBe(declared[0])
    expect(offered[1]?.key).toBe('by_channel-mirror')
    expect(offered[1]?.components).toEqual(byChannelMirror)
    expect(offered[3]?.components).toEqual([
      { field: 'state', direction: 'desc' },
      { field: 'createdAt', direction: 'asc' },
    ])
  })

  it('does not offer a mirror the table declared itself a second time', () => {
    // The declared mirror stands where the table put it: a menu naming one order
    // twice would be the framework's doing, not the table's.
    const offered = hilosTableOfferedOrders([
      { key: 'by_channel', components: byChannel },
      { key: 'by_channel_back', components: byChannelMirror },
    ])

    expect(offered.map(({ key }) => key)).toEqual([
      'by_channel',
      'by_channel_back',
    ])
  })

  it('offers nothing to a table that declared nothing', () => {
    expect(hilosTableOfferedOrders([])).toEqual([])
  })
})

describe('hilosTableColumnOrders', () => {
  it('offers every sortable column in column order, ascending and then descending', () => {
    const offered = hilosTableColumnOrders(columns)

    expect(offered).toEqual([
      {
        key: 'channel-asc',
        components: [{ field: 'channel', direction: 'asc' }],
      },
      {
        key: 'channel-desc',
        components: [{ field: 'channel', direction: 'desc' }],
      },
      {
        key: 'createdAt-asc',
        components: [{ field: 'createdAt', direction: 'asc' }],
      },
      {
        key: 'createdAt-desc',
        components: [{ field: 'createdAt', direction: 'desc' }],
      },
    ])
  })

  it('offers nothing for a column that is not sortable, the actions column among them', () => {
    const offered = hilosTableColumnOrders([
      { key: 'name', label: 'Name' },
      { key: 'state', label: 'State', sortable: false },
      { key: 'createdAt', label: 'Date', sortable: true },
      { key: HILOS_TABLE_ACTIONS_KEY, label: '', reads: [] },
    ])

    expect(offered.map(({ key }) => key)).toEqual([
      'createdAt-asc',
      'createdAt-desc',
    ])
  })

  it('offers nothing to a table without columns', () => {
    expect(hilosTableColumnOrders([])).toEqual([])
  })
})

const emptyStale = new Set<string>()

/** What the controller offers: the columns in both directions, then the declared orders. */
const offeredWithColumns: readonly HilosTableSortOrder[] = [
  ...hilosTableColumnOrders(columns),
  ...hilosTableOfferedOrders(declared),
]

describe('hilosTableOrderViews', () => {
  it('offers nothing at all to a table with neither a sortable column nor a composite order', () => {
    expect(
      hilosTableOrderViews([], byDate, byDate, columns, emptyStale),
    ).toEqual([])
  })

  it('puts the columns after the way home and leaves out the one the table opened in', () => {
    // The way home already names the opening order; a second item for it would name
    // one order twice.
    const views = hilosTableOrderViews(
      offeredWithColumns,
      byDate,
      byDate,
      columns,
      emptyStale,
    )

    expect(views.map(({ key }) => key)).toEqual([
      HILOS_TABLE_OPENING_ORDER_KEY,
      'channel-asc',
      'channel-desc',
      'createdAt-asc',
      'by_channel',
      'by_channel-mirror',
      'by_state',
      'by_state-mirror',
    ])
    expect(views.find(({ key }) => key === 'createdAt-asc')?.label).toBe(
      'Date ↑',
    )
  })

  it('offers the columns below md alone, and the composite orders on both widths', () => {
    const views = hilosTableOrderViews(
      offeredWithColumns,
      byDate,
      byDate,
      columns,
      emptyStale,
    )

    expect(
      views.filter(({ narrowOnly }) => narrowOnly).map(({ key }) => key),
    ).toEqual(['channel-asc', 'channel-desc', 'createdAt-asc'])
    expect(views[0]?.narrowOnly).toBe(false)
  })

  it('offers the way home below md alone on a table that declared no composite order', () => {
    const views = hilosTableOrderViews(
      hilosTableColumnOrders(columns),
      byDate,
      byDate,
      columns,
      emptyStale,
    )

    expect(views[0]?.key).toBe(HILOS_TABLE_OPENING_ORDER_KEY)
    expect(views.every(({ narrowOnly }) => narrowOnly)).toBe(true)
  })

  it('marks the item of a column while the window runs in the order a header click gave', () => {
    const clicked: TableSortOrder = [{ field: 'channel', direction: 'desc' }]
    const views = hilosTableOrderViews(
      offeredWithColumns,
      byDate,
      clicked,
      columns,
      emptyStale,
    )

    expect(views.filter(({ active }) => active).map(({ key }) => key)).toEqual([
      'channel-desc',
    ])
  })

  it('marks the item of a column whose source is lagging', () => {
    const sourcedColumns: readonly HilosTableColumn[] = [
      { key: 'channel', label: 'Kind', sortable: true, source: 'channels' },
      { key: 'createdAt', label: 'Date', sortable: true },
    ]
    const views = hilosTableOrderViews(
      hilosTableColumnOrders(sourcedColumns),
      byDate,
      byDate,
      sourcedColumns,
      new Set(['channels']),
    )

    expect(views.filter(({ stale }) => stale).map(({ key }) => key)).toEqual([
      'channel-asc',
      'channel-desc',
    ])
  })

  it('puts the way home first and the declared orders after it, in declaration order', () => {
    const views = hilosTableOrderViews(
      declared,
      byDate,
      byDate,
      columns,
      emptyStale,
    )

    expect(views.map(({ key }) => key)).toEqual([
      HILOS_TABLE_OPENING_ORDER_KEY,
      'by_channel',
      'by_state',
    ])
    expect(views[0]?.label).toBe('Date ↓')
  })

  it('keeps the way home on a table that opened in no order of its own', () => {
    // Without it a reader who left for a composite order would have no way back —
    // and on a narrow screen the menu is the only way to change the order at all.
    const views = hilosTableOrderViews(
      declared,
      undefined,
      byChannel,
      columns,
      emptyStale,
    )

    expect(views[0]?.key).toBe(HILOS_TABLE_OPENING_ORDER_KEY)
    expect(views[0]?.label).toBe(TABLE_ORDER_COPY.defaultOrder)
  })

  it('marks exactly the order the window runs in', () => {
    const views = hilosTableOrderViews(
      declared,
      byDate,
      byChannel,
      columns,
      emptyStale,
    )

    expect(views.filter(({ active }) => active).map(({ key }) => key)).toEqual([
      'by_channel',
    ])
  })

  it('marks the mirror item while the window runs in it, and words it the other way round', () => {
    const views = hilosTableOrderViews(
      hilosTableOfferedOrders(declared),
      byDate,
      byChannelMirror,
      columns,
      emptyStale,
    )

    expect(views.filter(({ active }) => active).map(({ key }) => key)).toEqual([
      'by_channel-mirror',
    ])
    expect(views.find(({ key }) => key === 'by_channel-mirror')?.label).toBe(
      'Kind ↓, then Date ↑',
    )
  })

  it('marks nothing while the window runs in an order the menu does not offer', () => {
    const clicked: TableSortOrder = [{ field: 'channel', direction: 'asc' }]
    const views = hilosTableOrderViews(
      declared,
      byDate,
      clicked,
      columns,
      emptyStale,
    )

    expect(views.some(({ active }) => active)).toBe(false)
  })

  it('marks an order running over a column whose source is lagging', () => {
    const sourcedColumns: readonly HilosTableColumn[] = [
      { key: 'channel', label: 'Kind', sortable: true, source: 'channels' },
      { key: 'createdAt', label: 'Date', sortable: true },
    ]
    const staleSources = new Set(['channels'])
    const views = hilosTableOrderViews(
      declared,
      byDate,
      byChannel,
      sourcedColumns,
      staleSources,
    )

    expect(views.find(({ key }) => key === 'by_channel')?.stale).toBe(true)
    expect(
      views.find(({ key }) => key === HILOS_TABLE_OPENING_ORDER_KEY)?.stale,
    ).toBe(false)
  })

  it('marks nothing when the set of stale sources is empty', () => {
    const sourcedColumns: readonly HilosTableColumn[] = [
      { key: 'channel', label: 'Kind', sortable: true, source: 'channels' },
      { key: 'createdAt', label: 'Date', sortable: true },
    ]
    const views = hilosTableOrderViews(
      declared,
      byDate,
      byChannel,
      sourcedColumns,
      emptyStale,
    )

    expect(views.some(({ stale }) => stale)).toBe(false)
  })

  it('never marks a column with no declared source', () => {
    const views = hilosTableOrderViews(
      declared,
      byDate,
      byDate,
      columns,
      new Set(['channel', 'createdAt', 'state']),
    )

    expect(views.some(({ stale }) => stale)).toBe(false)
  })

  it('marks the way-home item by the same rule when opening order runs over a quiet column', () => {
    const sourcedColumns: readonly HilosTableColumn[] = [
      { key: 'channel', label: 'Kind', sortable: true, source: 'channels' },
      { key: 'createdAt', label: 'Date', sortable: true },
    ]
    const views = hilosTableOrderViews(
      declared,
      byChannel,
      byDate,
      sourcedColumns,
      new Set(['channels']),
    )

    expect(views[0]?.key).toBe(HILOS_TABLE_OPENING_ORDER_KEY)
    expect(views[0]?.stale).toBe(true)
  })
})

describe('hilosTableOrderMenuNarrowOnly', () => {
  it('is false for a menu with no items', () => {
    expect(hilosTableOrderMenuNarrowOnly([])).toBe(false)
  })

  it('is true for a menu of columns alone', () => {
    const views = hilosTableOrderViews(
      hilosTableColumnOrders(columns),
      byDate,
      byDate,
      columns,
      emptyStale,
    )

    expect(hilosTableOrderMenuNarrowOnly(views)).toBe(true)
  })

  it('is false once the table declares a composite order', () => {
    const views = hilosTableOrderViews(
      offeredWithColumns,
      byDate,
      byDate,
      columns,
      emptyStale,
    )

    expect(hilosTableOrderMenuNarrowOnly(views)).toBe(false)
  })
})

describe('hilosTableOrderPosition', () => {
  it('tells a column its place in a composite order, counting from one', () => {
    expect(hilosTableOrderPosition(byChannel, 'channel')).toBe(1)
    expect(hilosTableOrderPosition(byChannel, 'createdAt')).toBe(2)
  })

  it('gives no place to a column the order does not run by', () => {
    expect(hilosTableOrderPosition(byChannel, 'state')).toBeNull()
  })

  it('gives no place under an order of one column, where there is nothing to tell apart', () => {
    expect(hilosTableOrderPosition(byDate, 'createdAt')).toBeNull()
    expect(hilosTableOrderPosition(undefined, 'createdAt')).toBeNull()
  })
})

describe('hilosTableSortPositionLabel', () => {
  it('speaks the place aria-sort has no way to say', () => {
    expect(hilosTableSortPositionLabel(2, 2)).toBe('Sort column 2 of 2')
  })
})
