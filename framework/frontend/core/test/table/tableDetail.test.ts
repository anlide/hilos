import { describe, expect, it } from 'vitest'
import { type TableViewportDescriptor } from '../../src/connection/HilosConnection.js'
import { type TableRow } from '../../src/state/TableRowsStore.js'
import { TableViewportController } from '../../src/table/TableViewportController.js'
import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumn,
} from '../../src/table/hilosTableColumn.js'
import { hilosTableDetailFields } from '../../src/table/tableDetail.js'

const columns: readonly HilosTableColumn[] = [
  { key: 'createdAt', label: 'Date' },
  { key: 'notificationTitle', label: 'Notification', detail: true },
  { key: 'state', label: 'State' },
  { key: 'lastError', label: 'Error', detail: true },
]

function row(rowKey: string): TableRow {
  return { rowKey, slots: {} }
}

function makeController(pageSize = 10) {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<TableRow>({
    resolve: (shown) => shown,
    sendViewport: (descriptor) => sent.push(descriptor),
  })

  const open = (
    rowKeys: readonly string[] = [],
    totalCount = rowKeys.length,
  ): void =>
    controller.ingestWindow(
      rowKeys.map(row),
      totalCount,
      true,
      null,
      null,
      pageSize,
    )

  /** Which rows say they are open, in window order. */
  const expandedKeys = (): readonly string[] =>
    controller.rows
      .get()
      .filter((shown) => shown.expanded)
      .map((shown) => shown.rowKey)

  return { controller, sent, open, expandedKeys }
}

describe('hilosTableDetailFields', () => {
  it('takes the marked columns and nothing else', () => {
    expect(hilosTableDetailFields(columns).map((column) => column.key)).toEqual(
      ['notificationTitle', 'lastError'],
    )
  })

  it('keeps the declaration order rather than sorting the fields', () => {
    const fields = hilosTableDetailFields([
      { key: 'zulu', label: 'Zulu', detail: true },
      { key: 'alpha', label: 'Alpha', detail: true },
    ])

    expect(fields.map((column) => column.key)).toEqual(['zulu', 'alpha'])
  })

  it('carries the label of the column, which is the label of the field', () => {
    expect(
      hilosTableDetailFields(columns).map((column) => column.label),
    ).toEqual(['Notification', 'Error'])
  })

  it('gives a table that marked no column an empty list, not a control', () => {
    expect(
      hilosTableDetailFields([
        { key: 'createdAt', label: 'Date' },
        { key: 'state', label: 'State' },
      ]),
    ).toEqual([])
  })

  it('skips the actions column however it was marked: it has no value of its own', () => {
    const fields = hilosTableDetailFields([
      { key: 'createdAt', label: 'Date' },
      { key: HILOS_TABLE_ACTIONS_KEY, label: '', detail: true },
    ])

    expect(fields).toEqual([])
  })
})

