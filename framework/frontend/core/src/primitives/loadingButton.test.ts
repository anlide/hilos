import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  DEFAULT_SPINNER_DELAY_MS,
  createLoadingButtonState,
} from './loadingButton.js'

describe('createLoadingButtonState', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('starts with the spinner hidden', () => {
    const state = createLoadingButtonState()
    expect(state.showSpinner.get()).toBe(false)
  })

  it('shows the spinner only after the delay while loading', () => {
    const state = createLoadingButtonState(DEFAULT_SPINNER_DELAY_MS)
    state.setLoading(true)
    expect(state.showSpinner.get()).toBe(false)
    vi.advanceTimersByTime(DEFAULT_SPINNER_DELAY_MS - 1)
    expect(state.showSpinner.get()).toBe(false)
    vi.advanceTimersByTime(1)
    expect(state.showSpinner.get()).toBe(true)
  })

  it('never shows the spinner when loading clears before the delay', () => {
    const state = createLoadingButtonState(300)
    state.setLoading(true)
    vi.advanceTimersByTime(200)
    state.setLoading(false)
    vi.advanceTimersByTime(200)
    expect(state.showSpinner.get()).toBe(false)
  })

  it('hides the spinner at once when loading clears after it appeared', () => {
    const state = createLoadingButtonState(300)
    state.setLoading(true)
    vi.advanceTimersByTime(300)
    expect(state.showSpinner.get()).toBe(true)
    state.setLoading(false)
    expect(state.showSpinner.get()).toBe(false)
  })

  it('reads a delay getter each time it arms', () => {
    let delay = 100
    const state = createLoadingButtonState(() => delay)
    state.setLoading(true)
    vi.advanceTimersByTime(100)
    expect(state.showSpinner.get()).toBe(true)

    state.setLoading(false)
    delay = 500
    state.setLoading(true)
    vi.advanceTimersByTime(100)
    expect(state.showSpinner.get()).toBe(false)
    vi.advanceTimersByTime(400)
    expect(state.showSpinner.get()).toBe(true)
  })

  it('cancels a pending spinner on dispose', () => {
    const state = createLoadingButtonState(300)
    state.setLoading(true)
    state.dispose()
    vi.advanceTimersByTime(300)
    expect(state.showSpinner.get()).toBe(false)
  })
})
