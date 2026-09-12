import { describe, expect, it } from 'vitest'
import { type TableViewportDescriptor } from '../../src/connection/HilosConnection.js'
import { type TableRow } from '../../src/state/TableRowsStore.js'
import { TableViewportController } from '../../src/table/TableViewportController.js'
import { type HilosTableBulkReport } from '../../src/table/tableBulk.js'
import { type HilosTableFrame } from '../../src/table/tableFrame.js'

/** A table whose page declared one bulk operation — the one sign that it runs them. */
const bulkFrame: HilosTableFrame = {
  title: 'Backups',
  columns: [{ key: 'createdAt', label: 'Date', sortable: true }],
  bulkActions: [{ key: 'delete', label: 'Delete', danger: true }],
}

function row(rowKey: string): TableRow {
  return { rowKey, slots: {} }
}

function report(
  progressKey: string,
  overrides: Partial<HilosTableBulkReport> = {},
): HilosTableBulkReport {
  return {
    progressKey,
    touched: 39,
    untouched: [{ rowKey: 'r7', reason: 'It was already gone' }],
    untouchedOmitted: 0,
    ...overrides,
  }
}

function makeController() {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<TableRow>({
    resolve: (shown) => shown,
    sendViewport: (descriptor) => sent.push(descriptor),
    frame: bulkFrame,
  })

  const open = (rowKeys: readonly string[] = []): void =>
    controller.ingestWindow(
      rowKeys.map(row),
      rowKeys.length,
      true,
      null,
      null,
      10,
    )

  return { controller, sent, open }
}

describe('bulk report', () => {
  it('has nothing to show before a run has ended', () => {
    const { controller, open } = makeController()
    open(['a', 'b'])

    expect(controller.bulk.report.get()).toBeNull()
  })

  it('shows the outcome of the run that ended, names and all', () => {
    const { controller, open } = makeController()
    open(['a', 'b'])

    controller.ingestBulkReport(report('bulk-1', { untouchedOmitted: 4 }))

    const shown = controller.bulk.report.get()
    expect(shown?.progressKey).toBe('bulk-1')
    expect(shown?.touched).toBe(39)
    expect(shown?.untouched).toEqual([
      { rowKey: 'r7', reason: 'It was already gone' },
    ])
    expect(shown?.untouchedOmitted).toBe(4)
  })

  it('outlives the window the run was started from', () => {
    const { controller, open } = makeController()
    open(['a', 'b'])
    controller.ingestBulkReport(report('bulk-1'))

    controller.setSearch('anything')
    controller.setPage(2)
    open(['c', 'd'])

    expect(controller.bulk.report.get()?.progressKey).toBe('bulk-1')
  })

  it('stands until the next run on this table begins', () => {
    const { controller, open } = makeController()
    open(['a', 'b'])
    controller.ingestBulkReport(report('bulk-1'))

    controller.ingestProgress({
      scope: 'bulk',
      progressKey: 'bulk-2',
      current: 1,
      total: 12,
    })

    expect(controller.bulk.report.get()).toBeNull()
  })

  it('survives the bar of its own run coming down', () => {
    const { controller, open } = makeController()
    open(['a', 'b'])
    controller.ingestProgress({
      scope: 'bulk',
      progressKey: 'bulk-1',
      current: 40,
      total: 40,
    })

    // The server sends the report first and takes the bar down after it, so that the
    // panel is never left with neither. Both steps have to leave the report standing.
    controller.ingestBulkReport(report('bulk-1'))
    controller.ingestProgress({
      scope: 'bulk',
      progressKey: 'bulk-1',
      current: 40,
      total: 40,
      ended: true,
    })

    expect(controller.bulk.report.get()?.progressKey).toBe('bulk-1')
    expect(controller.progress.bulk.get()).toBeNull()
  })

  it('is not disturbed by work running over the table or over a row', () => {
    const { controller, open } = makeController()
    open(['a', 'b'])
    controller.ingestBulkReport(report('bulk-1'))

    controller.ingestProgress({
      scope: 'table',
      progressKey: 'nightly',
      current: 3,
      total: 9,
    })
    controller.ingestProgress({
      scope: 'row',
      progressKey: 'restore-a',
      rowKey: 'a',
      current: 1,
      total: 4,
    })

    expect(controller.bulk.report.get()?.progressKey).toBe('bulk-1')
  })
})