describe('TableViewportController expansion', () => {
  it('opens one row, and the row itself says so', () => {
    const { controller, open, expandedKeys } = makeController()
    open(['a', 'b', 'c'])

    controller.expandRow('b', true)

    expect(expandedKeys()).toEqual(['b'])

    controller.expandRow('b', false)

    expect(expandedKeys()).toEqual([])
  })

  it('takes the state it is given rather than toggling it', () => {
    const { controller, open, expandedKeys } = makeController()
    open(['a'])

    controller.expandRow('a', true)
    controller.expandRow('a', true)

    expect(expandedKeys()).toEqual(['a'])
  })

  it('opens as many rows at once as the reader asks for', () => {
    const { controller, open, expandedKeys } = makeController()
    open(['a', 'b', 'c'])

    controller.expandRow('a', true)
    controller.expandRow('c', true)

    expect(expandedKeys()).toEqual(['a', 'c'])
  })

  it('refuses a key that is no row of this window', () => {
    const { controller, open, expandedKeys } = makeController()
    open(['a', 'b'])

    controller.expandRow('nobody', true)

    expect(expandedKeys()).toEqual([])
  })

  it('refuses a placeholder: there are no values left under it', () => {
    const { controller, open, expandedKeys } = makeController()
    open(['a', 'b'])
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()

    controller.expandRow('a', true)

    expect(controller.rows.get()[0]?.placeholder).toBe(true)
    expect(expandedKeys()).toEqual([])
  })

  it('closes the panel of a row an applied removal turned into a placeholder', () => {
    const { controller, open, expandedKeys } = makeController()
    open(['a', 'b'])
    controller.expandRow('a', true)

    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()

    expect(expandedKeys()).toEqual([])
  })

  it('leaves the panel open while a pending change waits behind the gate', () => {
    const { controller, open, expandedKeys } = makeController()
    open(['a', 'b'])
    controller.expandRow('a', true)

    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: row('a'),
      position: 1,
    })

    expect(controller.pendingCount.get()).toBe(1)
    expect(expandedKeys()).toEqual(['a'])
  })

  it('keeps the panel open while the row takes a new value', () => {
    const { controller, open, expandedKeys } = makeController()
    open(['a', 'b'])
    controller.expandRow('a', true)

    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: row('a'),
    })

    expect(expandedKeys()).toEqual(['a'])
  })

  it('drops the panel of a row an own insert pushed past the edge', () => {
    const { controller, open, expandedKeys } = makeController(2)
    open(['a', 'b'])
    controller.expandRow('b', true)

    controller.ingestOwnCreate(row('c'), 0, 3, true, null, null)

    expect(controller.rows.get().map((shown) => shown.rowKey)).toEqual([
      'c',
      'a',
    ])
    expect(expandedKeys()).toEqual([])
  })

  it('closes every panel when the window changes', () => {
    const { controller, open, expandedKeys } = makeController()
    open(['a', 'b'])
    controller.expandRow('a', true)
    controller.expandRow('b', true)

    controller.setSearch('failed')

    expect(expandedKeys()).toEqual([])
  })

  it('narrows the open rows to the ones a refreshed window brought back', () => {
    const { controller, open, expandedKeys } = makeController()
    open(['a', 'b'])
    controller.expandRow('a', true)
    controller.expandRow('b', true)

    open(['b', 'c'])

    expect(expandedKeys()).toEqual(['b'])
  })
})

describe('TableViewportController expandable rows', () => {
  /** A row carrying how many corrections it has to show in its panel. */
  function counted(rowKey: string, corrections: number): TableRow {
    return { rowKey, slots: { names: { corrections } } }
  }

  function makeExpandable() {
    const controller = new TableViewportController<TableRow>({
      resolve: (shown) => shown,
      sendViewport: () => {},
      expandable: (shown) => {
        const slot = shown.slots['names'] as { corrections: number }

        return slot.corrections > 0
      },
    })
    const open = (rows: readonly TableRow[]): void =>
      controller.ingestWindow(rows, rows.length, true, null, null, 10)
    const shown = (rowKey: string) =>
      controller.rows.get().find((view) => view.rowKey === rowKey)

    return { controller, open, shown }
  }

  it('says which rows have a panel to open, and every live row has one without the option', () => {
    const { open, shown } = makeExpandable()
    open([counted('a', 2), counted('b', 0)])

    expect(shown('a')?.expandable).toBe(true)
    expect(shown('b')?.expandable).toBe(false)

    const { controller, open: openPlain } = makeController()
    openPlain(['a'])

    expect(controller.rows.get()[0]?.expandable).toBe(true)
  })

  it('refuses to open a row the table says has nothing to open', () => {
    const { controller, open, shown } = makeExpandable()
    open([counted('a', 2), counted('b', 0)])

    controller.expandRow('b', true)

    expect(shown('b')?.expanded).toBe(false)
  })

  it('closes an open row that lost what it had to show, and keeps it closed when that returns', () => {
    const { controller, open, shown } = makeExpandable()
    open([counted('a', 2)])
    controller.expandRow('a', true)

    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: counted('a', 0),
    })

    expect(shown('a')?.expanded).toBe(false)
    expect(shown('a')?.expandable).toBe(false)

    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: counted('a', 1),
    })

    expect(shown('a')?.expandable).toBe(true)
    expect(shown('a')?.expanded).toBe(false)
  })

  it('closes a row a refreshed window brought back with nothing to show', () => {
    const { controller, open, shown } = makeExpandable()
    open([counted('a', 2), counted('b', 1)])
    controller.expandRow('a', true)
    controller.expandRow('b', true)

    open([counted('a', 0), counted('b', 1)])
    open([counted('a', 3), counted('b', 1)])

    expect(shown('a')?.expanded).toBe(false)
    expect(shown('b')?.expanded).toBe(true)
  })
})
