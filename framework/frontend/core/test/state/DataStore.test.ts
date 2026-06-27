import { describe, expect, it } from 'vitest'
import { DataStore } from '../../src/state/DataStore.js'
import { subscribeSignal } from '../../src/state/signal.js'

describe('DataStore', () => {
  it('returns undefined before the first set, the value after it', () => {
    const store = new DataStore()
    expect(store.signal('title').get()).toBeUndefined()

    store.set('title', 'Dashboard')
    expect(store.signal('title').get()).toBe('Dashboard')
  })

  it('replaces values and notifies subscribers', () => {
    const store = new DataStore()
    const seen: unknown[] = []
    store.set('count', 1)
    subscribeSignal(store.signal('count'), (value) => seen.push(value))

    store.set('count', 2)
    store.set('count', 3)
    expect(seen).toEqual([2, 3])
  })

  it('lets a key be watched before its value arrives', () => {
    const store = new DataStore()
    const seen: unknown[] = []
    subscribeSignal(store.signal('late'), (value) => seen.push(value))

    store.set('late', 'here')
    expect(seen).toEqual(['here'])
  })

  it('keeps keys independent', () => {
    const store = new DataStore()
    store.set('a', 1)
    store.set('b', 2)

    expect(store.signal('a').get()).toBe(1)
    expect(store.signal('b').get()).toBe(2)
  })
})
