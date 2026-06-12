import { describe, expect, it } from 'vitest'
import { EntityStore } from '../../src/state/EntityStore.js'
import { subscribeSignal } from '../../src/state/signal.js'

describe('EntityStore', () => {
  it('returns undefined before the first upsert, the snapshot after it', () => {
    const store = new EntityStore()
    const user = store.signal({ type: 'user', id: 7 })
    expect(user.get()).toBeUndefined()

    store.upsert({ type: 'user', id: 7 }, { name: 'Ann' })
    expect(user.get()).toEqual({
      type: 'user',
      id: '7',
      fields: { name: 'Ann' },
      revision: 1,
    })
  })

  it('merges fields: present overwrites, absent stays, explicit null clears', () => {
    const store = new EntityStore()
    store.upsert(
      { type: 'user', id: 1 },
      { name: 'Ann', email: 'a@x', age: 30 },
    )
    store.upsert({ type: 'user', id: 1 }, { name: 'Bea', email: null })

    expect(store.signal({ type: 'user', id: 1 }).get()).toEqual({
      type: 'user',
      id: '1',
      fields: { name: 'Bea', email: null, age: 30 },
      revision: 2,
    })
  })

  it('keeps one entity per (type, id), with ids matched stringified', () => {
    const store = new EntityStore()
    store.upsert({ type: 'user', id: 1 }, { name: 'Ann' })
    store.upsert({ type: 'user', id: '1' }, { age: 30 })

    const snapshot = store.signal({ type: 'user', id: 1 }).get()
    expect(snapshot?.fields).toEqual({ name: 'Ann', age: 30 })
    expect(store.signal({ type: 'user', id: '1' }).get()).toBe(snapshot)
  })

  it('does not collide entities of different types sharing an id', () => {
    const store = new EntityStore()
    store.upsert({ type: 'user', id: 1 }, { name: 'Ann' })
    store.upsert({ type: 'message', id: 1 }, { text: 'hi' })

    expect(store.signal({ type: 'user', id: 1 }).get()?.fields).toEqual({
      name: 'Ann',
    })
    expect(store.signal({ type: 'message', id: 1 }).get()?.fields).toEqual({
      text: 'hi',
    })
  })

  it('replaces the snapshot on upsert instead of mutating the previous one', () => {
    const store = new EntityStore()
    store.upsert({ type: 'user', id: 1 }, { name: 'Ann' })
    const before = store.signal({ type: 'user', id: 1 }).get()

    store.upsert({ type: 'user', id: 1 }, { name: 'Bea' })
    expect(before?.fields).toEqual({ name: 'Ann' })
    expect(store.signal({ type: 'user', id: 1 }).get()).not.toBe(before)
  })

  it('notifies subscribers when the entity appears and when it changes', () => {
    const store = new EntityStore()
    const seen: (string | undefined)[] = []
    subscribeSignal(store.signal({ type: 'user', id: 1 }), (snapshot) =>
      seen.push(snapshot?.fields['name'] as string | undefined),
    )

    store.upsert({ type: 'user', id: 1 }, { name: 'Ann' })
    store.upsert({ type: 'user', id: 1 }, { name: 'Bea' })
    expect(seen).toEqual(['Ann', 'Bea'])
  })
})
