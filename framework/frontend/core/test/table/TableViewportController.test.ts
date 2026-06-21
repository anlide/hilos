import { describe, expect, it } from 'vitest'
import { type TableViewportDescriptor } from '../../src/connection/HilosConnection.js'
import { type TableRow } from '../../src/state/TableRowsStore.js'
import { TableViewportController } from '../../src/table/TableViewportController.js'

function makeController(pageSize = 10) {
  const sent: TableViewportDescriptor[] = []
  const controller = new TableViewportController<TableRow>({
    resolve: (row) => row,
    sendViewport: (descriptor) => sent.push(descriptor),
    pageSize,
  })

  return { controller, sent }
}

describe('TableViewportController', () => {
  it('start sends the initial descriptor', () => {
    const { controller, sent } = makeController()
    controller.start()
    expect(sent).toEqual([{ filter: {}, sort: null, offset: 0, limit: 10 }])
  })

  it('setSearch sets the search filter, resets to page 0, and resends', () => {
    const { controller, sent } = makeController()
    controller.ingestWindow([], 50)
    controller.setPage(2)
    controller.setSearch('theme')

    expect(controller.search.get()).toBe('theme')
    expect(sent.at(-1)).toEqual({
      filter: { search: 'theme' },
      sort: null,
      offset: 0,
      limit: 10,
    })
  })

  it('an empty search drops the filter', () => {
    const { controller, sent } = makeController()
    controller.setSearch('theme')
    controller.setSearch('   ')
    expect(sent.at(-1)).toEqual({
      filter: {},
      sort: null,
      offset: 0,
      limit: 10,
    })
  })

  it('setSort toggles the direction on the same field', () => {
    const { controller, sent } = makeController()
    controller.setSort('key')
    expect(sent.at(-1)).toMatchObject({
      sort: { field: 'key', direction: 'asc' },
    })
    controller.setSort('key')
    expect(sent.at(-1)).toMatchObject({
      sort: { field: 'key', direction: 'desc' },
    })
  })

  it('setPage sends the offset for the page, clamped to the page count', () => {
    const { controller, sent } = makeController()
    controller.ingestWindow([], 50) // 5 pages of 10
    controller.setPage(3)
    expect(sent.at(-1)).toMatchObject({ offset: 30, limit: 10 })

    controller.setPage(99)
    expect(sent.at(-1)).toMatchObject({ offset: 40 }) // clamped to page 4
  })

  it('ingestWindow sets the resolved rows and the total / page counts', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: {} }], 42)

    expect(controller.rows.get()).toEqual([
      { rowKey: 'a', row: { rowKey: 'a', slots: {} }, placeholder: false },
    ])
    expect(controller.totalCount.get()).toBe(42)
    expect(controller.pageCount.get()).toBe(5)
  })

  it('accumulates a row update as pending without changing the rows', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: { name: 'old' } }], 1)
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })

    expect(controller.pendingCount.get()).toBe(1)
    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'old' },
    })

    controller.apply()
    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'new' },
    })
  })

  it('applies a removal as a placeholder in its slot', () => {
    const { controller } = makeController()
    controller.ingestWindow(
      [
        { rowKey: 'a', slots: {} },
        { rowKey: 'b', slots: {} },
      ],
      2,
    )
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    controller.apply()

    const rows = controller.rows.get()
    expect(rows).toHaveLength(2) // layout not collapsed
    expect(rows[0]).toEqual({ rowKey: 'a', row: null, placeholder: true })
    expect(rows[1]?.placeholder).toBe(false)
  })

  it('records a set change as a pending list-change banner', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: {} }], 1)
    controller.ingestDelta({ kind: 'set_changed', totalCount: 5 })

    expect(controller.listChanged.get()).toBe(true)
    expect(controller.pendingCount.get()).toBe(1)

    controller.apply()
    expect(controller.totalCount.get()).toBe(5)
    expect(controller.listChanged.get()).toBe(false)
  })

  it('ignores a delta for a row outside the window', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: {} }], 1)
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'z',
      row: { rowKey: 'z', slots: {} },
    })

    expect(controller.pendingCount.get()).toBe(0)
  })

  it('discards pending when the window changes', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: {} }], 1)
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })
    expect(controller.pendingCount.get()).toBe(1)

    controller.setSearch('x')
    expect(controller.pendingCount.get()).toBe(0)
  })

  it('applies an own-change echo at once instead of queuing it', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: { name: 'old' } }], 1)
    controller.expectOwnChange('a', Promise.resolve())
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })

    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'new' },
    })
  })

  it('an own-change echo resolves a pending change already queued for the same row', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: { name: 'old' } }], 1)
    // A concurrent change for the same row lands first as pending...
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'other' } },
    })
    expect(controller.pendingCount.get()).toBe(1)
    // ...then this tab's own echo arrives and applies, clearing the pending.
    controller.expectOwnChange('a', Promise.resolve())
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'mine' } },
    })

    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get()[0]?.row).toEqual({
      rowKey: 'a',
      slots: { name: 'mine' },
    })
  })

  it('applies an own removal at once as a placeholder', () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: {} }], 1)
    controller.expectOwnChange('a', Promise.resolve())
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'a',
      reason: 'deleted',
    })

    expect(controller.pendingCount.get()).toBe(0)
    expect(controller.rows.get()[0]).toEqual({
      rowKey: 'a',
      row: null,
      placeholder: true,
    })
  })

  it('drops the own-change mark when the action fails, so the echo queues as pending', async () => {
    const { controller } = makeController()
    controller.ingestWindow([{ rowKey: 'a', slots: { name: 'old' } }], 1)
    const settled = Promise.reject(new Error('fail'))
    controller.expectOwnChange('a', settled)
    await settled.catch(() => {}) // let the rejection clear the mark
    controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'new' } },
    })

    expect(controller.pendingCount.get()).toBe(1)
  })
})
