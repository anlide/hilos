import { describe, expect, it } from 'vitest'
import { applyTableMutations } from '@/composables/useTableData'
import type { TableDataState } from '@/types/table'

describe('applyTableMutations', () => {
  it('matches rows by configured string row key field', () => {
    const state: TableDataState = {
      rows: [{ key: 'chat.title', value: 'Old' }],
      totalCount: 1,
      offset: 0,
      limit: 10,
      mutations: [
        { type: 'updated', rowKey: 'chat.title', row: { value: 'New' } },
      ],
      rowKeyField: 'key',
    }

    const result = applyTableMutations(state)

    expect(result.state.rows).toEqual([{ key: 'chat.title', value: 'New' }])
    expect(result.state.mutations).toEqual([])
  })
})
