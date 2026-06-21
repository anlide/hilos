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
      { rowKey: 'a', row: { rowKey: 'a', slots: {} } },
    ])
    expect(controller.totalCount.get()).toBe(42)
    expect(controller.pageCount.get()).toBe(5)
  })
})
