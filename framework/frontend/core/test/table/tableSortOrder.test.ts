import { describe, expect, it } from 'vitest'
import { type HilosTableColumn } from '../../src/table/hilosTableColumn.js'
import { type TableSortOrder } from '../../src/table/TableViewportController.js'
import {
  HILOS_TABLE_MIRROR_ORDER_SUFFIX,
  HILOS_TABLE_OPENING_ORDER_KEY,
  hilosTableOfferedOrders,
  hilosTableOrderLabel,
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

const emptyStale = new Set<string>()

describe('hilosTableOrderViews', () => {
  it('offers nothing at all to a table that declared no composite order', () => {
    expect(
      hilosTableOrderViews([], byDate, byDate, columns, emptyStale),
    ).toEqual([])
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

  it('marks nothing while the window runs in an order that came from a header click', () => {
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
