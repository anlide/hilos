import { describe, expect, it } from 'vitest'
import {
  hilosTableViews,
  TABLE_VIEW_COPY,
  type HilosTableViews,
} from '../../src/table/tableView.js'

const columns = [{ key: 'picture', label: 'Picture' }]
const declaration: HilosTableViews = {
  tableKey: 'products',
  order: ['table', 'tiles'],
  imageColumn: 'picture',
}

describe('hilosTableViews', () => {
  it('keeps omission compatible and shares the view copy', () => {
    expect(hilosTableViews(undefined, [])).toBeUndefined()
    expect(TABLE_VIEW_COPY).toEqual({
      table: 'Table',
      tiles: 'Tiles',
      group: 'Table view',
      noImage: 'No image',
    })
  })

  it.each<HilosTableViews['order']>([
    ['table', 'tiles'],
    ['tiles', 'table'],
  ])('accepts the opening %s view followed by %s', (first, second) => {
    const input = { ...declaration, order: [first, second] }
    const result = hilosTableViews(input, columns)
    expect(result).toEqual(input)
    expect(result).not.toBe(input)
    expect(Object.isFrozen(result)).toBe(true)
    expect(Object.isFrozen(result?.order)).toBe(true)
    input.order.reverse()
    expect(result?.order).toEqual([first, second])
  })

  it.each([
    null,
    [],
    {},
    { ...declaration, tableKey: '' },
    { ...declaration, tableKey: '  ' },
    { ...declaration, tableKey: 1 },
    { ...declaration, order: ['table'] },
    { ...declaration, order: ['table', 'table'] },
    { ...declaration, order: ['tiles', 'tiles'] },
    { ...declaration, order: ['table', 'grid'] },
    { ...declaration, order: ['table', 'tiles', 'table'] },
    { ...declaration, order: 'table,tiles' },
    { ...declaration, imageColumn: '' },
    { ...declaration, imageColumn: 'missing' },
    { ...declaration, imageColumn: 'actions' },
    { ...declaration, imageColumn: 'detail' },
  ])('rejects an invalid runtime declaration %#', (input) => {
    expect(() =>
      hilosTableViews(input, [
        ...columns,
        { key: 'actions', label: '', reads: [] },
        { key: 'detail', label: 'Detail', detail: true },
      ]),
    ).toThrow(TypeError)
  })
})
