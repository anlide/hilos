import { describe, expect, it } from 'vitest'
import {
  STATUS_FILES_CHANGED_SIGNAL,
  STATUS_SIGNAL_SCHEMAS,
  statusFilesChangedSchema,
} from '../../src/protocol/statusSignals.js'

describe('Status publication hint', () => {
  it('accepts exactly an empty object', () => {
    expect(statusFilesChangedSchema.parse({})).toEqual({})
    expect(STATUS_SIGNAL_SCHEMAS[STATUS_FILES_CHANGED_SIGNAL]).toBe(
      statusFilesChangedSchema,
    )
    for (const value of [[], null, 0, 'hint', true, { state: 'operational' }]) {
      expect(statusFilesChangedSchema.safeParse(value).success).toBe(false)
    }
  })
})
