import { describe, expect, it } from 'vitest'
import {
  actionFailureReason,
  HILOS_VIEW_MODE_COPY,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  VIEW_MODE_ERROR_CODE,
} from '../../src/admin/viewMode.js'

describe('admin view mode words', () => {
  it('reads a view-mode refusal as the mode sentence', () => {
    expect(
      actionFailureReason(
        'The action could not be completed.',
        VIEW_MODE_ERROR_CODE,
      ),
    ).toBe(HILOS_VIEW_MODE_COPY.refusal)
  })

  it('keeps the server reason for any other code or none', () => {
    expect(actionFailureReason('Name already taken', undefined)).toBe(
      'Name already taken',
    )
    expect(actionFailureReason('Forbidden.', 'forbidden')).toBe('Forbidden.')
  })

  it('pins the words and the strip text id', () => {
    expect(VIEW_MODE_ERROR_CODE).toBe('view_mode')
    expect(HILOS_VIEW_MODE_COPY).toEqual({
      mark: 'View mode',
      explanation: 'You can look around, but not change anything.',
      refusal: 'View mode: you can look around, but not change anything.',
    })
    expect(HILOS_VIEW_MODE_STRIP_TEXT_ID).toBe('hilos-view-mode-strip-text')
  })
})
