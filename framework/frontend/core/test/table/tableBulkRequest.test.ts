import { describe, expect, it } from 'vitest'

import {
  hilosTableBulkAcceptedSchema,
  hilosTableBulkPayload,
} from '../../src/table/tableBulkRequest.js'

describe('hilosTableBulkPayload', () => {
  it('names the rows and carries no condition beside them', () => {
    expect(
      hilosTableBulkPayload('hilosBackups', { kind: 'rows', rowKeys: ['b1'] }),
    ).toEqual({ tableKey: 'hilosBackups', rowKeys: ['b1'] })
  })

  it('carries the condition and names no rows beside it', () => {
    expect(
      hilosTableBulkPayload('hilosBackups', {
        kind: 'filter',
        filter: { scope: 'full' },
      }),
    ).toEqual({ tableKey: 'hilosBackups', filter: { scope: 'full' } })
  })
})

describe('hilosTableBulkAcceptedSchema', () => {
  it('reads an absent total as a run with no honest count', () => {
    expect(
      hilosTableBulkAcceptedSchema.parse({ progressKey: 'hilosBackups:1' }),
    ).toEqual({ progressKey: 'hilosBackups:1', total: null })
  })

  it('keeps a total the run could count', () => {
    expect(
      hilosTableBulkAcceptedSchema.parse({
        progressKey: 'hilosBackups:2',
        total: 7,
      }),
    ).toEqual({ progressKey: 'hilosBackups:2', total: 7 })
  })
})
