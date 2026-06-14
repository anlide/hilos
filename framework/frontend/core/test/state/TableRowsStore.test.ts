import { describe, expect, it } from 'vitest'
import { TableRowsStore } from '../../src/state/TableRowsStore.js'
import { subscribeSignal } from '../../src/state/signal.js'

describe('TableRowsStore', () => {
  it('returns an empty table before the first upsert', () => {
    const store = new TableRowsStore()
    expect(store.signal('users').get()).toEqual([])
  })

  it('appends new rows at the end, preserving arrival order', () => {
    const store = new TableRowsStore()
    store.upsert('users', 1, { name: 'first' })
    store.upsert('users', 2, { name: 'second' })

    expect(store.signal('users').get()).toEqual([
      { rowKey: '1', slots: { name: 'first' } },
      { rowKey: '2', slots: { name: 'second' } },
    ])
  })

  it('replaces an existing row in place, keeping its position', () => {
    const store = new TableRowsStore()
    store.upsert('users', 1, { name: 'a' })
    store.upsert('users', 2, { name: 'b' })
    store.upsert('users', 1, { name: 'edited' })

    expect(store.signal('users').get()).toEqual([
      { rowKey: '1', slots: { name: 'edited' } },
      { rowKey: '2', slots: { name: 'b' } },
    ])
  })

  it('matches row keys stringified, so 1 and "1" are one row', () => {
    const store = new TableRowsStore()
    store.upsert('users', 1, { name: 'a' })
    store.upsert('users', '1', { name: 'b' })

    expect(store.signal('users').get()).toEqual([
      { rowKey: '1', slots: { name: 'b' } },
    ])
  })

  it('deletes a row and leaves the rest in order', () => {
    const store = new TableRowsStore()
    store.upsert('users', 1, { name: 'a' })
    store.upsert('users', 2, { name: 'b' })
    store.upsert('users', 3, { name: 'c' })
    store.delete('users', 2)

    expect(store.signal('users').get()).toEqual([
      { rowKey: '1', slots: { name: 'a' } },
      { rowKey: '3', slots: { name: 'c' } },
    ])
  })

  it('treats a delete of an absent row as a no-op', () => {
    const store = new TableRowsStore()
    store.upsert('users', 1, { name: 'a' })
    const before = store.signal('users').get()
    store.delete('users', 99)

    expect(store.signal('users').get()).toBe(before)
  })

  it('clears every row from a table (truncate)', () => {
    const store = new TableRowsStore()
    store.upsert('users', 1, { name: 'a' })
    store.upsert('users', 2, { name: 'b' })
    store.clear('users')

    expect(store.signal('users').get()).toEqual([])
  })

  it('upserts after a clear start from an empty table', () => {
    const store = new TableRowsStore()
    store.upsert('users', 1, { name: 'a' })
    store.clear('users')
    store.upsert('users', 9, { name: 'fresh' })

    expect(store.signal('users').get()).toEqual([
      { rowKey: '9', slots: { name: 'fresh' } },
    ])
  })

  it('treats a clear of an already-empty table as a no-op', () => {
    const store = new TableRowsStore()
    const before = store.signal('users').get()
    store.clear('users')

    expect(store.signal('users').get()).toBe(before)
  })

  it('keeps tables independent under different keys', () => {
    const store = new TableRowsStore()
    store.upsert('users', 1, { name: 'Ann' })
    store.upsert('bots', 1, { name: 'Bot' })

    expect(store.signal('users').get()).toEqual([
      { rowKey: '1', slots: { name: 'Ann' } },
    ])
    expect(store.signal('bots').get()).toEqual([
      { rowKey: '1', slots: { name: 'Bot' } },
    ])
  })

  it('replaces the array on change instead of mutating the previous one', () => {
    const store = new TableRowsStore()
    store.upsert('users', 1, { name: 'a' })
    const before = store.signal('users').get()

    store.upsert('users', 2, { name: 'b' })
    expect(before).toEqual([{ rowKey: '1', slots: { name: 'a' } }])
    expect(store.signal('users').get()).not.toBe(before)
  })

  it('notifies subscribers on append, edit, and delete', () => {
    const store = new TableRowsStore()
    const lengths: number[] = []
    subscribeSignal(store.signal('users'), (rows) => lengths.push(rows.length))

    store.upsert('users', 1, { name: 'a' })
    store.upsert('users', 2, { name: 'b' })
    store.upsert('users', 1, { name: 'edited' })
    store.delete('users', 2)
    expect(lengths).toEqual([1, 2, 2, 1])
  })
})
