import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { createHilosToastStore } from './toasts.js'

describe('createHilosToastStore', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('starts empty', () => {
    const store = createHilosToastStore()
    expect(store.toasts.get()).toEqual([])
  })

  it('queues a notice with its severity, oldest first', () => {
    const store = createHilosToastStore()
    store.push('first', { severity: 'error' })
    store.push('second', { severity: 'success' })

    expect(store.toasts.get().map((toast) => toast.message)).toEqual([
      'first',
      'second',
    ])
    expect(store.toasts.get()[0].severity).toBe('error')
  })

  it('defaults an unqualified notice to info', () => {
    const store = createHilosToastStore()
    store.push('plain')

    expect(store.toasts.get()[0].severity).toBe('info')
  })

  it('expires a success notice after its default lifetime', () => {
    const store = createHilosToastStore()
    store.push('done', { severity: 'success' })

    vi.advanceTimersByTime(4999)
    expect(store.toasts.get()).toHaveLength(1)

    vi.advanceTimersByTime(1)
    expect(store.toasts.get()).toEqual([])
  })

  it('keeps an error notice on screen longer than a success one', () => {
    const store = createHilosToastStore()
    store.push('failed', { severity: 'error' })

    vi.advanceTimersByTime(5000)
    expect(store.toasts.get()).toHaveLength(1)

    vi.advanceTimersByTime(7000)
    expect(store.toasts.get()).toEqual([])
  })

  it('keeps a notice until dismissed when its lifetime is zero', () => {
    const store = createHilosToastStore()
    const id = store.push('sticky', { ttlMs: 0 })

    vi.advanceTimersByTime(60_000)
    expect(store.toasts.get()).toHaveLength(1)

    store.dismiss(id)
    expect(store.toasts.get()).toEqual([])
  })

  it('dismisses one notice without touching the others', () => {
    const store = createHilosToastStore()
    const first = store.push('first')
    store.push('second')

    store.dismiss(first)

    expect(store.toasts.get().map((toast) => toast.message)).toEqual(['second'])
  })

  it('ignores a dismiss of an id that is already gone', () => {
    const store = createHilosToastStore()
    const id = store.push('once')
    store.dismiss(id)

    expect(() => store.dismiss(id)).not.toThrow()
    expect(store.toasts.get()).toEqual([])
  })

  it('clears the whole stack and its pending timers', () => {
    const store = createHilosToastStore()
    store.push('first')
    store.push('second')

    store.clear()
    expect(store.toasts.get()).toEqual([])

    // No timer may fire into the cleared stack afterwards.
    vi.advanceTimersByTime(60_000)
    expect(store.toasts.get()).toEqual([])
  })

  it('gives every notice its own id', () => {
    const store = createHilosToastStore()

    expect(store.push('a')).not.toBe(store.push('b'))
  })
})
