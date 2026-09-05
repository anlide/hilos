import { describe, expect, it } from 'vitest'

import {
  isReconnectDragging,
  RECONNECT_DRAGGING_COPY,
} from '../../src/connection/reconnectDragging.js'

const options = { baseDelayMs: 1000, maxDelayMs: 30000, factor: 2 }

describe('isReconnectDragging', () => {
  it('stays false while the pauses are still growing', () => {
    expect(isReconnectDragging(0, options)).toBe(false)
    expect(isReconnectDragging(1, options)).toBe(false)
    expect(isReconnectDragging(2, options)).toBe(false)
    expect(isReconnectDragging(3, options)).toBe(false)
    expect(isReconnectDragging(4, options)).toBe(false)
  })

  it('holds from the attempt whose pause reaches the ceiling onwards', () => {
    expect(isReconnectDragging(5, options)).toBe(true)
    expect(isReconnectDragging(100, options)).toBe(true)
  })

  it('moves with the options rather than with a constant of its own', () => {
    const lowCeiling = { baseDelayMs: 1000, maxDelayMs: 5000, factor: 2 }
    expect(isReconnectDragging(2, lowCeiling)).toBe(false)
    expect(isReconnectDragging(3, lowCeiling)).toBe(true)
  })

  it('carries the sentence the mark shows', () => {
    expect(RECONNECT_DRAGGING_COPY.dragging).toBe(
      'still trying; this is taking longer than usual.',
    )
  })
})
