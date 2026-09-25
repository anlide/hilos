import { describe, expect, it } from 'vitest'
import {
  TABLE_PLACEHOLDER_COPY,
  hilosTablePlaceholder,
  hilosTableRemovalReason,
} from '../../src/table/tablePlaceholder.js'

describe('table placeholder copy', () => {
  it('names every removal reason with its own icon and words', () => {
    expect(TABLE_PLACEHOLDER_COPY).toEqual({
      deleted: { icon: 'bi-dash-circle', text: 'Removed' },
      moved_out: { icon: 'bi-arrows-move', text: 'Moved to another page' },
      left_set: {
        icon: 'bi-box-arrow-right',
        text: 'No longer in this list',
      },
    })
  })

  it('uses the removed form when a view has no explicit reason', () => {
    expect(hilosTablePlaceholder(null)).toEqual(TABLE_PLACEHOLDER_COPY.deleted)
  })
})

describe('hilosTableRemovalReason', () => {
  it('keeps the two specific reasons and treats every other value as deleted', () => {
    expect(hilosTableRemovalReason('left_set')).toBe('left_set')
    expect(hilosTableRemovalReason('moved_out')).toBe('moved_out')
    expect(hilosTableRemovalReason('deleted')).toBe('deleted')
    expect(hilosTableRemovalReason('')).toBe('deleted')
    expect(hilosTableRemovalReason('future_reason')).toBe('deleted')
    expect(hilosTableRemovalReason(undefined)).toBe('deleted')
  })
})
