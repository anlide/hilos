import { describe, expect, it } from 'vitest'
import { ListStore } from '../../src/state/ListStore.js'
import { subscribeSignal } from '../../src/state/signal.js'

describe('ListStore', () => {
  it('returns an empty list before the first upsert', () => {
    const store = new ListStore()
    expect(store.signal('events').get()).toEqual([])
  })

  it('appends new items at the end, preserving arrival order', () => {
    const store = new ListStore()
    store.upsert('events', 1, { text: 'first' })
    store.upsert('events', 2, { text: 'second' })

    expect(store.signal('events').get()).toEqual([
      { itemKey: '1', slots: { text: 'first' } },
      { itemKey: '2', slots: { text: 'second' } },
    ])
  })

  it('replaces an existing item in place, keeping its position', () => {
    const store = new ListStore()
    store.upsert('events', 1, { text: 'a' })
    store.upsert('events', 2, { text: 'b' })
    store.upsert('events', 1, { text: 'edited' })

    expect(store.signal('events').get()).toEqual([
      { itemKey: '1', slots: { text: 'edited' } },
      { itemKey: '2', slots: { text: 'b' } },
    ])
  })

  it('matches item keys stringified, so 1 and "1" are one item', () => {
    const store = new ListStore()
    store.upsert('events', 1, { text: 'a' })
    store.upsert('events', '1', { text: 'b' })

    expect(store.signal('events').get()).toEqual([
      { itemKey: '1', slots: { text: 'b' } },
    ])
  })

  it('deletes an item and leaves the rest in order', () => {
    const store = new ListStore()
    store.upsert('events', 1, { text: 'a' })
    store.upsert('events', 2, { text: 'b' })
    store.upsert('events', 3, { text: 'c' })
    store.delete('events', 2)

    expect(store.signal('events').get()).toEqual([
      { itemKey: '1', slots: { text: 'a' } },
      { itemKey: '3', slots: { text: 'c' } },
    ])
  })

  it('treats a delete of an absent item as a no-op', () => {
    const store = new ListStore()
    store.upsert('events', 1, { text: 'a' })
    const before = store.signal('events').get()
    store.delete('events', 99)

    expect(store.signal('events').get()).toBe(before)
  })

  it('clears every item from a list (truncate)', () => {
    const store = new ListStore()
    store.upsert('events', 1, { text: 'a' })
    store.upsert('events', 2, { text: 'b' })
    store.clear('events')

    expect(store.signal('events').get()).toEqual([])
  })

  it('upserts after a clear start from an empty list', () => {
    const store = new ListStore()
    store.upsert('events', 1, { text: 'a' })
    store.clear('events')
    store.upsert('events', 9, { text: 'fresh' })

    expect(store.signal('events').get()).toEqual([
      { itemKey: '9', slots: { text: 'fresh' } },
    ])
  })

  it('treats a clear of an already-empty list as a no-op', () => {
    const store = new ListStore()
    const before = store.signal('events').get()
    store.clear('events')

    expect(store.signal('events').get()).toBe(before)
  })

  it('keeps lists independent under different keys', () => {
    const store = new ListStore()
    store.upsert('events', 1, { text: 'e' })
    store.upsert('users', 1, { name: 'Ann' })

    expect(store.signal('events').get()).toEqual([
      { itemKey: '1', slots: { text: 'e' } },
    ])
    expect(store.signal('users').get()).toEqual([
      { itemKey: '1', slots: { name: 'Ann' } },
    ])
  })

  it('replaces the array on change instead of mutating the previous one', () => {
    const store = new ListStore()
    store.upsert('events', 1, { text: 'a' })
    const before = store.signal('events').get()

    store.upsert('events', 2, { text: 'b' })
    expect(before).toEqual([{ itemKey: '1', slots: { text: 'a' } }])
    expect(store.signal('events').get()).not.toBe(before)
  })

  it('notifies subscribers on append, edit, and delete', () => {
    const store = new ListStore()
    const lengths: number[] = []
    subscribeSignal(store.signal('events'), (items) =>
      lengths.push(items.length),
    )

    store.upsert('events', 1, { text: 'a' })
    store.upsert('events', 2, { text: 'b' })
    store.upsert('events', 1, { text: 'edited' })
    store.delete('events', 2)
    expect(lengths).toEqual([1, 2, 2, 1])
  })
})
