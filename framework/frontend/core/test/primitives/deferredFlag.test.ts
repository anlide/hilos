import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { createDeferredFlagState } from '../../src/primitives/deferredFlag.js'

describe('createDeferredFlagState', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('keeps the flag down right after it is armed', () => {
    const flag = createDeferredFlagState(300)
    flag.set(true)
    expect(flag.shown.get()).toBe(false)
  })

  it('raises the flag once the delay has run out', () => {
    const flag = createDeferredFlagState(300)
    flag.set(true)
    vi.advanceTimersByTime(299)
    expect(flag.shown.get()).toBe(false)
    vi.advanceTimersByTime(1)
    expect(flag.shown.get()).toBe(true)
  })

  it('never raises the flag when the condition clears before the delay', () => {
    const flag = createDeferredFlagState(300)
    flag.set(true)
    vi.advanceTimersByTime(200)
    flag.set(false)
    vi.advanceTimersByTime(200)
    expect(flag.shown.get()).toBe(false)
  })

  it('lowers a raised flag at once when the condition clears', () => {
    const flag = createDeferredFlagState(300)
    flag.set(true)
    vi.advanceTimersByTime(300)
    flag.set(false)
    expect(flag.shown.get()).toBe(false)
  })

  it('re-arms from the start when armed again', () => {
    const flag = createDeferredFlagState(300)
    flag.set(true)
    vi.advanceTimersByTime(200)
    flag.set(true)
    vi.advanceTimersByTime(200)
    expect(flag.shown.get()).toBe(false)
    vi.advanceTimersByTime(100)
    expect(flag.shown.get()).toBe(true)
  })

  it('reads a delay getter each time it arms', () => {
    let delay = 100
    const flag = createDeferredFlagState(() => delay)
    flag.set(true)
    vi.advanceTimersByTime(100)
    expect(flag.shown.get()).toBe(true)

    flag.set(false)
    delay = 500
    flag.set(true)
    vi.advanceTimersByTime(100)
    expect(flag.shown.get()).toBe(false)
    vi.advanceTimersByTime(400)
    expect(flag.shown.get()).toBe(true)
  })

  it('cancels a pending timer on dispose', () => {
    const flag = createDeferredFlagState(300)
    flag.set(true)
    flag.dispose()
    vi.advanceTimersByTime(300)
    expect(flag.shown.get()).toBe(false)
  })
})
