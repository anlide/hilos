import { describe, expect, it } from 'vitest'
import { TableController } from '../../src/table/TableController.js'
import { type TableRow } from '../../src/state/TableRowsStore.js'
import {
  createSignal,
  subscribeSignal,
  type WritableSignal,
} from '../../src/state/signal.js'

interface UserVM {
  id: number
  name: string
  age: number | null
}

function source(...rows: TableRow[]): WritableSignal<readonly TableRow[]> {
  return createSignal<readonly TableRow[]>(rows)
}

function resolve(row: TableRow): UserVM {
  return {
    id: Number(row.rowKey),
    name: String(row.slots['name'] ?? ''),
    age: (row.slots['age'] as number | undefined) ?? null,
  }
}

const behavior = {
  resolve,
  searchText: (user: UserVM) => user.name,
  sortValue: (user: UserVM, field: string) =>
    field === 'age' ? user.age : field === 'name' ? user.name : user.id,
}

function keys<R>(rows: readonly { rowKey: string; row: R }[]): string[] {
  return rows.map((view) => view.rowKey)
}

describe('TableController', () => {
  it('resolves source rows to view rows', () => {
    const controller = new TableController({
      source: source(
        { rowKey: '1', slots: { name: 'Ann' } },
        { rowKey: '2', slots: { name: 'Bea' } },
      ),
      ...behavior,
    })

    expect(controller.rows.get()).toEqual([
      { rowKey: '1', row: { id: 1, name: 'Ann', age: null } },
      { rowKey: '2', row: { id: 2, name: 'Bea', age: null } },
    ])
    expect(controller.pageRows.get()).toEqual(controller.rows.get())
  })

  it('reacts when the source rows change', () => {
    const rows = source({ rowKey: '1', slots: { name: 'Ann' } })
    const controller = new TableController({ source: rows, ...behavior })

    const counts: number[] = []
    subscribeSignal(controller.pageRows, (view) => counts.push(view.length))

    rows.set([
      { rowKey: '1', slots: { name: 'Ann' } },
      { rowKey: '2', slots: { name: 'Bea' } },
    ])
    expect(counts).toEqual([2])
  })

  it('filters by search text, case-insensitively, and reflects the total', () => {
    const controller = new TableController({
      source: source(
        { rowKey: '1', slots: { name: 'Ann' } },
        { rowKey: '2', slots: { name: 'Bob' } },
        { rowKey: '3', slots: { name: 'anabel' } },
      ),
      ...behavior,
    })

    controller.setSearch('an')
    expect(keys(controller.pageRows.get())).toEqual(['1', '3'])
    expect(controller.totalCount.get()).toBe(2)
  })

  it('does not filter when no searchText accessor is configured', () => {
    const controller = new TableController({
      source: source({ rowKey: '1', slots: { name: 'Ann' } }),
      resolve,
    })

    controller.setSearch('zzz')
    expect(controller.totalCount.get()).toBe(1)
  })

  it('sorts ascending then toggles to descending on the same field', () => {
    const controller = new TableController({
      source: source(
        { rowKey: '1', slots: { name: 'Bea' } },
        { rowKey: '2', slots: { name: 'Ann' } },
        { rowKey: '3', slots: { name: 'Cara' } },
      ),
      ...behavior,
    })

    controller.setSort('name')
    expect(keys(controller.pageRows.get())).toEqual(['2', '1', '3'])
    expect(controller.sort.get()).toEqual({ field: 'name', direction: 'asc' })

    controller.setSort('name')
    expect(keys(controller.pageRows.get())).toEqual(['3', '1', '2'])
    expect(controller.sort.get()).toEqual({ field: 'name', direction: 'desc' })
  })

  it('sorts numeric fields numerically', () => {
    const controller = new TableController({
      source: source(
        { rowKey: '1', slots: { name: 'a', age: 9 } },
        { rowKey: '2', slots: { name: 'b', age: 100 } },
        { rowKey: '3', slots: { name: 'c', age: 20 } },
      ),
      ...behavior,
    })

    controller.setSort('age')
    expect(keys(controller.pageRows.get())).toEqual(['1', '3', '2'])
  })

  it('sorts missing values last in both directions', () => {
    const controller = new TableController({
      source: source(
        { rowKey: '1', slots: { name: 'a', age: 30 } },
        { rowKey: '2', slots: { name: 'b' } },
        { rowKey: '3', slots: { name: 'c', age: 20 } },
      ),
      ...behavior,
    })

    controller.setSort('age')
    expect(keys(controller.pageRows.get())).toEqual(['3', '1', '2'])
    controller.setSort('age')
    expect(keys(controller.pageRows.get())).toEqual(['1', '3', '2'])
  })

  it('clears the sort, restoring arrival order', () => {
    const controller = new TableController({
      source: source(
        { rowKey: '2', slots: { name: 'Ann' } },
        { rowKey: '1', slots: { name: 'Bea' } },
      ),
      ...behavior,
    })

    controller.setSort('name')
    expect(keys(controller.pageRows.get())).toEqual(['2', '1'])
    controller.clearSort()
    expect(keys(controller.pageRows.get())).toEqual(['2', '1'])
    expect(controller.sort.get()).toBeUndefined()
  })

  it('paginates the rows and clamps an out-of-range page', () => {
    const controller = new TableController({
      source: source(
        { rowKey: '1', slots: { name: 'u1' } },
        { rowKey: '2', slots: { name: 'u2' } },
        { rowKey: '3', slots: { name: 'u3' } },
        { rowKey: '4', slots: { name: 'u4' } },
        { rowKey: '5', slots: { name: 'u5' } },
      ),
      ...behavior,
      pageSize: 2,
    })

    expect(controller.pageCount.get()).toBe(3)
    expect(keys(controller.pageRows.get())).toEqual(['1', '2'])

    controller.setPage(1)
    expect(keys(controller.pageRows.get())).toEqual(['3', '4'])

    controller.setPage(99)
    expect(controller.page.get()).toBe(2)
    expect(keys(controller.pageRows.get())).toEqual(['5'])
  })

  it('returns to the first page on search, sort, and page-size change', () => {
    const controller = new TableController({
      source: source(
        { rowKey: '1', slots: { name: 'u1' } },
        { rowKey: '2', slots: { name: 'u2' } },
        { rowKey: '3', slots: { name: 'u3' } },
        { rowKey: '4', slots: { name: 'u4' } },
      ),
      ...behavior,
      pageSize: 2,
    })

    controller.setPage(1)
    controller.setSearch('u')
    expect(controller.page.get()).toBe(0)

    controller.setPage(1)
    controller.setSort('name')
    expect(controller.page.get()).toBe(0)

    controller.setPage(1)
    controller.setPageSize(3)
    expect(controller.page.get()).toBe(0)
    expect(controller.pageCount.get()).toBe(2)
  })
})
