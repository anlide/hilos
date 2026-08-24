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

    vi.advanceTimersByTime(19999)
    expect(store.toasts.get()).toHaveLength(1)

    vi.advanceTimersByTime(1)
    expect(store.toasts.get()).toEqual([])
  })

  it('keeps an error notice on screen longer than a success one', () => {
    const store = createHilosToastStore()
    store.push('failed', { severity: 'error' })

    vi.advanceTimersByTime(20000)
    expect(store.toasts.get()).toHaveLength(1)

    vi.advanceTimersByTime(20000)
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

  it('freezes the countdown while the stack is held', () => {
    const store = createHilosToastStore()
    store.push('read me', { severity: 'success' })

    store.pause()
    vi.advanceTimersByTime(60_000)

    expect(store.toasts.get()).toHaveLength(1)
  })

  it('resumes the countdown from what is left, not from the full lifetime', () => {
    const store = createHilosToastStore()
    store.push('read me', { severity: 'success' })

    vi.advanceTimersByTime(8000)
    store.pause()
    vi.advanceTimersByTime(60_000)
    store.resume()

    vi.advanceTimersByTime(11_999)
    expect(store.toasts.get()).toHaveLength(1)

    vi.advanceTimersByTime(1)
    expect(store.toasts.get()).toEqual([])
  })

  it('starts a notice pushed during a hold only once the hold is released', () => {
    const store = createHilosToastStore()
    store.pause()
    store.push('late arrival', { severity: 'success' })

    vi.advanceTimersByTime(60_000)
    expect(store.toasts.get()).toHaveLength(1)

    store.resume()
    vi.advanceTimersByTime(19_999)
    expect(store.toasts.get()).toHaveLength(1)

    vi.advanceTimersByTime(1)
    expect(store.toasts.get()).toEqual([])
  })

  it('needs one resume per hold, so the cursor cannot release the focus hold', () => {
    const store = createHilosToastStore()
    store.push('read me', { severity: 'success' })

    store.pause()
    store.pause()
    store.resume()

    vi.advanceTimersByTime(60_000)
    expect(store.toasts.get()).toHaveLength(1)

    store.resume()
    vi.advanceTimersByTime(20_000)
    expect(store.toasts.get()).toEqual([])
  })

  it('releases every hold when the stack is cleared', () => {
    const store = createHilosToastStore()
    store.pause()
    store.pause()
    store.clear()

    store.push('after the clear', { severity: 'success' })
    vi.advanceTimersByTime(20_000)

    expect(store.toasts.get()).toEqual([])
  })

  it('evicts the oldest notice once the stack is over the visible limit', () => {
    const store = createHilosToastStore()
    for (const message of ['first', 'second', 'third', 'fourth']) {
      store.push(message)
    }
    expect(store.toasts.get()).toHaveLength(4)

    store.push('fifth')

    expect(store.toasts.get().map((toast) => toast.message)).toEqual([
      'second',
      'third',
      'fourth',
      'fifth',
    ])
  })

  it('evicts a sticky notice like any other', () => {
    const store = createHilosToastStore()
    store.push('sticky', { ttlMs: 0 })
    for (const message of ['second', 'third', 'fourth', 'fifth']) {
      store.push(message)
    }

    expect(store.toasts.get().map((toast) => toast.message)).toEqual([
      'second',
      'third',
      'fourth',
      'fifth',
    ])
  })

  it('postpones eviction while held and applies it in one move on resume', () => {
    const store = createHilosToastStore()
    store.pause()
    for (const message of [
      'first',
      'second',
      'third',
      'fourth',
      'fifth',
      'sixth',
    ]) {
      store.push(message)
    }
    expect(store.toasts.get()).toHaveLength(6)

    store.resume()

    expect(store.toasts.get().map((toast) => toast.message)).toEqual([
      'third',
      'fourth',
      'fifth',
      'sixth',
    ])
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
