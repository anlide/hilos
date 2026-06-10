import { describe, expect, it } from 'vitest'
import { computeBackoffDelay, DEFAULT_RECONNECT_OPTIONS } from './backoff.js'

const options = { baseDelayMs: 1000, maxDelayMs: 30000, factor: 2 }

describe('computeBackoffDelay', () => {
  it('grows exponentially with the attempt at full random', () => {
    const atMax = (attempt: number) =>
      computeBackoffDelay(attempt, options, () => 1)
    expect(atMax(0)).toBe(1000)
    expect(atMax(1)).toBe(2000)
    expect(atMax(2)).toBe(4000)
    expect(atMax(3)).toBe(8000)
  })

  it('caps the delay at maxDelayMs', () => {
    expect(computeBackoffDelay(10, options, () => 1)).toBe(30000)
    expect(computeBackoffDelay(100, options, () => 1)).toBe(30000)
  })

  it('applies full jitter down to zero', () => {
    expect(computeBackoffDelay(5, options, () => 0)).toBe(0)
    expect(computeBackoffDelay(2, options, () => 0.5)).toBe(2000)
  })

  it('ships sane defaults', () => {
    expect(DEFAULT_RECONNECT_OPTIONS).toEqual({
      baseDelayMs: 1000,
      maxDelayMs: 30000,
      factor: 2,
    })
  })
})
