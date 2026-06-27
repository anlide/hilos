import { describe, expect, it } from 'vitest'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
} from '../../src/state/signal.js'

describe('createSignal', () => {
  it('holds the initial value and replaces it on set', () => {
    const count = createSignal(1)
    expect(count.get()).toBe(1)

    count.set(2)
    expect(count.get()).toBe(2)
  })
})

describe('computedSignal', () => {
  it('derives from signals it reads and tracks their changes', () => {
    const count = createSignal(2)
    const doubled = computedSignal(() => count.get() * 2)
    expect(doubled.get()).toBe(4)

    count.set(5)
    expect(doubled.get()).toBe(10)
  })

  it('computes lazily and caches between changes', () => {
    let runs = 0
    const count = createSignal(1)
    const doubled = computedSignal(() => {
      runs += 1
      return count.get() * 2
    })
    expect(runs).toBe(0)

    doubled.get()
    doubled.get()
    expect(runs).toBe(1)

    count.set(2)
    doubled.get()
    expect(runs).toBe(2)
  })
})

describe('subscribeSignal', () => {
  it('fires synchronously with the new value, never on subscription', () => {
    const count = createSignal(1)
    const seen: number[] = []
    subscribeSignal(count, (value) => seen.push(value))
    expect(seen).toEqual([])

    count.set(2)
    count.set(3)
    expect(seen).toEqual([2, 3])
  })

  it('skips writes that do not change the value', () => {
    const count = createSignal(1)
    const seen: number[] = []
    subscribeSignal(count, (value) => seen.push(value))

    count.set(1)
    expect(seen).toEqual([])
  })

  it('is shallow: in-place mutation does not notify, replacement does', () => {
    const user = createSignal({ name: 'a' })
    const seen: string[] = []
    subscribeSignal(user, (value) => seen.push(value.name))

    user.get().name = 'b'
    expect(seen).toEqual([])

    user.set({ name: 'c' })
    expect(seen).toEqual(['c'])
  })

  it('notifies on a computed only when its result changes', () => {
    const count = createSignal(1)
    const positive = computedSignal(() => count.get() > 0)
    const seen: boolean[] = []
    subscribeSignal(positive, (value) => seen.push(value))

    count.set(2)
    expect(seen).toEqual([])

    count.set(-1)
    expect(seen).toEqual([false])
  })

  it('stops notifying after unsubscribe, leaving other subscribers live', () => {
    const count = createSignal(1)
    const first: number[] = []
    const second: number[] = []
    const unsubscribe = subscribeSignal(count, (value) => first.push(value))
    subscribeSignal(count, (value) => second.push(value))

    count.set(2)
    unsubscribe()
    count.set(3)

    expect(first).toEqual([2])
    expect(second).toEqual([2, 3])
  })
})
