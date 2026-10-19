import { describe, expect, it } from 'vitest'
import {
  hiddenAsWord,
  HILOS_VIEW_MODE_COPY,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  VIEW_MODE_ERROR_CODE,
} from '../../src/admin/viewMode.js'
import { HIDDEN_VALUE } from '../../src/state/hiddenValue.js'

describe('admin view mode words', () => {
  it('pins the strip and hidden words without a client refusal sentence', () => {
    expect(VIEW_MODE_ERROR_CODE).toBe('view_mode')
    expect(HILOS_VIEW_MODE_COPY).toEqual({
      mark: 'View mode',
      explanation: 'You can look around, but not change anything.',
      hidden: 'Hidden',
    })
    expect(HILOS_VIEW_MODE_STRIP_TEXT_ID).toBe('hilos-view-mode-strip-text')
  })

  it('says a hidden value as the word, and any other as itself', () => {
    expect(hiddenAsWord(HIDDEN_VALUE)).toBe('Hidden')
    expect(hiddenAsWord('Olena')).toBe('Olena')
    expect(hiddenAsWord<string | null>(null)).toBeNull()
  })
})
