import { describe, expect, it } from 'vitest'
import { type ActionHandle } from '../../src/connection/actionLifecycle.js'
import { type TableViewportDescriptor } from '../../src/connection/HilosConnection.js'
import { type TableRow } from '../../src/state/TableRowsStore.js'
import { TableViewportController } from '../../src/table/TableViewportController.js'
import { type HilosTableBulkAccepted } from '../../src/table/tableBulk.js'
import { type HilosTableFrame } from '../../src/table/tableFrame.js'

/**
 * The sender of the declared operation, which these tests never press: what they
 * are about is the state behind the run, not the run itself.
 */
function neverRun(): ActionHandle<HilosTableBulkAccepted> {
  throw new Error('the declaration is only read here')
}

/** A table whose page declared one bulk operation — the one sign that it has marks. */
const bulkFrame: HilosTableFrame = {
  title: 'Backups',
  columns: [{ key: 'createdAt', label: 'Date', sortable: true }],
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

/** The same table with nothing declared for marked rows. */
const plainFrame: HilosTableFrame = {
  title: 'Backups',
  columns: [{ key: 'createdAt', label: 'Date', sortable: true }],
}

function row(rowKey: string): TableRow {
  return { rowKey, slots: {} }
}

function makeController(
  frame: HilosTableFrame = bulkFrame,
  pageSize = 10,
  initialFilter?: Record<string, unknown>,
) {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<TableRow>({
    resolve: (shown) => shown,
    sendViewport: (descriptor) => sent.push(descriptor),
    initialFilter,
    frame,
  })

  // The window's size comes with the window since HIL-642, so a test that wants one hands it
  // over the same way the page's answer does.
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

  /** The keys the panel would send, or null when it would send nothing. */
  const markedKeys = (): readonly string[] | null => {
    const target = controller.selection.target.get()

    return target === null || target.kind !== 'rows' ? null : target.rowKeys
  }

  return { controller, sent, open, markedKeys }
}

describe('TableViewportController selection', () => {
  it('has no marks at all on a table that declared no bulk operations', () => {
    const { controller, open } = makeController(plainFrame)
    open(['a', 'b'])

    controller.selectRow('a', true)
    controller.selectWindow(true)
    controller.selectAllByFilter()
    controller.clearSelection()

    expect(controller.selection.enabled).toBe(false)
    expect(controller.selection.target.get()).toBeNull()
    expect(controller.selection.count.get()).toBe(0)
    expect(controller.selection.header.get()).toBe('none')
    expect(controller.rows.get().map((shown) => shown.selected)).toEqual([
      false,
      false,
    ])
  })

  it('marks one row, and the row itself says so', () => {
    const { controller, open, markedKeys } = makeController()
    open(['a', 'b', 'c'])

    controller.selectRow('b', true)

    expect(controller.rows.get().map((shown) => shown.selected)).toEqual([
      false,
      true,
      false,
    ])
    expect(controller.selection.count.get()).toBe(1)
    expect(controller.selection.header.get()).toBe('some')
    expect(markedKeys()).toEqual(['b'])

    controller.selectRow('b', false)

    expect(controller.selection.count.get()).toBe(0)
    expect(controller.selection.header.get()).toBe('none')
    expect(controller.selection.target.get()).toBeNull()
  })

  it('takes the whole window with the header checkbox, and gives it back', () => {
    const { controller, open, markedKeys } = makeController()
    open(['a', 'b', 'c'])

    controller.selectWindow(true)

    expect(controller.selection.count.get()).toBe(3)
    expect(controller.selection.header.get()).toBe('all')
    // In window order, which is the order the rows are read in.
    expect(markedKeys()).toEqual(['a', 'b', 'c'])

    controller.selectWindow(false)

    expect(controller.selection.count.get()).toBe(0)
    expect(controller.selection.header.get()).toBe('none')
    expect(controller.selection.target.get()).toBeNull()
  })

  it('refuses to mark a placeholder — there is no row there to mark', () => {
    const { controller, open } = makeController()
    open(['a', 'b'])
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()

    controller.selectRow('a', true)

    expect(controller.rows.get()[0]?.placeholder).toBe(true)
    expect(controller.selection.count.get()).toBe(0)
    expect(controller.selection.target.get()).toBeNull()
  })

  it('takes the marks off on every change of window', () => {
    const changes: readonly [
      string,
      (c: TableViewportController<TableRow>) => void,
    ][] = [
      ['a filter', (c) => c.setFilter('kind', 'full')],
      ['a search', (c) => c.setSearch('nightly')],
      ['a sort', (c) => c.setSort('createdAt')],
      ['a page jump', (c) => c.setPage(1)],
    ]

    for (const [what, change] of changes) {
      const { controller, open } = makeController(bulkFrame, 2)
      open(['a', 'b'], 10)
      controller.selectWindow(true)
      expect(controller.selection.count.get(), what).toBe(2)

      change(controller)

      expect(controller.selection.count.get(), what).toBe(0)
      expect(controller.selection.target.get(), what).toBeNull()
      expect(controller.selection.header.get(), what).toBe('none')
    }
  })

  it('leaves a mark alone while a change waits behind the gate', () => {
    const { controller, open, markedKeys } = makeController()
    open(['a', 'b'])
    controller.selectWindow(true)

    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: 'a',
      row: row('a'),
      position: 1,
    })
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'b',
      reason: 'deleted',
    })

    expect(controller.pendingCount.get()).toBe(2)
    expect(controller.selection.count.get()).toBe(2)
    expect(markedKeys()).toEqual(['a', 'b'])
    expect(controller.rows.get().every((shown) => shown.selected)).toBe(true)
  })

  it('drops a row out of the marks when its removal is applied', () => {
    const { controller, open, markedKeys } = makeController()
    open(['a', 'b', 'c'])
    controller.selectWindow(true)

    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'b',
      reason: 'deleted',
    })
    controller.apply()

    expect(controller.rows.get()[1]?.placeholder).toBe(true)
    expect(controller.selection.count.get()).toBe(2)
    expect(markedKeys()).toEqual(['a', 'c'])
    // Two live rows left and both are marked, so the header is full again.
    expect(controller.selection.header.get()).toBe('all')
  })

  it("drops a row out of the marks the moment the author's own removal lands", () => {
    const { controller, open, markedKeys } = makeController()
    open(['a', 'b', 'c'])
    controller.selectWindow(true)

    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'b',
      reason: 'deleted',
      own: true,
    })

    // The author's echo needs no Apply, so the placeholder is there at once - and a
    // placeholder is not a row anybody can act on, so the mark goes with the record.
    expect(controller.rows.get()[1]?.placeholder).toBe(true)
    expect(controller.selection.count.get()).toBe(2)
    expect(markedKeys()).toEqual(['a', 'c'])
  })

  it('drops the row an own insert pushed past the edge', () => {
    const { controller, open, markedKeys } = makeController(bulkFrame, 2)
    open(['a', 'b'])
    controller.selectWindow(true)

    controller.ingestOwnCreate(row('c'), 0, 3, true)

    expect(controller.rows.get().map((shown) => shown.rowKey)).toEqual([
      'c',
      'a',
    ])
    expect(controller.selection.count.get()).toBe(1)
    expect(markedKeys()).toEqual(['a'])
    // The row that just arrived was marked by nobody.
    expect(controller.rows.get()[0]?.selected).toBe(false)
  })

  it('does not bring an old mark back with a key that returns as a fresh row', () => {
    const { controller, open } = makeController(bulkFrame, 2)
    open(['a', 'b'])
    controller.selectRow('b', true)

    controller.ingestOwnCreate(row('b'), 0, 2, true)

    expect(controller.rows.get().map((shown) => shown.rowKey)).toEqual([
      'b',
      'a',
    ])
    expect(controller.rows.get()[0]?.selected).toBe(false)
    expect(controller.selection.count.get()).toBe(0)
  })

  it('chooses the condition rather than a list of keys, and shows the page as marked', () => {
    const { controller, open } = makeController(bulkFrame, 10, { kind: 'full' })
    open(['a', 'b'])
    controller.selectRow('a', true)

    controller.selectAllByFilter()

    expect(controller.selection.target.get()).toEqual({
      kind: 'filter',
      filter: { kind: 'full' },
    })
    expect(controller.rows.get().every((shown) => shown.selected)).toBe(true)
    expect(controller.selection.count.get()).toBe(2)
    expect(controller.selection.header.get()).toBe('all')

    // A box that is already on stays on: the condition covers every row there is.
    controller.selectRow('b', true)

    expect(controller.selection.target.get()).toEqual({
      kind: 'filter',
      filter: { kind: 'full' },
    })
  })

  it('leaves the condition when a box is taken off, keeping the rest of the page', () => {
    const { controller, open, markedKeys } = makeController()
    open(['a', 'b', 'c'])
    controller.selectAllByFilter()

    controller.selectRow('b', false)

    expect(markedKeys()).toEqual(['a', 'c'])
    expect(controller.selection.count.get()).toBe(2)
    expect(controller.selection.header.get()).toBe('some')
    expect(controller.rows.get()[1]?.selected).toBe(false)
  })

  it('clears both kinds of choice with one button', () => {
    const { controller, open } = makeController()
    open(['a', 'b'])

    controller.selectAllByFilter()
    controller.clearSelection()

    expect(controller.selection.target.get()).toBeNull()
    expect(controller.selection.count.get()).toBe(0)
    expect(controller.selection.header.get()).toBe('none')

    controller.selectRow('a', true)
    controller.clearSelection()

    expect(controller.selection.target.get()).toBeNull()
    expect(controller.selection.count.get()).toBe(0)
  })

  it('keeps the marks when a window arrives without a change of window', () => {
    const { controller, open, markedKeys } = makeController()
    open(['a', 'b', 'c'])
    controller.selectWindow(true)

    controller.refresh()
    expect(controller.selection.count.get()).toBe(3)

    // The refreshed window came back one row short: that key is not in it any more.
    open(['a', 'c'])

    expect(controller.selection.count.get()).toBe(2)
    expect(markedKeys()).toEqual(['a', 'c'])
    expect(controller.selection.header.get()).toBe('all')

    // The condition survives that same arrival: it is about the filter, and the
    // filter did not move.
    controller.selectAllByFilter()
    open(['a', 'c'])

    expect(controller.selection.target.get()).toEqual({
      kind: 'filter',
      filter: {},
    })
  })
})
