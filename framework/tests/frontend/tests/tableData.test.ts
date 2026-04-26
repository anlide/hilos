import { describe, expect, it } from 'vitest'
import { applyTableMutation, applyTableMutations } from '@/composables/useTableData'
import type { TableDataState } from '@/types/table'

describe('applyTableMutation', () => {
  it('applies immediate mutation without clearing pending mutations', () => {
    const state: TableDataState = {
      rows: [{ id: 1, name: 'Old' }],
      totalCount: 1,
      offset: 0,
      limit: 10,
      mutations: [
        { type: 'update', rowKey: 2, row: { id: 2, name: 'Pending' } },
      ],
    }

    const result = applyTableMutation(state, {
      type: 'update',
      rowKey: 1,
      row: { name: 'Immediate' },
    })

    expect(result.state.rows).toEqual([{ id: 1, name: 'Immediate' }])
    expect(result.state.mutations).toEqual([
      { type: 'update', rowKey: 2, row: { id: 2, name: 'Pending' } },
    ])
  })
})

describe('applyTableMutations', () => {
  it('matches rows by configured string row key field', () => {
    const state: TableDataState = {
      rows: [{ key: 'chat.title', value: 'Old' }],
      totalCount: 1,
      offset: 0,
      limit: 10,
      mutations: [
        { type: 'update', rowKey: 'chat.title', row: { value: 'New' } },
      ],
      rowKeyField: 'key',
    }

    const result = applyTableMutations(state)

    expect(result.state.rows).toEqual([{ key: 'chat.title', value: 'New' }])
    expect(result.state.mutations).toEqual([])
  })

  it('applies create, update, and delete row mutations', () => {
    const state: TableDataState = {
      rows: [
        { id: 1, name: 'Old' },
        { id: 2, name: 'Gone' },
      ],
      totalCount: 2,
      offset: 0,
      limit: 10,
      mutations: [
        { type: 'create', rowKey: 3, row: { id: 3, name: 'New' } },
        { type: 'update', rowKey: 1, row: { name: 'Updated' } },
        { type: 'delete', rowKey: 2 },
      ],
    }

    const result = applyTableMutations(state)

    expect(result.state.rows).toEqual([
      { id: 1, name: 'Updated' },
      { id: 3, name: 'New' },
    ])
    expect(result.state.totalCount).toBe(2)
    expect(result.state.mutations).toEqual([])
    expect(result.hasDeletes).toBe(true)
  })
})
